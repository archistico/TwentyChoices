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
    public function testPathAndNonceCiphertextsCannotBeSwappedWithinTheSameRound(): void
    {
        $cipher = new AuthenticatedRoundSecretCipher('a-test-secret-with-sufficient-entropy');
        $roundId = '01TESTROUND';
        $encryptedPath = $cipher->encrypt('10110001101001011100', 'round:'.$roundId.':winning-path');
        $encryptedNonce = $cipher->encrypt(str_repeat("\x2A", 32), 'round:'.$roundId.':commitment-nonce');

        try {
            $cipher->decrypt($encryptedPath, 'round:'.$roundId.':commitment-nonce');
            self::fail('The winning-path ciphertext must not decrypt as the nonce.');
        } catch (DomainRuleViolation) {
            self::assertTrue(true);
        }

        $this->expectException(DomainRuleViolation::class);
        $cipher->decrypt($encryptedNonce, 'round:'.$roundId.':winning-path');
    }

}
