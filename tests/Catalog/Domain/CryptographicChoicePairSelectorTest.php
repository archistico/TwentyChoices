<?php

declare(strict_types=1);

namespace App\Tests\Catalog\Domain;

use App\Catalog\Domain\Model\ChoicePair;
use App\Catalog\Domain\Service\CryptographicChoicePairSelector;
use App\Game\Domain\Exception\DomainRuleViolation;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;

final class CryptographicChoicePairSelectorTest extends TestCase
{
    public function testItSelectsNineteenDistinctActiveRegularPairs(): void
    {
        $pairs = [];
        $now = new DateTimeImmutable('2026-07-18 12:00:00 UTC');
        for ($index = 1; $index <= 25; ++$index) {
            $pairs[] = ChoicePair::createRegular(
                'PAIR-'.$index,
                'pair-'.$index,
                'A '.$index,
                'B '.$index,
                'Test',
                $index * 10,
                $now,
            );
        }

        $selected = (new CryptographicChoicePairSelector())->select($pairs);
        $ids = array_map(static fn (ChoicePair $pair): string => $pair->id(), $selected);

        self::assertCount(19, $selected);
        self::assertCount(19, array_unique($ids));
    }

    public function testItRejectsAnInsufficientCatalog(): void
    {
        $pair = ChoicePair::createRegular('PAIR-1', 'pair-1', 'A', 'B', 'Test', 10);

        $this->expectException(DomainRuleViolation::class);
        (new CryptographicChoicePairSelector())->select([$pair]);
    }
}
