<?php

declare(strict_types=1);

namespace App\Verification\Application;

use App\Audit\Application\AuditLogger;
use App\Game\Application\OpenRound;
use App\Game\Domain\Exception\DomainRuleViolation;
use App\Game\Domain\Security\RoundSecretCipher;
use App\Game\Domain\ValueObject\RoundCommitment;
use App\Game\Domain\ValueObject\WinningPath;
use App\Shared\Time\Clock;
use Doctrine\DBAL\Connection;
use Symfony\Component\Uid\Uuid;
use Throwable;

final readonly class RoundVerificationPublisher
{
    public function __construct(
        private Connection $connection,
        private RoundSecretCipher $secretCipher,
        private Clock $clock,
        private AuditLogger $audit,
    ) {
    }

    public function publishLegacySettledByPublicCode(string $publicCode): void
    {
        $this->connection->beginTransaction();
        try {
            $round = $this->connection->fetchAssociative(<<<'SQL'
SELECT id, public_code, status, question_set_hash, secret_commitment,
       encrypted_winning_path, encrypted_secret_nonce,
       revealed_winning_path, revealed_secret_nonce_hex
FROM game_round
WHERE public_code = :publicCode
LIMIT 1
SQL, ['publicCode' => $publicCode]);

            if ($round === false || (string) $round['status'] !== 'SETTLED'
                || ($round['revealed_winning_path'] !== null && $round['revealed_secret_nonce_hex'] !== null)
            ) {
                $this->connection->commit();
                return;
            }

            $roundId = (string) $round['id'];
            $pathBits = $this->secretCipher->decrypt(
                self::blobToString($round['encrypted_winning_path']),
                OpenRound::pathContext($roundId),
            );
            $nonce = $this->secretCipher->decrypt(
                self::blobToString($round['encrypted_secret_nonce']),
                OpenRound::nonceContext($roundId),
            );
            $path = WinningPath::fromBitString($pathBits);
            if (!RoundCommitment::fromHash((string) $round['secret_commitment'])->verifies(
                (string) $round['public_code'],
                (string) $round['question_set_hash'],
                $path,
                $nonce,
            )) {
                throw new DomainRuleViolation('Legacy settled round failed cryptographic verification before publication.');
            }

            $publishedAt = $this->clock->now()->format('Y-m-d H:i:s.u');
            $updated = $this->connection->executeStatement(<<<'SQL'
UPDATE game_round
SET revealed_winning_path = :path,
    revealed_secret_nonce_hex = :nonceHex,
    verification_published_at = :publishedAt,
    version = version + 1
WHERE id = :roundId
  AND status = 'SETTLED'
  AND revealed_winning_path IS NULL
  AND revealed_secret_nonce_hex IS NULL
SQL, [
                'path' => $pathBits,
                'nonceHex' => bin2hex($nonce),
                'publishedAt' => $publishedAt,
                'roundId' => $roundId,
            ]);

            if ($updated === 1) {
                $this->audit->append('ROUND_VERIFICATION_PUBLISHED', [
                    'legacyBackfill' => true,
                    'winningPath' => $pathBits,
                    'nonceHex' => bin2hex($nonce),
                ], $roundId, null, (string) Uuid::v7());
            }

            $this->connection->commit();
        } catch (Throwable $exception) {
            if ($this->connection->isTransactionActive()) {
                $this->connection->rollBack();
            }
            throw $exception;
        }
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
