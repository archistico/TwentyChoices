<?php

declare(strict_types=1);

namespace App\Verification\Application;

use App\Game\Application\OpenRound;
use App\Game\Application\StartPlay;
use App\Game\Application\SubmitChoice;
use App\Game\Domain\Exception\DomainRuleViolation;
use App\Game\Domain\Security\RoundSecretCipher;
use App\Game\Domain\ValueObject\RoundCommitment;
use App\Game\Domain\ValueObject\WinningPath;
use App\Player\Application\PlayerSessionRegistry;
use App\Shared\Security\SecureTokenGenerator;
use App\Shared\Time\Clock;
use Doctrine\DBAL\Connection;
use Symfony\Component\Uid\Ulid;
use Symfony\Component\Uid\Uuid;
use Throwable;

final readonly class WinningSettlementGateVerifier
{
    private const INITIAL_JACKPOT_CENTS = 1_000_000;
    private const STANDARD_JACKPOT_CONTRIBUTION_CENTS = 80;
    private const RESTART_CREDIT_CENTS = 100;

    public function __construct(
        private Connection $connection,
        private OpenRound $openRound,
        private PlayerSessionRegistry $sessions,
        private StartPlay $startPlay,
        private SubmitChoice $submitChoice,
        private SecureTokenGenerator $tokens,
        private RoundSecretCipher $secretCipher,
        private ReceiptQuery $receipts,
        private Clock $clock,
    ) {
    }

    /** @return array{status:string, checks:list<array{name:string,status:string,value:string,detail:string}>} */
    public function verify(): array
    {
        $checks = [];
        $this->connection->beginTransaction();

        try {
            $nextRoundId = $this->verifySuccessfulSettlement($checks);
            $this->verifyLateFaultRollsBackEntireSettlement($checks, $nextRoundId);
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
    private function verifySuccessfulSettlement(array &$checks): string
    {
        $round = $this->openRound->open();
        $winnerSession = $this->sessions->resolve(null);
        $interruptedSessionA = $this->sessions->resolve(null);
        $interruptedSessionB = $this->sessions->resolve(null);

        $winnerPlay = $this->startPlay->start($winnerSession->id);
        $interruptedPlayA = $this->startPlay->start($interruptedSessionA->id);
        $interruptedPlayB = $this->startPlay->start($interruptedSessionB->id);

        $secrets = $this->roundSecrets($round->id);
        $expectedFrozen = self::INITIAL_JACKPOT_CENTS + 3 * self::STANDARD_JACKPOT_CONTRIBUTION_CENTS;
        $before = $this->connection->fetchAssociative(<<<'SQL'
SELECT status, entry_contribution_cents, winner_play_id, frozen_jackpot_cents
FROM game_round
WHERE id = :id
SQL, ['id' => $round->id]);
        if ($before === false) {
            throw new DomainRuleViolation('Il round vincente non è disponibile prima del settlement M1.9.7.');
        }

        $checks[] = $this->check(
            'Pre-settlement accounting baseline',
            (string) $before['status'] === 'ACTIVE'
                && (int) $before['entry_contribution_cents'] === 240
                && $before['winner_play_id'] === null
                && $before['frozen_jackpot_cents'] === null
                && (int) $this->connection->fetchOne(
                    "SELECT COUNT(*) FROM ledger_entry WHERE round_id = :roundId AND entry_type = 'JACKPOT_CONTRIBUTION' AND amount_cents = 80",
                    ['roundId' => $round->id],
                ) === 3,
            sprintf('standard=3, contribution=%d, expected-frozen=%d', (int) $before['entry_contribution_cents'], $expectedFrozen),
            'Il jackpot da congelare deve derivare esclusivamente dal seed iniziale più 0,80 € per ogni partecipazione STANDARD contabilizzata nel round.',
        );

        $result = $this->completeWinningPath(
            $round->id,
            $winnerPlay->id,
            $winnerPlay->publicCode,
            $winnerSession->id,
            $secrets['path'],
        );

        $winner = $this->connection->fetchAssociative(<<<'SQL'
SELECT status, current_step, chosen_path_bits, completed_at
FROM play
WHERE id = :id
SQL, ['id' => $winnerPlay->id]);
        if ($winner === false) {
            throw new DomainRuleViolation('La giocata vincente non è disponibile dopo il settlement M1.9.7.');
        }

        $checks[] = $this->check(
            'Winning path completion and first-winner claim',
            $result->outcome === 'WON'
                && $result->completed
                && (string) $winner['status'] === 'COMPLETED_WON'
                && (int) $winner['current_step'] === 20
                && (string) $winner['chosen_path_bits'] === $secrets['path']
                && $winner['completed_at'] !== null,
            sprintf('outcome=%s, step=%d, path-match=%s', $result->outcome ?? 'null', (int) $winner['current_step'], (string) $winner['chosen_path_bits'] === $secrets['path'] ? 'yes' : 'no'),
            'La ventesima scelta corretta deve essere registrata e la stessa play deve diventare l’unico vincitore validato, senza processi successivi separati.',
        );

        $settled = $this->connection->fetchAssociative(<<<'SQL'
SELECT
     status
    ,public_code
    ,question_set_hash
    ,secret_commitment
    ,winner_play_id
    ,initial_jackpot_cents
    ,entry_contribution_cents
    ,frozen_jackpot_cents
    ,won_at
    ,settled_at
    ,revealed_winning_path
    ,revealed_secret_nonce_hex
    ,verification_published_at
FROM game_round
WHERE id = :id
SQL, ['id' => $round->id]);
        if ($settled === false) {
            throw new DomainRuleViolation('Il vecchio round non è disponibile dopo il settlement M1.9.7.');
        }

        $commitmentValid = RoundCommitment::fromHash((string) $settled['secret_commitment'])->verifies(
            (string) $settled['public_code'],
            (string) $settled['question_set_hash'],
            WinningPath::fromBitString((string) $settled['revealed_winning_path']),
            hex2bin((string) $settled['revealed_secret_nonce_hex']) ?: '',
        );

        $checks[] = $this->check(
            'Atomic round freeze, reveal and settlement',
            (string) $settled['status'] === 'SETTLED'
                && (string) $settled['winner_play_id'] === $winnerPlay->id
                && (int) $settled['initial_jackpot_cents'] === self::INITIAL_JACKPOT_CENTS
                && (int) $settled['entry_contribution_cents'] === 240
                && (int) $settled['frozen_jackpot_cents'] === $expectedFrozen
                && $settled['won_at'] !== null
                && $settled['settled_at'] !== null
                && (string) $settled['revealed_winning_path'] === $secrets['path']
                && (string) $settled['revealed_secret_nonce_hex'] === bin2hex($secrets['nonce'])
                && $settled['verification_published_at'] !== null
                && $commitmentValid
                && $result->frozenJackpotCents === $expectedFrozen,
            sprintf('status=%s, frozen=%d, winner=%s, commitment=%s', (string) $settled['status'], (int) $settled['frozen_jackpot_cents'], (string) $settled['winner_play_id'], $commitmentValid ? 'valid' : 'invalid'),
            'Winner claim, freeze del jackpot, reveal del percorso/nonce e SETTLED devono risultare coerenti nello stesso stato persistito; il commitment rivelato deve ricalcolarsi esattamente.',
        );

        $payout = $this->connection->fetchAssociative(<<<'SQL'
SELECT COUNT(*) AS rows_count, COALESCE(SUM(amount_cents), 0) AS amount_cents, COUNT(DISTINCT play_id) AS plays
FROM ledger_entry
WHERE round_id = :roundId
  AND entry_type = 'JACKPOT_PAYOUT'
SQL, ['roundId' => $round->id]);
        if ($payout === false) {
            throw new DomainRuleViolation('Il payout del round non è disponibile.');
        }

        $checks[] = $this->check(
            'Unique jackpot payout reconciliation',
            (int) $payout['rows_count'] === 1
                && (int) $payout['amount_cents'] === $expectedFrozen
                && (int) $payout['plays'] === 1
                && (string) $this->connection->fetchOne(
                    "SELECT play_id FROM ledger_entry WHERE round_id = :roundId AND entry_type = 'JACKPOT_PAYOUT' LIMIT 1",
                    ['roundId' => $round->id],
                ) === $winnerPlay->id,
            sprintf('rows=%d, payout=%d, frozen=%d', (int) $payout['rows_count'], (int) $payout['amount_cents'], $expectedFrozen),
            'Deve esistere un solo JACKPOT_PAYOUT, attribuito alla play vincente e di importo esattamente uguale al jackpot congelato.',
        );

        $interruptedIds = [$interruptedPlayA->id, $interruptedPlayB->id];
        $credited = true;
        foreach ($interruptedIds as $interruptedId) {
            $credited = $credited
                && (string) $this->connection->fetchOne('SELECT status FROM play WHERE id = :id', ['id' => $interruptedId]) === 'CREDITED'
                && (int) $this->connection->fetchOne(
                    "SELECT COUNT(*) FROM play_credit WHERE source_play_id = :id AND source_round_id = :roundId AND status = 'AVAILABLE'",
                    ['id' => $interruptedId, 'roundId' => $round->id],
                ) === 1
                && (int) $this->connection->fetchOne(
                    "SELECT COUNT(*) FROM ledger_entry WHERE play_id = :id AND round_id = :roundId AND entry_type = 'RESTART_CREDIT_ISSUED' AND amount_cents = :amount",
                    ['id' => $interruptedId, 'roundId' => $round->id, 'amount' => self::RESTART_CREDIT_CENTS],
                ) === 1;
        }

        $checks[] = $this->check(
            'Interrupted plays and restart credits',
            $credited
                && $result->interruptedPlayCount === 2
                && (int) $this->connection->fetchOne(
                    "SELECT COUNT(*) FROM play WHERE round_id = :roundId AND status IN ('CREATED', 'IN_PROGRESS', 'INTERRUPTED')",
                    ['roundId' => $round->id],
                ) === 0
                && (int) $this->connection->fetchOne('SELECT COUNT(*) FROM play_credit WHERE source_round_id = :roundId', ['roundId' => $round->id]) === 2,
            sprintf('interrupted=%d, credits=%d', $result->interruptedPlayCount, (int) $this->connection->fetchOne('SELECT COUNT(*) FROM play_credit WHERE source_round_id = :roundId', ['roundId' => $round->id])),
            'Ogni altra play aperta del round deve essere interrotta, diventare CREDITED e ricevere esattamente un credito di ripartenza disponibile e tracciato nel ledger.',
        );

        $next = $this->connection->fetchAssociative(<<<'SQL'
SELECT id, public_code, status, initial_jackpot_cents, entry_contribution_cents, winner_play_id, frozen_jackpot_cents
FROM game_round
WHERE status = 'ACTIVE'
LIMIT 1
SQL);
        if ($next === false) {
            throw new DomainRuleViolation('Il nuovo round ACTIVE non è stato creato dal settlement.');
        }

        $checks[] = $this->check(
            'Next round atomically opened from bank seed',
            (string) $next['id'] !== $round->id
                && (string) $next['public_code'] === (string) $result->nextRoundPublicCode
                && (string) $next['status'] === 'ACTIVE'
                && (int) $next['initial_jackpot_cents'] === self::INITIAL_JACKPOT_CENTS
                && (int) $next['entry_contribution_cents'] === 0
                && $next['winner_play_id'] === null
                && $next['frozen_jackpot_cents'] === null
                && (int) $this->connection->fetchOne('SELECT COUNT(*) FROM round_question WHERE round_id = :id', ['id' => $next['id']]) === 20
                && (int) $this->connection->fetchOne(
                    "SELECT COUNT(*) FROM ledger_entry WHERE round_id = :id AND entry_type = 'BANK_SEED' AND amount_cents = :amount",
                    ['id' => $next['id'], 'amount' => self::INITIAL_JACKPOT_CENTS],
                ) === 1
                && (int) $this->connection->fetchOne("SELECT COUNT(*) FROM game_round WHERE status = 'ACTIVE'") === 1,
            sprintf('%s, seed=%d, contribution=%d', (string) $next['public_code'], (int) $next['initial_jackpot_cents'], (int) $next['entry_contribution_cents']),
            'Nella stessa unità atomica deve nascere un solo nuovo round ACTIVE completo, con 20 snapshot e nuovo BANK_SEED da 10.000,00 €, senza ereditare contribuzioni del round precedente.',
        );

        $receiptRows = $this->connection->fetchAllAssociative(
            'SELECT public_code, play_id, outcome FROM play_receipt WHERE round_id = :roundId ORDER BY play_id',
            ['roundId' => $round->id],
        );
        $receiptsValid = count($receiptRows) === 3;
        $outcomes = [];
        foreach ($receiptRows as $receiptRow) {
            $receipt = $this->receipts->byVerificationCode((string) $receiptRow['public_code']);
            $outcomes[] = (string) $receiptRow['outcome'];
            $receiptsValid = $receiptsValid
                && $receipt !== null
                && $receipt->receiptIntegrityValid
                && $receipt->roundStatus === 'SETTLED'
                && $receipt->roundVerificationAvailable
                && $receipt->roundCommitmentValid
                && $receipt->outcomeConsistent
                && $receipt->winningPath === $secrets['path']
                && $receipt->nonceHex === bin2hex($secrets['nonce'])
                && $receipt->frozenJackpotCents === $expectedFrozen;
        }
        sort($outcomes);

        $checks[] = $this->check(
            'Terminal receipts published consistently',
            $receiptsValid && $outcomes === ['INTERRUPTED', 'INTERRUPTED', 'WON'],
            sprintf('receipts=%d, outcomes=%s', count($receiptRows), implode(',', $outcomes)),
            'La stessa transazione deve lasciare una ricevuta immutabile per vincitore e giocate interrotte; dopo SETTLED tutte devono verificare lo stesso reveal e il commitment del round.',
        );

        return (string) $next['id'];
    }

    /** @param list<array{name:string,status:string,value:string,detail:string}> $checks */
    private function verifyLateFaultRollsBackEntireSettlement(array &$checks, string $roundId): void
    {
        $winnerSession = $this->sessions->resolve(null);
        $interruptedSession = $this->sessions->resolve(null);
        $winnerPlay = $this->startPlay->start($winnerSession->id);
        $interruptedPlay = $this->startPlay->start($interruptedSession->id);
        $secrets = $this->roundSecrets($roundId);

        for ($index = 0; $index < 19; ++$index) {
            $challenge = $this->seedAvailableStep($winnerPlay->id, $roundId, $index + 1);
            $this->submitChoice->submit(
                $winnerPlay->publicCode,
                $winnerSession->id,
                $challenge,
                $secrets['path'][$index] === '0' ? 'A' : 'B',
                (string) Uuid::v7(),
                2_000,
            );
        }

        $finalChallenge = $this->seedAvailableStep($winnerPlay->id, $roundId, 20);
        $before = [
            'rounds' => (int) $this->connection->fetchOne('SELECT COUNT(*) FROM game_round'),
            'ledger' => (int) $this->connection->fetchOne('SELECT COUNT(*) FROM ledger_entry WHERE round_id = :id', ['id' => $roundId]),
            'audit' => (int) $this->connection->fetchOne('SELECT COUNT(*) FROM audit_event WHERE round_id = :id', ['id' => $roundId]),
            'receipts' => (int) $this->connection->fetchOne('SELECT COUNT(*) FROM play_receipt WHERE round_id = :id', ['id' => $roundId]),
            'credits' => (int) $this->connection->fetchOne('SELECT COUNT(*) FROM play_credit WHERE source_round_id = :id', ['id' => $roundId]),
            'contribution' => (int) $this->connection->fetchOne('SELECT entry_contribution_cents FROM game_round WHERE id = :id', ['id' => $roundId]),
        ];

        $this->connection->executeStatement(<<<'SQL'
CREATE TRIGGER trg_m197_fault_before_settled
BEFORE UPDATE OF status ON game_round
WHEN NEW.status = 'SETTLED'
BEGIN
    SELECT RAISE(ABORT, 'injected m1.9.7 late settlement failure');
END
SQL);

        $failure = null;
        try {
            $this->submitChoice->submit(
                $winnerPlay->publicCode,
                $winnerSession->id,
                $finalChallenge,
                $secrets['path'][19] === '0' ? 'A' : 'B',
                (string) Uuid::v7(),
                2_000,
            );
        } catch (Throwable $exception) {
            $failure = $exception;
        } finally {
            $this->connection->executeStatement('DROP TRIGGER IF EXISTS trg_m197_fault_before_settled');
        }

        $round = $this->connection->fetchAssociative(<<<'SQL'
SELECT
     status
    ,winner_play_id
    ,frozen_jackpot_cents
    ,won_at
    ,settled_at
    ,revealed_winning_path
    ,revealed_secret_nonce_hex
    ,verification_published_at
    ,entry_contribution_cents
FROM game_round
WHERE id = :id
SQL, ['id' => $roundId]);
        $winner = $this->connection->fetchAssociative(
            'SELECT status, current_step, chosen_path_bits, completed_at FROM play WHERE id = :id',
            ['id' => $winnerPlay->id],
        );
        $finalStep = $this->connection->fetchAssociative(<<<'SQL'
SELECT answered_at, selected_option, request_id
FROM play_step
WHERE play_id = :playId AND step_number = 20
SQL, ['playId' => $winnerPlay->id]);

        if ($round === false || $winner === false || $finalStep === false) {
            throw new DomainRuleViolation('Lo stato post-fault M1.9.7 non è leggibile.');
        }

        $rollbackComplete = $failure !== null
            && str_contains($failure->getMessage(), 'injected m1.9.7 late settlement failure')
            && (string) $round['status'] === 'ACTIVE'
            && $round['winner_play_id'] === null
            && $round['frozen_jackpot_cents'] === null
            && $round['won_at'] === null
            && $round['settled_at'] === null
            && $round['revealed_winning_path'] === null
            && $round['revealed_secret_nonce_hex'] === null
            && $round['verification_published_at'] === null
            && (int) $round['entry_contribution_cents'] === $before['contribution']
            && (string) $winner['status'] === 'IN_PROGRESS'
            && (int) $winner['current_step'] === 19
            && strlen((string) $winner['chosen_path_bits']) === 19
            && $winner['completed_at'] === null
            && $finalStep['answered_at'] === null
            && $finalStep['selected_option'] === null
            && $finalStep['request_id'] === null
            && (string) $this->connection->fetchOne('SELECT status FROM play WHERE id = :id', ['id' => $interruptedPlay->id]) === 'IN_PROGRESS'
            && (int) $this->connection->fetchOne("SELECT COUNT(*) FROM ledger_entry WHERE round_id = :id AND entry_type = 'JACKPOT_PAYOUT'", ['id' => $roundId]) === 0
            && (int) $this->connection->fetchOne('SELECT COUNT(*) FROM play_credit WHERE source_round_id = :id', ['id' => $roundId]) === $before['credits']
            && (int) $this->connection->fetchOne('SELECT COUNT(*) FROM play_receipt WHERE round_id = :id', ['id' => $roundId]) === $before['receipts']
            && (int) $this->connection->fetchOne('SELECT COUNT(*) FROM ledger_entry WHERE round_id = :id', ['id' => $roundId]) === $before['ledger']
            && (int) $this->connection->fetchOne('SELECT COUNT(*) FROM audit_event WHERE round_id = :id', ['id' => $roundId]) === $before['audit']
            && (int) $this->connection->fetchOne('SELECT COUNT(*) FROM game_round') === $before['rounds']
            && (int) $this->connection->fetchOne("SELECT COUNT(*) FROM game_round WHERE status = 'ACTIVE'") === 1;

        $checks[] = $this->check(
            'Late settlement fault rolls back every intermediate effect',
            $rollbackComplete,
            sprintf('failed=%s, round=%s, winner-step=%d, rounds=%d→%d, ledger=%d→%d, receipts=%d→%d, credits=%d→%d',
                $failure !== null ? 'yes' : 'no',
                (string) $round['status'],
                (int) $winner['current_step'],
                $before['rounds'],
                (int) $this->connection->fetchOne('SELECT COUNT(*) FROM game_round'),
                $before['ledger'],
                (int) $this->connection->fetchOne('SELECT COUNT(*) FROM ledger_entry WHERE round_id = :id', ['id' => $roundId]),
                $before['receipts'],
                (int) $this->connection->fetchOne('SELECT COUNT(*) FROM play_receipt WHERE round_id = :id', ['id' => $roundId]),
                $before['credits'],
                (int) $this->connection->fetchOne('SELECT COUNT(*) FROM play_credit WHERE source_round_id = :id', ['id' => $roundId]),
            ),
            'Un errore iniettato dopo claim, payout, crediti, ricevute e apertura del round successivo ma prima di SETTLED deve annullare anche la scelta 20 e lasciare persistito soltanto lo stato pre-settlement: nessun mezzo settlement osservabile.',
        );
    }

    private function completeWinningPath(
        string $roundId,
        string $playId,
        string $playPublicCode,
        string $sessionId,
        string $winningPath,
    ): \App\Game\Application\ChoiceSubmissionResult {
        $result = null;

        for ($index = 0; $index < 20; ++$index) {
            $challenge = $this->seedAvailableStep($playId, $roundId, $index + 1);
            $result = $this->submitChoice->submit(
                $playPublicCode,
                $sessionId,
                $challenge,
                $winningPath[$index] === '0' ? 'A' : 'B',
                (string) Uuid::v7(),
                2_000,
            );
        }

        if ($result === null) {
            throw new DomainRuleViolation('Il percorso vincente M1.9.7 non ha prodotto un risultato.');
        }

        return $result;
    }

    private function seedAvailableStep(string $playId, string $roundId, int $stepNumber): string
    {
        $questionId = (string) $this->connection->fetchOne(
            'SELECT id FROM round_question WHERE round_id = :roundId AND step_number = :stepNumber',
            ['roundId' => $roundId, 'stepNumber' => $stepNumber],
        );
        if ($questionId === '') {
            throw new DomainRuleViolation(sprintf('La domanda %d non è disponibile per il gate M1.9.7.', $stepNumber));
        }

        $rawChallenge = $this->tokens->generate();
        $now = $this->clock->now();
        $shownAt = $now->modify('-3 seconds')->format('Y-m-d H:i:s.u');
        $availableAt = $now->modify('-1 second')->format('Y-m-d H:i:s.u');

        $this->connection->insert('play_step', [
            'id' => (string) new Ulid(),
            'play_id' => $playId,
            'round_question_id' => $questionId,
            'step_number' => $stepNumber,
            'option_a_is_left' => 1,
            'challenge_token_hash' => $this->tokens->hash($rawChallenge),
            'shown_at' => $shownAt,
            'available_at' => $availableAt,
            'answered_at' => null,
            'selected_option' => null,
            'request_id' => null,
            'client_elapsed_ms' => null,
            'created_at' => $shownAt,
        ]);

        return $rawChallenge;
    }

    /** @return array{path:string,nonce:string} */
    private function roundSecrets(string $roundId): array
    {
        $row = $this->connection->fetchAssociative(<<<'SQL'
SELECT encrypted_winning_path, encrypted_secret_nonce
FROM game_round
WHERE id = :id
SQL, ['id' => $roundId]);
        if ($row === false) {
            throw new DomainRuleViolation('Il materiale crittografico del round non è disponibile per il gate M1.9.7.');
        }

        return [
            'path' => $this->secretCipher->decrypt(self::blobToString($row['encrypted_winning_path']), OpenRound::pathContext($roundId)),
            'nonce' => $this->secretCipher->decrypt(self::blobToString($row['encrypted_secret_nonce']), OpenRound::nonceContext($roundId)),
        ];
    }

    private static function blobToString(mixed $value): string
    {
        if (is_resource($value)) {
            $contents = stream_get_contents($value);

            return $contents === false ? '' : $contents;
        }

        return (string) $value;
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
