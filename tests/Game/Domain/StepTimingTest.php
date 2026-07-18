<?php

declare(strict_types=1);

namespace App\Tests\Game\Domain;

use App\Game\Domain\Exception\DomainRuleViolation;
use App\Game\Domain\ValueObject\StepTiming;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;

final class StepTimingTest extends TestCase
{
    public function testAChoiceBecomesAvailableAfterExactlyTwoSeconds(): void
    {
        $shown = new DateTimeImmutable('2026-07-18 12:00:00.250000 UTC');
        $timing = StepTiming::start($shown);

        self::assertFalse($timing->isAvailableAt($shown->modify('+1 second')->modify('+999000 microseconds')));
        self::assertTrue($timing->isAvailableAt($shown->modify('+2 seconds')));
        self::assertSame(2_000, $timing->remainingMillisecondsAt($shown));
        self::assertSame(0, $timing->remainingMillisecondsAt($shown->modify('+2 seconds')));
    }

    public function testItRejectsAShorterPersistedDelay(): void
    {
        $shown = new DateTimeImmutable('2026-07-18 12:00:00 UTC');

        $this->expectException(DomainRuleViolation::class);
        StepTiming::reconstitute($shown, $shown->modify('+1 second')->modify('+999000 microseconds'));
    }
}
