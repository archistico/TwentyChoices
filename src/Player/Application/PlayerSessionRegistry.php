<?php

declare(strict_types=1);

namespace App\Player\Application;

use App\Audit\Application\AuditLogger;
use App\Game\Domain\Exception\DomainRuleViolation;
use App\Shared\Security\SecureTokenGenerator;
use App\Shared\Time\Clock;
use Doctrine\DBAL\Connection;
use Symfony\Component\Uid\Ulid;
use Throwable;

final readonly class PlayerSessionRegistry
{
    public function __construct(
        private Connection $connection,
        private SecureTokenGenerator $tokens,
        private Clock $clock,
        private AuditLogger $audit,
    ) {
    }

    public function resolve(?string $rawToken): PlayerSessionIdentity
    {
        if ($rawToken !== null && $this->tokens->isWellFormed($rawToken)) {
            $existing = $this->findByToken($rawToken);
            if ($existing !== null) {
                $this->connection->update('player_session', [
                    'last_seen_at' => $this->formatNow(),
                ], ['id' => $existing->id]);

                return $existing;
            }
        }

        return $this->create();
    }

    public function requireExisting(?string $rawToken): PlayerSessionIdentity
    {
        if ($rawToken === null || !$this->tokens->isWellFormed($rawToken)) {
            throw new DomainRuleViolation('La sessione anonima della giocata non è disponibile.');
        }

        return $this->findByToken($rawToken)
            ?? throw new DomainRuleViolation('La sessione anonima della giocata non è valida.');
    }

    private function findByToken(string $rawToken): ?PlayerSessionIdentity
    {
        $row = $this->connection->fetchAssociative(<<<'SQL'
SELECT id
FROM player_session
WHERE public_token_hash = :tokenHash
  AND blocked_at IS NULL
LIMIT 1
SQL, ['tokenHash' => $this->tokens->hash($rawToken)]);

        return $row === false
            ? null
            : new PlayerSessionIdentity((string) $row['id'], $rawToken, false);
    }

    private function create(): PlayerSessionIdentity
    {
        $this->connection->beginTransaction();
        try {
            $rawToken = $this->tokens->generate();
            $id = (string) new Ulid();
            $now = $this->formatNow();
            $this->connection->insert('player_session', [
                'id' => $id,
                'public_token_hash' => $this->tokens->hash($rawToken),
                'created_at' => $now,
                'last_seen_at' => $now,
                'blocked_at' => null,
            ]);
            $this->audit->append('PLAYER_SESSION_CREATED', [], null, null, null);
            $this->connection->commit();

            return new PlayerSessionIdentity($id, $rawToken, true);
        } catch (Throwable $exception) {
            if ($this->connection->isTransactionActive()) {
                $this->connection->rollBack();
            }

            throw $exception;
        }
    }

    private function formatNow(): string
    {
        return $this->clock->now()->format('Y-m-d H:i:s.u');
    }
}
