<?php

declare(strict_types=1);

namespace App\Shared\Security;

final readonly class SecureTokenGenerator
{
    public function generate(int $bytes = 32): string
    {
        if ($bytes < 16) {
            throw new \InvalidArgumentException('A security token must contain at least 128 bits of entropy.');
        }

        return rtrim(strtr(base64_encode(random_bytes($bytes)), '+/', '-_'), '=');
    }

    public function hash(string $token): string
    {
        return hash('sha256', $token);
    }

    public function isWellFormed(string $token, int $bytes = 32): bool
    {
        $expectedLength = (int) ceil($bytes * 4 / 3);

        return strlen($token) === $expectedLength
            && preg_match('/^[A-Za-z0-9_-]+$/D', $token) === 1;
    }
}
