<?php

declare(strict_types=1);

namespace App\Game\Application;

use App\Catalog\Domain\Repository\ChoicePairRepository;
use App\Catalog\Domain\Repository\QuestionSetSnapshotStore;
use App\Catalog\Domain\Service\CryptographicChoicePairSelector;
use App\Catalog\Domain\Service\QuestionSetFactory;
use App\Game\Domain\Exception\DomainRuleViolation;
use App\Game\Domain\Model\GameRound;
use App\Game\Domain\Security\RoundSecretCipher;
use App\Game\Domain\ValueObject\VirtualMoney;
use App\Game\Domain\ValueObject\WinningPath;
use App\Shared\Time\Clock;
use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\DBAL\ParameterType;
use Symfony\Component\Uid\Ulid;
use Symfony\Component\Uid\Uuid;
use Throwable;

final readonly class OpenRound
{
    private const INITIAL_JACKPOT_CENTS = 1_000_000;

    public function __construct(
        private Connection $connection,
        private ChoicePairRepository $choicePairs,
        private QuestionSetSnapshotStore $questionSetStore,
        private QuestionSetFactory $questionSetFactory,
        private CryptographicChoicePairSelector $selector,
        private RoundSecretCipher $secretCipher,
        private Clock $clock,
    ) {
    }

    public function open(): OpenedRound
    {
        $this->connection->beginTransaction();
        try {
            $opened = $this->createRound();
            $this->connection->commit();

            return $opened;
        } catch (Throwable $exception) {
            if ($this->connection->isTransactionActive()) {
                $this->connection->rollBack();
            }

            if ($exception instanceof UniqueConstraintViolationException
                && (int) $this->connection->fetchOne(
                    "SELECT COUNT(*) FROM game_round WHERE status = 'ACTIVE'",
                ) !== 0
            ) {
                throw new DomainRuleViolation('An active round already exists.', 0, $exception);
            }

            throw $exception;
        }
    }

    /**
     * Opens a round without committing or rolling back the caller transaction.
     * Used by M1.4 so the winning play, reset and next round are one atomic unit.
     */
    public function openWithinCurrentTransaction(): OpenedRound
    {
        if (!$this->connection->isTransactionActive()) {
            throw new DomainRuleViolation('Opening a round inside an existing transaction requires an active transaction.');
        }

        return $this->createRound();
    }

    public static function pathContext(string $roundId): string
    {
        return 'round:'.$roundId.':winning-path';
    }

    public static function nonceContext(string $roundId): string
    {
        return 'round:'.$roundId.':commitment-nonce';
    }

    private function createRound(): OpenedRound
    {
        if ((int) $this->connection->fetchOne(
            "SELECT COUNT(*) FROM game_round WHERE status = 'ACTIVE'",
        ) !== 0) {
            throw new DomainRuleViolation('An active round already exists.');
        }

        $activeRegular = $this->choicePairs->findAllActiveRegular();
        $selected = $this->selector->select($activeRegular, 19);
        $finalDoor = $this->choicePairs->findFinalDoor()
            ?? throw new DomainRuleViolation('The mandatory final door is missing from the catalog.');
        $questionSet = $this->questionSetFactory->create($selected, $finalDoor);

        $roundId = (string) new Ulid();
        $startedAt = $this->clock->now();
        $publicCode = $this->generatePublicCode($startedAt);
        $winningPath = WinningPath::generate();
        $secretNonce = random_bytes(32);
        $round = GameRound::prepare(
            $publicCode,
            $questionSet->hash(),
            $winningPath,
            $secretNonce,
            VirtualMoney::fromCents(self::INITIAL_JACKPOT_CENTS),
        );
        $encryptedPath = $this->secretCipher->encrypt(
            $winningPath->toBitString(),
            self::pathContext($roundId),
        );
        $encryptedNonce = $this->secretCipher->encrypt(
            $secretNonce,
            self::nonceContext($roundId),
        );
        $correlationId = (string) Uuid::v7();

        $this->connection->insert('game_round', [
            'id' => $roundId,
            'public_code' => $publicCode,
            'status' => 'PREPARING',
            'question_set_hash' => $questionSet->hash(),
            'secret_commitment' => $round->commitment()->hash,
            'encrypted_winning_path' => $encryptedPath,
            'encrypted_secret_nonce' => $encryptedNonce,
            'initial_jackpot_cents' => self::INITIAL_JACKPOT_CENTS,
            'entry_contribution_cents' => 0,
            'frozen_jackpot_cents' => null,
            'winner_play_id' => null,
            'started_at' => null,
            'won_at' => null,
            'settled_at' => null,
            'version' => 1,
        ], [
            'encrypted_winning_path' => ParameterType::BINARY,
            'encrypted_secret_nonce' => ParameterType::BINARY,
        ]);

        $this->questionSetStore->saveForRound($roundId, $questionSet);

        $this->connection->insert('ledger_entry', [
            'id' => (string) new Ulid(),
            'round_id' => $roundId,
            'play_id' => null,
            'entry_type' => 'BANK_SEED',
            'amount_cents' => self::INITIAL_JACKPOT_CENTS,
            'correlation_id' => $correlationId,
            'created_at' => self::formatDate($startedAt),
        ]);

        $activated = $this->connection->executeStatement(<<<'SQL'
UPDATE game_round
SET
     status = 'ACTIVE'
    ,started_at = :startedAt
    ,version = version + 1
WHERE id = :roundId
  AND status = 'PREPARING'
SQL, [
            'roundId' => $roundId,
            'startedAt' => self::formatDate($startedAt),
        ]);

        if ($activated !== 1) {
            throw new DomainRuleViolation('The prepared round could not be activated.');
        }

        return new OpenedRound(
            $roundId,
            $publicCode,
            $questionSet->hash(),
            $round->commitment()->hash,
            self::INITIAL_JACKPOT_CENTS,
            $startedAt,
            $this->secretCipher->algorithm(),
        );
    }

    private function generatePublicCode(DateTimeImmutable $startedAt): string
    {
        return sprintf(
            'R-%s-%s',
            $startedAt->format('Ymd'),
            strtoupper(bin2hex(random_bytes(6))),
        );
    }

    private static function formatDate(DateTimeImmutable $date): string
    {
        return $date->format('Y-m-d H:i:s.u');
    }
}
