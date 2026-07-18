<?php

declare(strict_types=1);

namespace App\Game\Domain\ValueObject;

use App\Game\Domain\Exception\DomainRuleViolation;
use DateTimeImmutable;

final readonly class StepTiming
{
    public const WAIT_SECONDS = 2;

    private function __construct(
        public DateTimeImmutable $shownAt,
        public DateTimeImmutable $availableAt,
    ) {
        if ($availableAt < $shownAt->modify('+'.self::WAIT_SECONDS.' seconds')) {
            throw new DomainRuleViolation('A choice must remain unavailable for at least two seconds.');
        }
    }

    public static function start(DateTimeImmutable $shownAt): self
    {
        return new self($shownAt, $shownAt->modify('+'.self::WAIT_SECONDS.' seconds'));
    }

    public static function reconstitute(DateTimeImmutable $shownAt, DateTimeImmutable $availableAt): self
    {
        return new self($shownAt, $availableAt);
    }

    public function isAvailableAt(DateTimeImmutable $instant): bool
    {
        return $instant >= $this->availableAt;
    }

    public function remainingMillisecondsAt(DateTimeImmutable $instant): int
    {
        if ($this->isAvailableAt($instant)) {
            return 0;
        }

        $availableMicros = ((int) $this->availableAt->format('U')) * 1_000_000
            + (int) $this->availableAt->format('u');
        $instantMicros = ((int) $instant->format('U')) * 1_000_000
            + (int) $instant->format('u');

        return (int) ceil(($availableMicros - $instantMicros) / 1_000);
    }
}
