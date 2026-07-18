<?php

declare(strict_types=1);

namespace App\Tests\Game\Domain;

use App\Game\Domain\Exception\DomainRuleViolation;
use App\Game\Domain\Model\GameRound;
use App\Game\Domain\Model\RoundStatus;
use App\Game\Domain\ValueObject\VirtualMoney;
use App\Game\Domain\ValueObject\WinningPath;
use PHPUnit\Framework\TestCase;

final class GameRoundTest extends TestCase
{
    public function testAWinningPathFreezesTheCurrentJackpot(): void
    {
        $path = WinningPath::fromBitString('10110001101001011100');
        $round = GameRound::prepare(
            'R-000001',
            hash('sha256', 'questions-v1'),
            $path,
            str_repeat("\x42", 32),
            VirtualMoney::euros(10_000),
        );

        $round->activate();
        $round->addStandardEntry();
        $round->addStandardEntry();
        $payout = $round->closeAsWon('G-WINNER', $path);

        self::assertSame(RoundStatus::Won, $round->status());
        self::assertSame(1_000_160, $payout->cents);
        self::assertSame('G-WINNER', $round->winnerPlayCode());
    }

    public function testALosingPathCannotCloseTheRound(): void
    {
        $round = GameRound::prepare(
            'R-000001',
            hash('sha256', 'questions-v1'),
            WinningPath::fromInt(1),
            str_repeat("\x42", 32),
            VirtualMoney::euros(10_000),
        );
        $round->activate();

        $this->expectException(DomainRuleViolation::class);
        $round->closeAsWon('G-LOSER', WinningPath::fromInt(2));
    }

    public function testTheSecretCannotBeRevealedBeforeWinning(): void
    {
        $round = GameRound::prepare(
            'R-000001',
            hash('sha256', 'questions-v1'),
            WinningPath::fromInt(1),
            str_repeat("\x42", 32),
            VirtualMoney::euros(10_000),
        );

        $this->expectException(DomainRuleViolation::class);
        $round->reveal();
    }
}
