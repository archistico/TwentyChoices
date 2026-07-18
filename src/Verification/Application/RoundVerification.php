<?php

declare(strict_types=1);

namespace App\Verification\Application;

final readonly class RoundVerification
{
    public function __construct(
        public bool $available,
        public bool $commitmentMatches,
        public ?string $winningPath,
        public ?string $nonceHex,
        public ?string $calculatedCommitment,
    ) {
    }
}
