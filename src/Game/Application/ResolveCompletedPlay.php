<?php

declare(strict_types=1);

namespace App\Game\Application;

use App\Audit\Application\AuditLogger;
use App\Game\Domain\Exception\DomainRuleViolation;
use App\Game\Domain\Security\RoundSecretCipher;
use App\Game\Domain\ValueObject\RoundCommitment;
use App\Game\Domain\ValueObject\WinningPath;
use App\Shared\Time\Clock;
use App\Verification\Application\PlayReceiptIssuer;
use Doctrine\DBAL\Connection;
use Symfony\Component\Uid\Ulid;
use Symfony\Component\Uid\Uuid;

final readonly class ResolveCompletedPlay
{
    private const RESTART_CREDIT_CENTS = 100;

    public function __construct(
        private Connection $connection,
        private RoundSecretCipher $secretCipher,
        private OpenRound $openRound,
        private Clock $clock,
        private AuditLogger $audit,
        private PlayReceiptIssuer $receipts,
    ) {
    }

    public function resolve(string $playId, string $requestId): CompletedPlayResolution
    {
        if (!$this->connection->isTransactionActive()) {
            throw new DomainRuleViolation('Completed-play resolution must run inside the choice transaction.');
        }

        $play = $this->connection->fetchAssociative(<<<'SQL'
SELECT
     p.id
    ,p.public_code
    ,p.round_id
    ,p.player_session_id
    ,p.status
    ,p.current_step
    ,p.chosen_path_bits
    ,p.completed_at
    ,r.public_code AS round_public_code
    ,r.status AS round_status
    ,r.question_set_hash
    ,r.secret_commitment
    ,r.encrypted_winning_path
    ,r.encrypted_secret_nonce
    ,r.initial_jackpot_cents
    ,r.entry_contribution_cents
FROM play p
INNER JOIN game_round r ON r.id = p.round_id
WHERE p.id = :playId
LIMIT 1
SQL, ['playId' => $playId]);

        if ($play === false) {
            throw new DomainRuleViolation('The completed play no longer exists.');
        }
        if ((string) $play['status'] !== 'IN_PROGRESS'
            || (int) $play['current_step'] !== 20
            || $play['completed_at'] === null
        ) {
            throw new DomainRuleViolation('Only a fully completed in-progress play can be resolved.');
        }
        if ((string) $play['round_status'] !== 'ACTIVE') {
            throw new DomainRuleViolation('The completed play belongs to a round that is no longer active.');
        }

        $roundId = (string) $play['round_id'];
        $winningPath = WinningPath::fromBitString($this->secretCipher->decrypt(
            self::blobToString($play['encrypted_winning_path']),
            OpenRound::pathContext($roundId),
        ));
        $secretNonce = $this->secretCipher->decrypt(
            self::blobToString($play['encrypted_secret_nonce']),
            OpenRound::nonceContext($roundId),
        );
        $commitment = RoundCommitment::fromHash((string) $play['secret_commitment']);

        if (!$commitment->verifies(
            (string) $play['round_public_code'],
            (string) $play['question_set_hash'],
            $winningPath,
            $secretNonce,
        )) {
            throw new DomainRuleViolation('Round cryptographic verification failed before resolving the play.');
        }

        $submittedPath = WinningPath::fromBitString((string) $play['chosen_path_bits']);
        if (!$winningPath->equals($submittedPath)) {
            $updated = $this->connection->executeStatement(<<<'SQL'
UPDATE play
SET
     status = 'COMPLETED_LOST'
    ,version = version + 1
WHERE id = :playId
  AND status = 'IN_PROGRESS'
  AND current_step = 20
SQL, ['playId' => $playId]);
            if ($updated !== 1) {
                throw new DomainRuleViolation('The completed losing play was modified concurrently.');
            }

            $verificationCode = $this->receipts->ensureForTerminalPlay($playId);
            $this->audit->append('PLAY_COMPLETED_LOST', [
                'choices' => 20,
                'verificationCode' => $verificationCode,
            ], $roundId, $playId, $requestId);

            return new CompletedPlayResolution('LOST');
        }

        $now = $this->clock->now();
        $nowValue = $now->format('Y-m-d H:i:s.u');
        $frozenJackpot = (int) $play['initial_jackpot_cents'] + (int) $play['entry_contribution_cents'];

        // This conditional state transition is the single source of truth for "first winner wins".
        $roundWon = $this->connection->executeStatement(<<<'SQL'
UPDATE game_round
SET
     status = 'WON'
    ,winner_play_id = :winnerPlayId
    ,frozen_jackpot_cents = :frozenJackpot
    ,won_at = :wonAt
    ,version = version + 1
WHERE id = :roundId
  AND status = 'ACTIVE'
  AND winner_play_id IS NULL
SQL, [
            'winnerPlayId' => $playId,
            'frozenJackpot' => $frozenJackpot,
            'wonAt' => $nowValue,
            'roundId' => $roundId,
        ]);
        if ($roundWon !== 1) {
            throw new DomainRuleViolation('The round was already won by another validated play.');
        }

        $winnerUpdated = $this->connection->executeStatement(<<<'SQL'
UPDATE play
SET
     status = 'COMPLETED_WON'
    ,version = version + 1
WHERE id = :playId
  AND status = 'IN_PROGRESS'
  AND current_step = 20
SQL, ['playId' => $playId]);
        if ($winnerUpdated !== 1) {
            throw new DomainRuleViolation('The winning play could not be finalized.');
        }
        $winnerVerificationCode = $this->receipts->ensureForTerminalPlay($playId);

        $payoutCorrelation = (string) Uuid::v7();
        $this->connection->insert('ledger_entry', [
            'id' => (string) new Ulid(),
            'round_id' => $roundId,
            'play_id' => $playId,
            'entry_type' => 'JACKPOT_PAYOUT',
            'amount_cents' => $frozenJackpot,
            'correlation_id' => $payoutCorrelation,
            'created_at' => $nowValue,
        ]);

        $openPlays = $this->connection->fetchAllAssociative(<<<'SQL'
SELECT id, player_session_id
FROM play
WHERE round_id = :roundId
  AND id <> :winnerPlayId
  AND status IN ('CREATED', 'IN_PROGRESS')
ORDER BY id
SQL, [
            'roundId' => $roundId,
            'winnerPlayId' => $playId,
        ]);

        foreach ($openPlays as $openPlay) {
            $sourcePlayId = (string) $openPlay['id'];
            $interrupted = $this->connection->executeStatement(<<<'SQL'
UPDATE play
SET
     status = 'INTERRUPTED'
    ,interrupted_at = :interruptedAt
    ,version = version + 1
WHERE id = :playId
  AND status IN ('CREATED', 'IN_PROGRESS')
SQL, [
                'interruptedAt' => $nowValue,
                'playId' => $sourcePlayId,
            ]);
            if ($interrupted !== 1) {
                throw new DomainRuleViolation('An open play changed while the winning round was being settled.');
            }

            $creditId = (string) new Ulid();
            $this->connection->insert('play_credit', [
                'id' => $creditId,
                'player_session_id' => (string) $openPlay['player_session_id'],
                'source_round_id' => $roundId,
                'source_play_id' => $sourcePlayId,
                'status' => 'AVAILABLE',
                'issued_at' => $nowValue,
                'redeemed_at' => null,
                'redeemed_play_id' => null,
            ]);

            $creditCorrelation = (string) Uuid::v7();
            $this->connection->insert('ledger_entry', [
                'id' => (string) new Ulid(),
                'round_id' => $roundId,
                'play_id' => $sourcePlayId,
                'entry_type' => 'RESTART_CREDIT_ISSUED',
                'amount_cents' => self::RESTART_CREDIT_CENTS,
                'correlation_id' => $creditCorrelation,
                'created_at' => $nowValue,
            ]);

            $credited = $this->connection->executeStatement(<<<'SQL'
UPDATE play
SET
     status = 'CREDITED'
    ,version = version + 1
WHERE id = :playId
  AND status = 'INTERRUPTED'
SQL, ['playId' => $sourcePlayId]);
            if ($credited !== 1) {
                throw new DomainRuleViolation('The interrupted play could not be marked as credited.');
            }

            $interruptedVerificationCode = $this->receipts->ensureForTerminalPlay($sourcePlayId);
            $this->audit->append('PLAY_INTERRUPTED_BY_WINNER', [
                'winnerPlayId' => $playId,
                'restartCreditId' => $creditId,
                'verificationCode' => $interruptedVerificationCode,
            ], $roundId, $sourcePlayId, $creditCorrelation);
            $this->audit->append('RESTART_CREDIT_ISSUED', [
                'creditId' => $creditId,
                'virtualValueCents' => self::RESTART_CREDIT_CENTS,
            ], $roundId, $sourcePlayId, $creditCorrelation);
        }

        $this->audit->append('ROUND_WON', [
            'winnerPlayId' => $playId,
            'winnerPlayCode' => (string) $play['public_code'],
            'frozenJackpotCents' => $frozenJackpot,
            'interruptedPlayCount' => count($openPlays),
            'verificationCode' => $winnerVerificationCode,
        ], $roundId, $playId, $requestId);
        $this->audit->append('JACKPOT_PAYOUT_RECORDED', [
            'virtualAmountCents' => $frozenJackpot,
        ], $roundId, $playId, $payoutCorrelation);

        // The old round is no longer ACTIVE, so a new ACTIVE round can be opened in this same transaction.
        $nextRound = $this->openRound->openWithinCurrentTransaction();

        $settled = $this->connection->executeStatement(<<<'SQL'
UPDATE game_round
SET
     status = 'SETTLED'
    ,settled_at = :settledAt
    ,revealed_winning_path = :revealedWinningPath
    ,revealed_secret_nonce_hex = :revealedSecretNonceHex
    ,verification_published_at = :verificationPublishedAt
    ,version = version + 1
WHERE id = :roundId
  AND status = 'WON'
  AND winner_play_id = :winnerPlayId
SQL, [
            'settledAt' => $nowValue,
            'revealedWinningPath' => $winningPath->toBitString(),
            'revealedSecretNonceHex' => bin2hex($secretNonce),
            'verificationPublishedAt' => $nowValue,
            'roundId' => $roundId,
            'winnerPlayId' => $playId,
        ]);
        if ($settled !== 1) {
            throw new DomainRuleViolation('The won round could not be settled.');
        }

        $this->audit->append('ROUND_VERIFICATION_PUBLISHED', [
            'winningPath' => $winningPath->toBitString(),
            'nonceHex' => bin2hex($secretNonce),
            'commitment' => $commitment->hash,
        ], $roundId, $playId, $requestId);
        $this->audit->append('ROUND_SETTLED', [
            'nextRoundId' => $nextRound->id,
            'nextRoundPublicCode' => $nextRound->publicCode,
        ], $roundId, $playId, $requestId);
        $this->audit->append('NEXT_ROUND_OPENED', [
            'previousRoundId' => $roundId,
            'initialJackpotCents' => $nextRound->initialJackpotCents,
        ], $nextRound->id, null, $requestId);

        return new CompletedPlayResolution(
            'WON',
            $frozenJackpot,
            $nextRound->publicCode,
            count($openPlays),
        );
    }

    private static function blobToString(mixed $value): string
    {
        if (is_resource($value)) {
            $contents = stream_get_contents($value);

            return $contents === false ? '' : $contents;
        }

        return (string) $value;
    }
}
