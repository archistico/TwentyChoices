<?php

declare(strict_types=1);

namespace App\Tests\Game\Domain\Exception;

use App\Game\Domain\Exception\ChoiceTooEarly;
use App\Game\Domain\Exception\DomainRuleViolation;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;

final class ChoiceTooEarlyTest extends TestCase
{
    public function testItIsASpecializedDomainRuleViolation(): void
    {
        $availableAt = new DateTimeImmutable('2026-07-18 12:00:02.000000 UTC');
        $exception = new ChoiceTooEarly($availableAt, 1_250);

        self::assertInstanceOf(DomainRuleViolation::class, $exception);
        self::assertSame($availableAt, $exception->availableAt);
        self::assertSame(1_250, $exception->remainingMilliseconds);
        self::assertSame('La scelta sarà disponibile tra 1.2 secondi.', $exception->getMessage());
    }
}
