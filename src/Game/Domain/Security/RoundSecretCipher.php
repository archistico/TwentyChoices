<?php

declare(strict_types=1);

namespace App\Game\Domain\Security;

interface RoundSecretCipher
{
    public function encrypt(string $plaintext, string $context): string;

    public function decrypt(string $ciphertext, string $context): string;

    public function algorithm(): string;
}
