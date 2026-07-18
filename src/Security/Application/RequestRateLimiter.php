<?php

declare(strict_types=1);

namespace App\Security\Application;

use App\Shared\Time\Clock;
use Doctrine\DBAL\Connection;

final readonly class RequestRateLimiter
{
    public function __construct(
        private Connection $connection,
        private Clock $clock,
        private string $applicationSecret,
    ) {
    }

    public function consume(string $scope, string $subject, int $limit, int $windowSeconds): RateLimitDecision
    {
        if ($scope === '' || $subject === '' || $limit < 1 || $windowSeconds < 1) {
            throw new \InvalidArgumentException('Parametri rate limiter non validi.');
        }

        $now = $this->clock->now()->getTimestamp();
        $windowStart = intdiv($now, $windowSeconds) * $windowSeconds;
        $keyHash = $this->fingerprint($subject);
        $updatedAt = $this->clock->now()->format('Y-m-d H:i:s.u');

        $this->connection->executeStatement(<<<'SQL'
INSERT INTO request_rate_limit (
     scope
    ,key_hash
    ,window_start
    ,request_count
    ,updated_at
)
VALUES (
     :scope
    ,:keyHash
    ,:windowStart
    ,1
    ,:updatedAt
)
ON CONFLICT(scope, key_hash, window_start)
DO UPDATE SET
     request_count = request_count + 1
    ,updated_at = excluded.updated_at
SQL, [
            'scope' => $scope,
            'keyHash' => $keyHash,
            'windowStart' => $windowStart,
            'updatedAt' => $updatedAt,
        ]);

        $consumed = (int) $this->connection->fetchOne(<<<'SQL'
SELECT request_count
FROM request_rate_limit
WHERE scope = :scope
  AND key_hash = :keyHash
  AND window_start = :windowStart
SQL, [
            'scope' => $scope,
            'keyHash' => $keyHash,
            'windowStart' => $windowStart,
        ]);

        // Mantiene la tabella limitata senza introdurre job esterni nel prototipo.
        $this->connection->executeStatement(
            'DELETE FROM request_rate_limit WHERE window_start < :cutoff',
            ['cutoff' => $now - 172800],
        );

        $allowed = $consumed <= $limit;
        $remaining = max(0, $limit - $consumed);
        $retryAfter = $allowed ? 0 : max(1, ($windowStart + $windowSeconds) - $now);

        return new RateLimitDecision($allowed, $limit, $consumed, $remaining, $retryAfter);
    }

    public function fingerprint(string $subject): string
    {
        return hash_hmac('sha256', $subject, $this->applicationSecret);
    }
}
