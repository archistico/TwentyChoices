<?php

declare(strict_types=1);

namespace App\Tests\Game\Infrastructure;

use App\Game\Domain\Exception\DomainRuleViolation;
use App\Game\Infrastructure\Security\AuthenticatedRoundSecretCipher;
use PHPUnit\Framework\TestCase;

final class AuthenticatedRoundSecretCipherTest extends TestCase
{
    public function testItEncryptsAndDecryptsWithContextBinding(): void
    {
        $cipher = new AuthenticatedRoundSecretCipher('a-test-secret-with-sufficient-entropy');
        $plaintext = '10110001101001011100';
        $encrypted = $cipher->encrypt($plaintext, 'round:01TEST:winning-path');

        self::assertNotSame($plaintext, $encrypted);
        self::assertStringNotContainsString($plaintext, $encrypted);
        self::assertSame(
            $plaintext,
            $cipher->decrypt($encrypted, 'round:01TEST:winning-path'),
        );
        self::assertContains($cipher->algorithm(), [
            'sodium-secretbox-xsalsa20-poly1305',
            'openssl-aes-256-gcm',
        ]);
    }

    public function testTheDistributedPlaceholderSecretIsRejected(): void
    {
        $this->expectException(DomainRuleViolation::class);
        new AuthenticatedRoundSecretCipher('change-this-development-secret');
    }

    public function testTamperingIsRejected(): void
    {
        $cipher = new AuthenticatedRoundSecretCipher('a-test-secret-with-sufficient-entropy');
        $encrypted = $cipher->encrypt('secret-value', 'round:01TEST:nonce');
        $lastIndex = strlen($encrypted) - 1;
        $encrypted[$lastIndex] = chr(ord($encrypted[$lastIndex]) ^ 1);

        $this->expectException(DomainRuleViolation::class);
        $cipher->decrypt($encrypted, 'round:01TEST:nonce');
    }

    public function testASecretCannotBeDecryptedUnderAnotherContext(): void
    {
        $cipher = new AuthenticatedRoundSecretCipher('a-test-secret-with-sufficient-entropy');
        $encrypted = $cipher->encrypt('secret-value', 'round:01TEST:nonce');

        $this->expectException(DomainRuleViolation::class);
        $cipher->decrypt($encrypted, 'round:OTHER:nonce');
    }
}
