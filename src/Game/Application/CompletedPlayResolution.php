<?php

declare(strict_types=1);

namespace App\Game\Application;

final readonly class CompletedPlayResolution
{
    public function __construct(
        public string $outcome,
        public ?int $frozenJackpotCents = null,
        public ?string $nextRoundPublicCode = null,
        public int $interruptedPlayCount = 0,
    ) {
    }

    public function won(): bool
    {
        return $this->outcome === 'WON';
    }
}
