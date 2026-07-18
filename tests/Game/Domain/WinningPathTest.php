<?php

declare(strict_types=1);

namespace App\Tests\Game\Domain;

use App\Game\Domain\Exception\DomainRuleViolation;
use App\Game\Domain\ValueObject\Choice;
use App\Game\Domain\ValueObject\WinningPath;
use PHPUnit\Framework\TestCase;

final class WinningPathTest extends TestCase
{
    /**
     * @dataProvider validPaths
     */
    public function testItRoundTripsValidValues(int $value, string $bits): void
    {
        $path = WinningPath::fromInt($value);

        self::assertSame($bits, $path->toBitString());
        self::assertSame($value, WinningPath::fromBitString($bits)->value);
    }

    public static function validPaths(): iterable
    {
        yield 'all A' => [0, '00000000000000000000'];
        yield 'all B' => [WinningPath::MAX_VALUE, '11111111111111111111'];
        yield 'mixed' => [727644, '10110001101001011100'];
    }

    public function testItReadsChoicesInHumanStepOrder(): void
    {
        $path = WinningPath::fromBitString('10000000000000000001');

        self::assertSame(Choice::B, $path->choiceAt(1));
        self::assertSame(Choice::A, $path->choiceAt(2));
        self::assertSame(Choice::B, $path->choiceAt(20));
    }

    public function testItRejectsOutOfRangeValues(): void
    {
        $this->expectException(DomainRuleViolation::class);
        WinningPath::fromInt(WinningPath::COMBINATIONS);
    }
}
