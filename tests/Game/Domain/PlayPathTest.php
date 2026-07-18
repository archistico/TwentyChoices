<?php

declare(strict_types=1);

namespace App\Tests\Game\Domain;

use App\Game\Domain\Exception\DomainRuleViolation;
use App\Game\Domain\ValueObject\Choice;
use App\Game\Domain\ValueObject\PlayPath;
use App\Game\Domain\ValueObject\WinningPath;
use PHPUnit\Framework\TestCase;

final class PlayPathTest extends TestCase
{
    public function testOnlyACompleteEqualPathMatches(): void
    {
        $bits = '10110001101001011100';
        $playPath = new PlayPath();

        foreach (str_split($bits) as $bit) {
            $playPath->append(Choice::fromBit((int) $bit));
        }

        self::assertTrue($playPath->isComplete());
        self::assertTrue($playPath->matches(WinningPath::fromBitString($bits)));
        self::assertFalse($playPath->matches(WinningPath::fromBitString('00110001101001011100')));
    }

    public function testACompletedPathCannotGrowPastTwentyChoices(): void
    {
        $playPath = PlayPath::fromChoices(array_fill(0, WinningPath::STEPS, Choice::A));

        $this->expectException(DomainRuleViolation::class);
        $playPath->append(Choice::B);
    }
}
