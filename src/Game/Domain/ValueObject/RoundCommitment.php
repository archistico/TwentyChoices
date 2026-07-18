<?php

declare(strict_types=1);

namespace App\Game\Domain\ValueObject;

use App\Game\Domain\Exception\DomainRuleViolation;

final readonly class RoundCommitment
{
    private function __construct(public string $hash)
    {
        if (!preg_match('/^[a-f0-9]{64}$/D', $hash)) {
            throw new DomainRuleViolation('A round commitment must be a lowercase SHA-256 hash.');
        }
    }

    public static function create(
        string $roundPublicCode,
        string $questionSetHash,
        WinningPath $winningPath,
        string $secretNonce,
    ): self {
        self::validateInputs($roundPublicCode, $questionSetHash, $secretNonce);

        return new self(hash('sha256', self::payload(
            $roundPublicCode,
            $questionSetHash,
            $winningPath,
            $secretNonce,
        )));
    }

    public static function fromHash(string $hash): self
    {
        return new self($hash);
    }

    public function verifies(
        string $roundPublicCode,
        string $questionSetHash,
        WinningPath $winningPath,
        string $secretNonce,
    ): bool {
        $candidate = self::create($roundPublicCode, $questionSetHash, $winningPath, $secretNonce);

        return hash_equals($this->hash, $candidate->hash);
    }

    private static function payload(
        string $roundPublicCode,
        string $questionSetHash,
        WinningPath $winningPath,
        string $secretNonce,
    ): string {
        return implode(':', [
            'twenty-choices-v1',
            $roundPublicCode,
            $questionSetHash,
            $winningPath->toBitString(),
            bin2hex($secretNonce),
        ]);
    }

    private static function validateInputs(
        string $roundPublicCode,
        string $questionSetHash,
        string $secretNonce,
    ): void {
        if ($roundPublicCode === '' || strlen($roundPublicCode) > 40) {
            throw new DomainRuleViolation('The round public code is required and must not exceed 40 characters.');
        }

        if (!preg_match('/^[a-f0-9]{64}$/D', $questionSetHash)) {
            throw new DomainRuleViolation('The question-set hash must be a lowercase SHA-256 hash.');
        }

        if (strlen($secretNonce) !== 32) {
            throw new DomainRuleViolation('The secret nonce must contain exactly 32 bytes.');
        }
    }
}
