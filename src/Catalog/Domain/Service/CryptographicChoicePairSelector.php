<?php

declare(strict_types=1);

namespace App\Catalog\Domain\Service;

use App\Catalog\Domain\Model\ChoicePair;
use App\Catalog\Domain\Model\ChoicePairType;
use App\Game\Domain\Exception\DomainRuleViolation;

final class CryptographicChoicePairSelector
{
    /**
     * @param list<ChoicePair> $availablePairs
     * @return list<ChoicePair>
     */
    public function select(array $availablePairs, int $count = 19): array
    {
        if ($count < 1) {
            throw new DomainRuleViolation('At least one regular choice pair must be selected.');
        }

        $eligible = [];
        $ids = [];
        foreach ($availablePairs as $pair) {
            if (!$pair instanceof ChoicePair) {
                throw new DomainRuleViolation('Every selectable item must be a ChoicePair.');
            }

            if ($pair->type() !== ChoicePairType::Regular || !$pair->isActive()) {
                continue;
            }

            if (isset($ids[$pair->id()])) {
                throw new DomainRuleViolation('The selectable catalog contains a duplicate pair.');
            }

            $ids[$pair->id()] = true;
            $eligible[] = $pair;
        }

        if (count($eligible) < $count) {
            throw new DomainRuleViolation(sprintf(
                'At least %d active regular pairs are required to open a round.',
                $count,
            ));
        }

        for ($index = count($eligible) - 1; $index > 0; --$index) {
            $other = random_int(0, $index);
            [$eligible[$index], $eligible[$other]] = [$eligible[$other], $eligible[$index]];
        }

        return array_slice($eligible, 0, $count);
    }
}
