<?php

declare(strict_types=1);

namespace App\Verification\Application;

use App\Game\Application\OpenRound;
use App\Game\Application\StartPlay;
use App\Player\Application\PlayerSessionIdentity;
use App\Player\Application\PlayerSessionRegistry;
use Doctrine\DBAL\Connection;
use Symfony\Component\Uid\Ulid;
use Symfony\Component\Uid\Uuid;
use Throwable;

final readonly class PlayStartAccountingGateVerifier
{
    public function __construct(
        private Connection $connection,
        private OpenRound $openRound,
        private PlayerSessionRegistry $sessions,
        private StartPlay $startPlay,
    ) {
    }

    /** @return array{status:string, checks:list<array{name:string,status:string,value:string,detail:string}>} */
    public function verify(): array
    {
        $checks = [];
        $this->connection->beginTransaction();

        try {
            $this->verifyAccountingSchemaHardening($checks);

            $round = $this->openRound->open();
            $firstSession = $this->sessions->resolve(null);
            $secondSession = $this->sessions->resolve(null);

            $this->verifyAnonymousSessions($checks, $firstSession, $secondSession);

            $first = $this->startPlay->start($firstSession->id);
            $roundContributionAfterFirst = $this->roundContribution($round->id);
            $firstLedgerCount = $this->ledgerCount($first->id);
            $resumed = $this->startPlay->start($firstSession->id);

            $this->verifyFirstPlayAndIdempotency(
                $checks,
                $round->id,
                $firstSession->id,
                $first,
                $resumed,
                $roundContributionAfterFirst,
                $firstLedgerCount,
            );
            $this->verifyStandardAccounting($checks, $first->id, $round->id, 'first participation');

            $second = $this->startPlay->start($secondSession->id);
            $this->verifyPublicCodesAndParticipation($checks, $first->publicCode, $first->participationNumber, $second->publicCode, $second->participationNumber);
            $this->verifyStandardAccounting($checks, $second->id, $round->id, 'second participation');
            $this->verifyRoundContribution($checks, $round->id, 160);
            $this->verifyDuplicateLedgerBlocked($checks, $first->id, $round->id);
            $this->verifyAccountingFailureRollsBackAtomically($checks, $round->id);
        } catch (Throwable $exception) {
            $checks[] = $this->check('Verification scenario', false, $exception::class, $exception->getMessage());
        } finally {
            if ($this->connection->isTransactionActive()) {
                $this->connection->rollBack();
            }
        }

        return [
            'status' => in_array('error', array_column($checks, 'status'), true) ? 'error' : 'ok',
            'checks' => $checks,
        ];
    }

    /** @param list<array{name:string,status:string,value:string,detail:string}> $checks */
    private function verifyAccountingSchemaHardening(array &$checks): void
    {
        $required = [
            'index' => [
                'uniq_standard_player_entry_per_play',
                'uniq_standard_jackpot_contribution_per_play',
                'uniq_standard_organizer_share_per_play',
            ],
            'trigger' => [
                'trg_ledger_standard_entry_play_binding',
            ],
        ];
        $missing = [];

        foreach ($required as $type => $names) {
            foreach ($names as $name) {
                $exists = (int) $this->connection->fetchOne(
                    'SELECT COUNT(*) FROM sqlite_master WHERE type = :type AND name = :name',
                    ['type' => $type, 'name' => $name],
                ) === 1;

                if (!$exists) {
                    $missing[] = $name;
                }
            }
        }

        $checks[] = $this->check(
            'Accounting schema hardening',
            [] === $missing,
            [] === $missing ? '3 unique indexes + 1 binding trigger present' : 'missing: '.implode(', ', $missing),
            'Il database di test deve avere applicato gli oggetti SQLite che rendono univoche le tre componenti STANDARD prima di eseguire il gate funzionale.',
        );
    }

    /** @param list<array{name:string,status:string,value:string,detail:string}> $checks */
    private function verifyAnonymousSessions(array &$checks, PlayerSessionIdentity $first, PlayerSessionIdentity $second): void
    {
        $firstHash = (string) $this->connection->fetchOne('SELECT public_token_hash FROM player_session WHERE id = :id', ['id' => $first->id]);
        $secondHash = (string) $this->connection->fetchOne('SELECT public_token_hash FROM player_session WHERE id = :id', ['id' => $second->id]);
        $wellFormed = preg_match('/^[A-Za-z0-9_-]{43}$/D', $first->rawToken) === 1
            && preg_match('/^[A-Za-z0-9_-]{43}$/D', $second->rawToken) === 1;
        $hashOnly = hash_equals(hash('sha256', $first->rawToken), $firstHash)
            && hash_equals(hash('sha256', $second->rawToken), $secondHash)
            && !hash_equals($first->rawToken, $firstHash)
            && !hash_equals($second->rawToken, $secondHash);
        $distinct = !hash_equals($first->rawToken, $second->rawToken)
            && !hash_equals($firstHash, $secondHash)
            && $first->id !== $second->id;
        $auditPayload = implode("\n", array_map(
            static fn (mixed $value): string => (string) $value,
            $this->connection->fetchFirstColumn("SELECT payload_json FROM audit_event WHERE event_type = 'PLAYER_SESSION_CREATED'"),
        ));
        $auditClean = !str_contains($auditPayload, $first->rawToken) && !str_contains($auditPayload, $second->rawToken);

        $checks[] = $this->check(
            'Anonymous session tokens',
            $first->newlyCreated && $second->newlyCreated && $wellFormed && $hashOnly && $distinct && $auditClean,
            sprintf('tokens=%s, persisted=%s, distinct=%s, audit=%s', $wellFormed ? '256-bit URL-safe' : 'invalid', $hashOnly ? 'SHA-256 only' : 'raw/mismatch', $distinct ? 'yes' : 'no', $auditClean ? 'clean' : 'raw leak'),
            'Le sessioni anonime devono usare token casuali distinti; nel database deve essere persistito solo SHA-256 del token, mai il valore raw.',
        );
    }

    /** @param list<array{name:string,status:string,value:string,detail:string}> $checks */
    private function verifyFirstPlayAndIdempotency(
        array &$checks,
        string $roundId,
        string $sessionId,
        \App\Game\Application\StartedPlay $first,
        \App\Game\Application\StartedPlay $resumed,
        int $roundContributionAfterFirst,
        int $firstLedgerCount,
    ): void {
        $row = $this->connection->fetchAssociative(<<<'SQL'
SELECT status, player_session_id, entry_kind, current_step, chosen_path_bits
FROM play
WHERE id = :id
SQL, ['id' => $first->id]);
        $openCount = (int) $this->connection->fetchOne(<<<'SQL'
SELECT COUNT(*)
FROM play
WHERE round_id = :roundId
  AND player_session_id = :sessionId
  AND status IN ('CREATED', 'IN_PROGRESS')
SQL, ['roundId' => $roundId, 'sessionId' => $sessionId]);

        $unchangedAfterReplay = $roundContributionAfterFirst === $this->roundContribution($roundId)
            && $firstLedgerCount === $this->ledgerCount($first->id);
        $ok = $row !== false
            && $row['status'] === 'IN_PROGRESS'
            && $row['player_session_id'] === $sessionId
            && $row['entry_kind'] === 'STANDARD'
            && (int) $row['current_step'] === 0
            && $row['chosen_path_bits'] === ''
            && !$first->resumed
            && $resumed->resumed
            && $first->id === $resumed->id
            && $first->publicCode === $resumed->publicCode
            && $openCount === 1
            && $unchangedAfterReplay;

        $checks[] = $this->check(
            'Start idempotency',
            $ok,
            sprintf('open=%d, resumed=%s, ledger=%d, contribution=%d', $openCount, $resumed->resumed ? 'same play' : 'new play', $this->ledgerCount($first->id), $this->roundContribution($roundId)),
            'Due avvii della stessa sessione nello stesso round devono restituire la stessa giocata senza creare una seconda quota o incrementare di nuovo il jackpot.',
        );
    }

    /** @param list<array{name:string,status:string,value:string,detail:string}> $checks */
    private function verifyPublicCodesAndParticipation(
        array &$checks,
        string $firstCode,
        int $firstParticipation,
        string $secondCode,
        int $secondParticipation,
    ): void {
        $codesValid = preg_match('/^G-[A-F0-9]{24}$/D', $firstCode) === 1
            && preg_match('/^G-[A-F0-9]{24}$/D', $secondCode) === 1
            && $firstCode !== $secondCode;
        $participationValid = $firstParticipation >= 1 && $secondParticipation === $firstParticipation + 1;

        $checks[] = $this->check(
            'Play public codes and participation',
            $codesValid && $participationValid,
            sprintf('%s / #%d; %s / #%d', $firstCode, $firstParticipation, $secondCode, $secondParticipation),
            'Il numero di partecipazione è sequenziale nel round, mentre il codice pubblico della giocata deve essere casuale, opaco e distinto dal progressivo.',
        );
    }

    /** @param list<array{name:string,status:string,value:string,detail:string}> $checks */
    private function verifyStandardAccounting(array &$checks, string $playId, string $roundId, string $label): void
    {
        $rows = $this->connection->fetchAllAssociative(<<<'SQL'
SELECT entry_type, amount_cents, correlation_id
FROM ledger_entry
WHERE play_id = :playId
  AND entry_type IN ('PLAYER_ENTRY', 'JACKPOT_CONTRIBUTION', 'ORGANIZER_SHARE')
ORDER BY entry_type
SQL, ['playId' => $playId]);

        $amounts = [];
        $correlations = [];
        foreach ($rows as $row) {
            $amounts[(string) $row['entry_type']] = (int) $row['amount_cents'];
            $correlations[(string) $row['correlation_id']] = true;
        }
        $ok = count($rows) === 3
            && $amounts === [
                'JACKPOT_CONTRIBUTION' => 80,
                'ORGANIZER_SHARE' => 20,
                'PLAYER_ENTRY' => 100,
            ]
            && count($correlations) === 1
            && (int) $this->connection->fetchOne('SELECT COUNT(*) FROM play WHERE id = :id AND round_id = :roundId AND entry_kind = :kind', [
                'id' => $playId,
                'roundId' => $roundId,
                'kind' => 'STANDARD',
            ]) === 1;

        $checks[] = $this->check(
            'Standard accounting: '.$label,
            $ok,
            sprintf('rows=%d, entry=%d, jackpot=%d, organizer=%d, correlations=%d', count($rows), $amounts['PLAYER_ENTRY'] ?? -1, $amounts['JACKPOT_CONTRIBUTION'] ?? -1, $amounts['ORGANIZER_SHARE'] ?? -1, count($correlations)),
            'Ogni partecipazione STANDARD deve produrre una sola contabilizzazione correlata: 100 centesimi di ingresso, 80 al jackpot e 20 all’organizzazione.',
        );
    }

    /** @param list<array{name:string,status:string,value:string,detail:string}> $checks */
    private function verifyRoundContribution(array &$checks, string $roundId, int $expected): void
    {
        $actual = $this->roundContribution($roundId);
        $ledger = (int) $this->connection->fetchOne("SELECT COALESCE(SUM(amount_cents), 0) FROM ledger_entry WHERE round_id = :roundId AND entry_type = 'JACKPOT_CONTRIBUTION'", ['roundId' => $roundId]);

        $checks[] = $this->check(
            'Round jackpot contribution reconciliation',
            $actual === $expected && $ledger === $expected,
            sprintf('round=%d, ledger=%d, expected=%d', $actual, $ledger, $expected),
            'Il contatore del jackpot del round deve riconciliarsi esattamente con la somma delle sole contribuzioni da 80 centesimi persistite nel ledger.',
        );
    }

    /** @param list<array{name:string,status:string,value:string,detail:string}> $checks */
    private function verifyDuplicateLedgerBlocked(array &$checks, string $playId, string $roundId): void
    {
        $blocked = [];
        foreach ([
            'PLAYER_ENTRY' => 100,
            'JACKPOT_CONTRIBUTION' => 80,
            'ORGANIZER_SHARE' => 20,
        ] as $entryType => $amount) {
            $blocked[$entryType] = false;
            try {
                $this->connection->insert('ledger_entry', [
                    'id' => (string) new Ulid(),
                    'round_id' => $roundId,
                    'play_id' => $playId,
                    'entry_type' => $entryType,
                    'amount_cents' => $amount,
                    'correlation_id' => (string) Uuid::v7(),
                    'created_at' => '2026-07-19 12:00:00.000000',
                ]);
            } catch (Throwable) {
                $blocked[$entryType] = true;
            }
        }

        $countsOk = true;
        foreach (array_keys($blocked) as $entryType) {
            $countsOk = $countsOk && (int) $this->connection->fetchOne(
                'SELECT COUNT(*) FROM ledger_entry WHERE play_id = :playId AND entry_type = :entryType',
                ['playId' => $playId, 'entryType' => $entryType],
            ) === 1;
        }
        $allBlocked = !in_array(false, $blocked, true);

        $checks[] = $this->check(
            'Duplicate accounting protection',
            $allBlocked && $countsOk,
            sprintf('entry=%s, jackpot=%s, organizer=%s', $blocked['PLAYER_ENTRY'] ? 'blocked' : 'accepted', $blocked['JACKPOT_CONTRIBUTION'] ? 'blocked' : 'accepted', $blocked['ORGANIZER_SHARE'] ? 'blocked' : 'accepted'),
            'Lo schema deve impedire una seconda riga di qualsiasi componente 100/80/20 sulla stessa giocata, anche con un correlation_id differente.',
        );
    }

    /** @param list<array{name:string,status:string,value:string,detail:string}> $checks */
    private function verifyAccountingFailureRollsBackAtomically(array &$checks, string $roundId): void
    {
        $session = $this->sessions->resolve(null);
        $before = [
            'plays' => (int) $this->connection->fetchOne('SELECT COUNT(*) FROM play WHERE round_id = :roundId', ['roundId' => $roundId]),
            'ledger' => (int) $this->connection->fetchOne('SELECT COUNT(*) FROM ledger_entry WHERE round_id = :roundId', ['roundId' => $roundId]),
            'contribution' => $this->roundContribution($roundId),
        ];
        $failed = false;

        $this->connection->executeStatement(<<<'SQL'
CREATE TEMP TRIGGER gate_m194_abort_organizer_share
BEFORE INSERT ON ledger_entry
WHEN NEW.entry_type = 'ORGANIZER_SHARE'
BEGIN
    SELECT RAISE(ABORT, 'M1.9.4 injected accounting failure');
END
SQL);
        try {
            $this->startPlay->start($session->id);
        } catch (Throwable) {
            $failed = true;
        } finally {
            $this->connection->executeStatement('DROP TRIGGER IF EXISTS gate_m194_abort_organizer_share');
        }

        $after = [
            'plays' => (int) $this->connection->fetchOne('SELECT COUNT(*) FROM play WHERE round_id = :roundId', ['roundId' => $roundId]),
            'ledger' => (int) $this->connection->fetchOne('SELECT COUNT(*) FROM ledger_entry WHERE round_id = :roundId', ['roundId' => $roundId]),
            'contribution' => $this->roundContribution($roundId),
        ];

        $checks[] = $this->check(
            'Atomic accounting rollback',
            $failed && $before === $after,
            sprintf('failed=%s, plays=%d→%d, ledger=%d→%d, contribution=%d→%d', $failed ? 'yes' : 'no', $before['plays'], $after['plays'], $before['ledger'], $after['ledger'], $before['contribution'], $after['contribution']),
            'Un errore a metà della contabilizzazione deve rollbackare nello stesso commit la nuova play, tutte le righe ledger e l’incremento del jackpot.',
        );
    }

    private function roundContribution(string $roundId): int
    {
        return (int) $this->connection->fetchOne('SELECT entry_contribution_cents FROM game_round WHERE id = :id', ['id' => $roundId]);
    }

    private function ledgerCount(string $playId): int
    {
        return (int) $this->connection->fetchOne('SELECT COUNT(*) FROM ledger_entry WHERE play_id = :id', ['id' => $playId]);
    }

    /** @return array{name:string,status:string,value:string,detail:string} */
    private function check(string $name, bool $ok, string $value, string $detail): array
    {
        return [
            'name' => $name,
            'status' => $ok ? 'ok' : 'error',
            'value' => $value,
            'detail' => $detail,
        ];
    }
}
