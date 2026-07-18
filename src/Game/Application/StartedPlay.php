<?php

declare(strict_types=1);

namespace App\Game\Application;

final readonly class StartedPlay
{
    public function __construct(
        public string $id,
        public string $publicCode,
        public string $roundId,
        public string $roundPublicCode,
        public int $participationNumber,
        public int $currentJackpotCents,
        public bool $resumed,
    ) {
    }
}
