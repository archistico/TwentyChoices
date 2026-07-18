<?php

declare(strict_types=1);

namespace App\Security\Application;

final readonly class RateLimitDecision
{
    public function __construct(
        public bool $allowed,
        public int $limit,
        public int $consumed,
        public int $remaining,
        public int $retryAfterSeconds,
    ) {
    }
}
