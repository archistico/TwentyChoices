<?php

declare(strict_types=1);

namespace App\Audit\Application;

final readonly class AuditIntegrityReport
{
    public function __construct(
        public bool $valid,
        public int $eventCount,
        public ?int $firstInvalidSequence = null,
    ) {
    }
}
