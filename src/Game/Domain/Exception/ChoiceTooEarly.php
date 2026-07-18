<?php

declare(strict_types=1);

namespace App\Game\Domain\Exception;

use DateTimeImmutable;

final class ChoiceTooEarly extends DomainRuleViolation
{
    public function __construct(
        public readonly DateTimeImmutable $availableAt,
        public readonly int $remainingMilliseconds,
    ) {
        parent::__construct(sprintf(
            'La scelta sarà disponibile tra %.1f secondi.',
            $remainingMilliseconds / 1_000,
        ));
    }
}
