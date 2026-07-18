<?php

declare(strict_types=1);

namespace App\Security\Admin;

use App\Shared\Time\Clock;
use Doctrine\DBAL\Connection;
use Symfony\Component\Uid\Ulid;

final readonly class AdminUserRepository
{
    public function __construct(
        private Connection $connection,
        private Clock $clock,
    ) {
    }

    /** @return array<string,mixed>|null */
    public function findByUsername(string $username): ?array
    {
        $row = $this->connection->fetchAssociative(
            'SELECT * FROM admin_user WHERE username_normalized = :username LIMIT 1',
            ['username' => self::normalizeUsername($username)],
        );

        return $row === false ? null : $row;
    }

    /** @return array<string,mixed>|null */
    public function findById(string $id): ?array
    {
        $row = $this->connection->fetchAssociative('SELECT * FROM admin_user WHERE id = :id LIMIT 1', ['id' => $id]);

        return $row === false ? null : $row;
    }

    /** @return list<array<string,mixed>> */
    public function all(): array
    {
        return $this->connection->fetchAllAssociative(
            'SELECT id, username, role, is_active, created_at, updated_at, last_login_at, password_changed_at FROM admin_user ORDER BY username_normalized',
        );
    }

    public function create(string $username, string $passwordHash, AdminRole $role): string
    {
        $username = self::validateUsername($username);
        $now = $this->clock->now()->format('Y-m-d H:i:s.u');
        $id = (string) new Ulid();
        $this->connection->insert('admin_user', [
            'id' => $id,
            'username' => $username,
            'username_normalized' => self::normalizeUsername($username),
            'password_hash' => $passwordHash,
            'role' => $role->value,
            'is_active' => 1,
            'created_at' => $now,
            'updated_at' => $now,
            'last_login_at' => null,
            'password_changed_at' => $now,
            'auth_version' => 1,
        ]);

        return $id;
    }

    public function updateLastLogin(string $id): void
    {
        $now = $this->clock->now()->format('Y-m-d H:i:s.u');
        $this->connection->update('admin_user', ['last_login_at' => $now, 'updated_at' => $now], ['id' => $id]);
    }

    public function updatePassword(string $id, string $passwordHash): void
    {
        $now = $this->clock->now()->format('Y-m-d H:i:s.u');
        $this->connection->executeStatement(<<<'SQL'
UPDATE admin_user
SET password_hash = :passwordHash,
    password_changed_at = :now,
    updated_at = :now,
    auth_version = auth_version + 1
WHERE id = :id
SQL, ['passwordHash' => $passwordHash, 'now' => $now, 'id' => $id]);
    }

    public function updateRole(string $id, AdminRole $role): void
    {
        $this->connection->executeStatement(<<<'SQL'
UPDATE admin_user
SET role = :role,
    updated_at = :now,
    auth_version = auth_version + 1
WHERE id = :id AND role <> :currentRole
SQL, ['role' => $role->value, 'currentRole' => $role->value, 'now' => $this->clock->now()->format('Y-m-d H:i:s.u'), 'id' => $id]);
    }

    public function setActive(string $id, bool $active): void
    {
        $this->connection->executeStatement(<<<'SQL'
UPDATE admin_user
SET is_active = :active,
    updated_at = :now,
    auth_version = auth_version + 1
WHERE id = :id AND is_active <> :currentActive
SQL, ['active' => $active ? 1 : 0, 'currentActive' => $active ? 1 : 0, 'now' => $this->clock->now()->format('Y-m-d H:i:s.u'), 'id' => $id]);
    }

    public function count(): int
    {
        return (int) $this->connection->fetchOne('SELECT COUNT(*) FROM admin_user');
    }

    public static function normalizeUsername(string $username): string
    {
        return strtolower(trim($username));
    }

    public static function validateUsername(string $username): string
    {
        $username = trim($username);
        if (preg_match('/^[A-Za-z0-9._-]{3,64}$/', $username) !== 1) {
            throw new \InvalidArgumentException('Username non valido: usa 3-64 caratteri tra lettere, numeri, punto, trattino e underscore.');
        }

        return $username;
    }
}
