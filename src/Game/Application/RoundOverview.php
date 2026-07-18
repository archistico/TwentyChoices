<?php

declare(strict_types=1);

namespace App\Game\Application;

use DateTimeImmutable;

final readonly class RoundOverview
{
    public function __construct(
        public string $id,
        public string $publicCode,
        public string $status,
        public string $questionSetHash,
        public string $commitment,
        public int $initialJackpotCents,
        public int $contributionCents,
        public ?int $frozenJackpotCents,
        public ?DateTimeImmutable $startedAt,
        public ?DateTimeImmutable $wonAt,
        public ?DateTimeImmutable $settledAt,
        public int $questionCount,
        public ?string $revealedWinningPath,
        public ?string $revealedSecretNonceHex,
        public ?DateTimeImmutable $verificationPublishedAt,
        public ?string $winnerPlayPublicCode,
    ) {
    }

    public function currentJackpotCents(): int
    {
        return $this->frozenJackpotCents
            ?? $this->initialJackpotCents + $this->contributionCents;
    }

    public function currentJackpotFormatted(): string
    {
        return number_format($this->currentJackpotCents() / 100, 2, ',', '.').' €';
    }

    public function verificationPublished(): bool
    {
        return $this->status === 'SETTLED'
            && $this->revealedWinningPath !== null
            && $this->revealedSecretNonceHex !== null
            && $this->verificationPublishedAt !== null;
    }
}
