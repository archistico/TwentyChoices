<?php

declare(strict_types=1);

namespace App\Game\Application;

use DateTimeImmutable;

final readonly class OpenedRound
{
    public function __construct(
        public string $id,
        public string $publicCode,
        public string $questionSetHash,
        public string $commitment,
        public int $initialJackpotCents,
        public DateTimeImmutable $startedAt,
        public string $cipherAlgorithm,
    ) {
    }
}
