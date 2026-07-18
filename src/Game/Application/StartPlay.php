<?php

declare(strict_types=1);

namespace App\Game\Application;

use App\Audit\Application\AuditLogger;
use App\Game\Domain\Exception\DomainRuleViolation;
use App\Shared\Time\Clock;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Symfony\Component\Uid\Ulid;
use Symfony\Component\Uid\Uuid;
use Throwable;

final readonly class StartPlay
{
    private const ENTRY_CENTS = 100;
    private const JACKPOT_CONTRIBUTION_CENTS = 80;
    private const ORGANIZER_SHARE_CENTS = 20;

    public function __construct(
        private Connection $connection,
        private Clock $clock,
        private AuditLogger $audit,
    ) {
    }

    public function start(string $playerSessionId): StartedPlay
    {
        $this->connection->beginTransaction();
        try {
            $round = $this->connection->fetchAssociative(<<<'SQL'
SELECT
     id
    ,public_code
    ,initial_jackpot_cents
    ,entry_contribution_cents
FROM game_round
WHERE status = 'ACTIVE'
LIMIT 1
SQL);
            if ($round === false) {
                throw new DomainRuleViolation('Non esiste un round attivo.');
            }

            $existing = $this->connection->fetchAssociative(<<<'SQL'
SELECT
     id
    ,public_code
    ,participation_number
FROM play
WHERE round_id = :roundId
  AND player_session_id = :playerSessionId
  AND status IN ('CREATED', 'IN_PROGRESS')
LIMIT 1
SQL, [
                'roundId' => $round['id'],
                'playerSessionId' => $playerSessionId,
            ]);

            if ($existing !== false) {
                $this->connection->commit();

                return new StartedPlay(
                    (string) $existing['id'],
                    (string) $existing['public_code'],
                    (string) $round['id'],
                    (string) $round['public_code'],
                    (int) $existing['participation_number'],
                    (int) $round['initial_jackpot_cents'] + (int) $round['entry_contribution_cents'],
                    true,
                );
            }

            $credit = $this->connection->fetchAssociative(<<<'SQL'
SELECT id, source_round_id, source_play_id
FROM play_credit
WHERE player_session_id = :playerSessionId
  AND status = 'AVAILABLE'
ORDER BY issued_at, id
LIMIT 1
SQL, ['playerSessionId' => $playerSessionId]);

            $playId = (string) new Ulid();
            $playPublicCode = $this->generatePublicCode();
            $participationNumber = (int) $this->connection->fetchOne(
                'SELECT COALESCE(MAX(participation_number), 0) + 1 FROM play WHERE round_id = :roundId',
                ['roundId' => $round['id']],
            );
            $now = $this->clock->now()->format('Y-m-d H:i:s.u');
            $correlationId = (string) Uuid::v7();
            $entryKind = $credit === false ? 'STANDARD' : 'RESTART_CREDIT';

            $this->connection->insert('play', [
                'id' => $playId,
                'public_code' => $playPublicCode,
                'round_id' => $round['id'],
                'player_session_id' => $playerSessionId,
                'status' => 'IN_PROGRESS',
                'participation_number' => $participationNumber,
                'current_step' => 0,
                'chosen_path_bits' => '',
                'entry_kind' => $entryKind,
                'started_at' => $now,
                'completed_at' => null,
                'interrupted_at' => null,
                'created_at' => $now,
                'version' => 1,
            ]);

            if ($credit === false) {
                $this->recordStandardEntry((string) $round['id'], $playId, $correlationId, $now);

                $updated = $this->connection->executeStatement(<<<'SQL'
UPDATE game_round
SET
     entry_contribution_cents = entry_contribution_cents + :contribution
    ,version = version + 1
WHERE id = :roundId
  AND status = 'ACTIVE'
SQL, [
                    'contribution' => self::JACKPOT_CONTRIBUTION_CENTS,
                    'roundId' => $round['id'],
                ]);
                if ($updated !== 1) {
                    throw new DomainRuleViolation('Il round non è più disponibile.');
                }

                $this->audit->append('PLAY_STARTED', [
                    'entryKind' => 'STANDARD',
                    'participationNumber' => $participationNumber,
                    'virtualEntryCents' => self::ENTRY_CENTS,
                    'jackpotContributionCents' => self::JACKPOT_CONTRIBUTION_CENTS,
                    'organizerShareCents' => self::ORGANIZER_SHARE_CENTS,
                ], (string) $round['id'], $playId, $correlationId);

                $jackpot = (int) $round['initial_jackpot_cents']
                    + (int) $round['entry_contribution_cents']
                    + self::JACKPOT_CONTRIBUTION_CENTS;
            } else {
                $redeemed = $this->connection->executeStatement(<<<'SQL'
UPDATE play_credit
SET
     status = 'REDEEMED'
    ,redeemed_at = :redeemedAt
    ,redeemed_play_id = :redeemedPlayId
WHERE id = :creditId
  AND status = 'AVAILABLE'
  AND redeemed_play_id IS NULL
SQL, [
                    'redeemedAt' => $now,
                    'redeemedPlayId' => $playId,
                    'creditId' => $credit['id'],
                ]);
                if ($redeemed !== 1) {
                    throw new DomainRuleViolation('Il credito di ripartenza è già stato utilizzato.');
                }

                $this->connection->insert('ledger_entry', [
                    'id' => (string) new Ulid(),
                    'round_id' => $round['id'],
                    'play_id' => $playId,
                    'entry_type' => 'RESTART_CREDIT_REDEEMED',
                    'amount_cents' => self::ENTRY_CENTS,
                    'correlation_id' => $correlationId,
                    'created_at' => $now,
                ]);

                $this->audit->append('RESTART_CREDIT_REDEEMED', [
                    'creditId' => (string) $credit['id'],
                    'sourceRoundId' => (string) $credit['source_round_id'],
                    'sourcePlayId' => (string) $credit['source_play_id'],
                    'participationNumber' => $participationNumber,
                    'jackpotContributionCents' => 0,
                    'organizerShareCents' => 0,
                ], (string) $round['id'], $playId, $correlationId);
                $this->audit->append('PLAY_STARTED', [
                    'entryKind' => 'RESTART_CREDIT',
                    'participationNumber' => $participationNumber,
                    'virtualEntryCents' => 0,
                    'jackpotContributionCents' => 0,
                    'organizerShareCents' => 0,
                ], (string) $round['id'], $playId, $correlationId);

                $jackpot = (int) $round['initial_jackpot_cents'] + (int) $round['entry_contribution_cents'];
            }

            $this->connection->commit();

            return new StartedPlay(
                $playId,
                $playPublicCode,
                (string) $round['id'],
                (string) $round['public_code'],
                $participationNumber,
                $jackpot,
                false,
            );
        } catch (Throwable $exception) {
            if ($this->connection->isTransactionActive()) {
                $this->connection->rollBack();
            }

            if ($exception instanceof UniqueConstraintViolationException) {
                $existing = $this->connection->fetchAssociative(<<<'SQL'
SELECT
     p.id
    ,p.public_code
    ,p.participation_number
    ,r.id AS round_id
    ,r.public_code AS round_public_code
    ,r.initial_jackpot_cents
    ,r.entry_contribution_cents
FROM play p
INNER JOIN game_round r ON r.id = p.round_id
WHERE p.player_session_id = :playerSessionId
  AND p.status IN ('CREATED', 'IN_PROGRESS')
  AND r.status = 'ACTIVE'
LIMIT 1
SQL, ['playerSessionId' => $playerSessionId]);

                if ($existing !== false) {
                    return new StartedPlay(
                        (string) $existing['id'],
                        (string) $existing['public_code'],
                        (string) $existing['round_id'],
                        (string) $existing['round_public_code'],
                        (int) $existing['participation_number'],
                        (int) $existing['initial_jackpot_cents'] + (int) $existing['entry_contribution_cents'],
                        true,
                    );
                }
            }

            throw $exception;
        }
    }

    private function recordStandardEntry(string $roundId, string $playId, string $correlationId, string $createdAt): void
    {
        foreach ([
            ['PLAYER_ENTRY', self::ENTRY_CENTS],
            ['JACKPOT_CONTRIBUTION', self::JACKPOT_CONTRIBUTION_CENTS],
            ['ORGANIZER_SHARE', self::ORGANIZER_SHARE_CENTS],
        ] as [$entryType, $amount]) {
            $this->connection->insert('ledger_entry', [
                'id' => (string) new Ulid(),
                'round_id' => $roundId,
                'play_id' => $playId,
                'entry_type' => $entryType,
                'amount_cents' => $amount,
                'correlation_id' => $correlationId,
                'created_at' => $createdAt,
            ]);
        }
    }

    private function generatePublicCode(): string
    {
        return 'G-'.strtoupper(bin2hex(random_bytes(12)));
    }
}
