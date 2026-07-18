<?php

declare(strict_types=1);

namespace App\Tests\Catalog\Domain;

use App\Catalog\Domain\Model\ChoicePair;
use App\Catalog\Domain\Model\ChoicePairType;
use App\Game\Domain\Exception\DomainRuleViolation;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;

final class ChoicePairTest extends TestCase
{
    public function testItNormalizesAndUpdatesARegularPair(): void
    {
        $createdAt = new DateTimeImmutable('2026-07-18 10:00:00');
        $updatedAt = new DateTimeImmutable('2026-07-18 11:00:00');
        $pair = ChoicePair::createRegular(
            'PAIR-1',
            '  mare-montagna  ',
            '  Mare  ',
            'Montagna',
            '  Natura  ',
            10,
            $createdAt,
        );

        $pair->update('mare-montagna', 'Oceano', 'Vetta', 'Paesaggi', 20, $updatedAt);

        self::assertSame('mare-montagna', $pair->code());
        self::assertSame('Oceano', $pair->optionAText());
        self::assertSame('Vetta', $pair->optionBText());
        self::assertSame('Paesaggi', $pair->category());
        self::assertSame(20, $pair->sortOrder());
        self::assertSame($updatedAt, $pair->updatedAt());
    }

    public function testARegularPairCanBeDeactivatedAndReactivated(): void
    {
        $pair = $this->regularPair();

        $pair->deactivate();
        self::assertFalse($pair->isActive());

        $pair->activate();
        self::assertTrue($pair->isActive());
    }

    public function testTheFinalDoorCannotBeDeactivated(): void
    {
        $door = $this->finalDoor();

        $this->expectException(DomainRuleViolation::class);
        $door->deactivate();
    }

    public function testTheFinalDoorCannotBeDeleted(): void
    {
        $door = $this->finalDoor();

        $this->expectException(DomainRuleViolation::class);
        $door->assertDeletable();
    }

    public function testTheTwoOptionsMustDiffer(): void
    {
        $this->expectException(DomainRuleViolation::class);

        ChoicePair::createRegular('PAIR-1', 'uguali', 'Mare', 'mare', 'Test', 10);
    }

    private function regularPair(): ChoicePair
    {
        return ChoicePair::createRegular('PAIR-1', 'mare-montagna', 'Mare', 'Montagna', 'Natura', 10);
    }

    private function finalDoor(): ChoicePair
    {
        $now = new DateTimeImmutable('2026-07-18 10:00:00');

        return ChoicePair::reconstitute(
            'FINAL-DOOR',
            'porta-finale',
            'Porta rossa',
            'Porta blu',
            'Finale',
            ChoicePairType::FinalDoor,
            true,
            true,
            10_000,
            $now,
            $now,
        );
    }
}
