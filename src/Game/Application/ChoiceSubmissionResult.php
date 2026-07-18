<?php

declare(strict_types=1);

namespace App\Game\Application;

final readonly class ChoiceSubmissionResult
{
    public function __construct(
        public string $playPublicCode,
        public int $acceptedStep,
        public bool $completed,
        public bool $idempotentReplay,
        public ?string $outcome = null,
        public ?int $frozenJackpotCents = null,
        public ?string $nextRoundPublicCode = null,
        public int $interruptedPlayCount = 0,
    ) {
    }
}
