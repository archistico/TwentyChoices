<?php

declare(strict_types=1);

namespace App\Security\Admin;

final class AdminPasswordHasher
{
    private const MIN_LENGTH = 12;

    public function hash(string $password): string
    {
        $this->assertStrongEnough($password);
        $algorithm = defined('PASSWORD_ARGON2ID') ? PASSWORD_ARGON2ID : PASSWORD_DEFAULT;
        $hash = password_hash($password, $algorithm);
        if (!is_string($hash)) {
            throw new \RuntimeException('Impossibile generare l hash della password.');
        }

        return $hash;
    }

    public function verify(string $password, string $hash): bool
    {
        return password_verify($password, $hash);
    }

    public function needsRehash(string $hash): bool
    {
        $algorithm = defined('PASSWORD_ARGON2ID') ? PASSWORD_ARGON2ID : PASSWORD_DEFAULT;

        return password_needs_rehash($hash, $algorithm);
    }

    public function assertStrongEnough(string $password): void
    {
        if (strlen($password) < self::MIN_LENGTH) {
            throw new \InvalidArgumentException('La password deve contenere almeno 12 caratteri.');
        }
        if (preg_match('/[A-Za-z]/', $password) !== 1 || preg_match('/\d/', $password) !== 1) {
            throw new \InvalidArgumentException('La password deve contenere almeno una lettera e una cifra.');
        }
    }
}
