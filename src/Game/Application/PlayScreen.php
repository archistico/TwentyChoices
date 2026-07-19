<?php

declare(strict_types=1);

namespace App\Game\Application;

use DateTimeImmutable;

final readonly class PlayScreen
{
    public function __construct(
        public string $playId,
        public string $playPublicCode,
        public string $roundId,
        public string $roundPublicCode,
        public int $participationNumber,
        public int $currentStep,
        public string $status,
        public int $currentJackpotCents,
        public bool $completed,
        public bool $restartCreditAvailable,
        public ?string $activeRoundPublicCode,
        public ?int $displayedStep,
        public ?string $category,
        public ?string $leftLabel,
        public ?string $leftValue,
        public ?string $rightLabel,
        public ?string $rightValue,
        public ?string $challengeToken,
        public ?string $requestId,
        public ?DateTimeImmutable $shownAt,
        public ?DateTimeImmutable $availableAt,
        public ?int $waitRemainingMilliseconds,
        public ?int $elapsedSinceShownMilliseconds,
        public ?string $verificationCode,
    ) {
    }

    public function jackpotFormatted(): string
    {
        return number_format($this->currentJackpotCents / 100, 2, ',', '.').' €';
    }


}
