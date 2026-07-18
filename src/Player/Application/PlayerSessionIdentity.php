<?php

declare(strict_types=1);

namespace App\Player\Application;

final readonly class PlayerSessionIdentity
{
    public function __construct(
        public string $id,
        public string $rawToken,
        public bool $newlyCreated,
    ) {
    }
}
