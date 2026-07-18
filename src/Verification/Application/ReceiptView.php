<?php

declare(strict_types=1);

namespace App\Verification\Application;

use DateTimeImmutable;

final readonly class ReceiptView
{
    public function __construct(
        public string $verificationCode,
        public string $receiptHash,
        public bool $receiptIntegrityValid,
        public string $playPublicCode,
        public string $roundPublicCode,
        public int $participationNumber,
        public string $entryKind,
        public string $outcome,
        public int $completedSteps,
        public string $chosenPathBits,
        public DateTimeImmutable $issuedAt,
        public string $roundStatus,
        public string $commitment,
        public string $questionSetHash,
        public ?string $winningPath,
        public ?string $nonceHex,
        public bool $roundVerificationAvailable,
        public bool $roundCommitmentValid,
        public ?string $winnerPlayPublicCode,
        public ?int $frozenJackpotCents,
        public bool $outcomeConsistent,
    ) {
    }

    public function frozenJackpotFormatted(): ?string
    {
        return $this->frozenJackpotCents === null
            ? null
            : number_format($this->frozenJackpotCents / 100, 2, ',', '.').' €';
    }
}
