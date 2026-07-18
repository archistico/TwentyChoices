<?php

declare(strict_types=1);

namespace App\Game\Infrastructure\Security;

use App\Game\Domain\Exception\DomainRuleViolation;
use App\Game\Domain\Security\RoundSecretCipher;
use Throwable;

final readonly class AuthenticatedRoundSecretCipher implements RoundSecretCipher
{
    private const SODIUM_PREFIX = "TC1S";
    private const OPENSSL_PREFIX = "TC1G";
    private const OPENSSL_CIPHER = 'aes-256-gcm';

    private string $masterKey;

    public function __construct(string $applicationSecret)
    {
        $applicationSecret = trim($applicationSecret);
        if ($applicationSecret === '' || $applicationSecret === 'change-this-development-secret') {
            throw new DomainRuleViolation('A private APP_SECRET is required to encrypt round secrets.');
        }

        $this->masterKey = hash_hkdf(
            'sha256',
            $applicationSecret,
            32,
            'twenty-choices-round-secrets-master-v1',
        );
    }

    public function encrypt(string $plaintext, string $context): string
    {
        $this->assertContext($context);

        if (function_exists('sodium_crypto_secretbox')) {
            $nonce = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
            $ciphertext = sodium_crypto_secretbox(
                $plaintext,
                $nonce,
                $this->deriveKey($context, 'sodium-secretbox'),
            );

            return self::SODIUM_PREFIX.$nonce.$ciphertext;
        }

        if ($this->supportsOpenSslGcm()) {
            $iv = random_bytes(12);
            $tag = '';
            $ciphertext = openssl_encrypt(
                $plaintext,
                self::OPENSSL_CIPHER,
                $this->deriveKey($context, 'openssl-aes-256-gcm'),
                OPENSSL_RAW_DATA,
                $iv,
                $tag,
                $context,
                16,
            );

            if ($ciphertext === false || strlen($tag) !== 16) {
                throw new DomainRuleViolation('Unable to encrypt the round secret with OpenSSL.');
            }

            return self::OPENSSL_PREFIX.$iv.$tag.$ciphertext;
        }

        throw new DomainRuleViolation('Neither Sodium secretbox nor OpenSSL AES-256-GCM is available.');
    }

    public function decrypt(string $ciphertext, string $context): string
    {
        $this->assertContext($context);

        try {
            if (str_starts_with($ciphertext, self::SODIUM_PREFIX)) {
                return $this->decryptSodium($ciphertext, $context);
            }

            if (str_starts_with($ciphertext, self::OPENSSL_PREFIX)) {
                return $this->decryptOpenSsl($ciphertext, $context);
            }
        } catch (Throwable $exception) {
            throw new DomainRuleViolation('The encrypted round secret is invalid or has been tampered with.', 0, $exception);
        }

        throw new DomainRuleViolation('Unknown round-secret ciphertext format.');
    }

    public function algorithm(): string
    {
        if (function_exists('sodium_crypto_secretbox')) {
            return 'sodium-secretbox-xsalsa20-poly1305';
        }

        if ($this->supportsOpenSslGcm()) {
            return 'openssl-aes-256-gcm';
        }

        return 'unavailable';
    }

    private function decryptSodium(string $payload, string $context): string
    {
        if (!function_exists('sodium_crypto_secretbox_open')) {
            throw new DomainRuleViolation('Sodium is required to decrypt this round secret.');
        }

        $minimumLength = strlen(self::SODIUM_PREFIX)
            + SODIUM_CRYPTO_SECRETBOX_NONCEBYTES
            + SODIUM_CRYPTO_SECRETBOX_MACBYTES;
        if (strlen($payload) < $minimumLength) {
            throw new DomainRuleViolation('The Sodium ciphertext is truncated.');
        }

        $offset = strlen(self::SODIUM_PREFIX);
        $nonce = substr($payload, $offset, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        $encrypted = substr($payload, $offset + SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        $plaintext = sodium_crypto_secretbox_open(
            $encrypted,
            $nonce,
            $this->deriveKey($context, 'sodium-secretbox'),
        );

        if ($plaintext === false) {
            throw new DomainRuleViolation('The Sodium authentication tag is invalid.');
        }

        return $plaintext;
    }

    private function decryptOpenSsl(string $payload, string $context): string
    {
        if (!$this->supportsOpenSslGcm()) {
            throw new DomainRuleViolation('OpenSSL AES-256-GCM is required to decrypt this round secret.');
        }

        $minimumLength = strlen(self::OPENSSL_PREFIX) + 12 + 16;
        if (strlen($payload) < $minimumLength) {
            throw new DomainRuleViolation('The OpenSSL ciphertext is truncated.');
        }

        $offset = strlen(self::OPENSSL_PREFIX);
        $iv = substr($payload, $offset, 12);
        $tag = substr($payload, $offset + 12, 16);
        $encrypted = substr($payload, $offset + 28);
        $plaintext = openssl_decrypt(
            $encrypted,
            self::OPENSSL_CIPHER,
            $this->deriveKey($context, 'openssl-aes-256-gcm'),
            OPENSSL_RAW_DATA,
            $iv,
            $tag,
            $context,
        );

        if ($plaintext === false) {
            throw new DomainRuleViolation('The OpenSSL authentication tag is invalid.');
        }

        return $plaintext;
    }

    private function deriveKey(string $context, string $algorithm): string
    {
        return hash_hkdf(
            'sha256',
            $this->masterKey,
            32,
            'twenty-choices:'.$algorithm.':'.$context,
        );
    }

    private function supportsOpenSslGcm(): bool
    {
        return function_exists('openssl_encrypt')
            && in_array(self::OPENSSL_CIPHER, openssl_get_cipher_methods(), true);
    }

    private function assertContext(string $context): void
    {
        if (trim($context) === '') {
            throw new DomainRuleViolation('An encryption context is required.');
        }
    }
}
