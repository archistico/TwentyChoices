<?php

declare(strict_types=1);

namespace App\Simulation\Domain;

enum SimulationProfile: string
{
    case UNIFORM = 'UNIFORM';
    case FIXED_A_BIAS = 'FIXED_A_BIAS';
    case ALTERNATING_BIAS = 'ALTERNATING_BIAS';

    public function label(): string
    {
        return match ($this) {
            self::UNIFORM => 'Uniforme 50/50',
            self::FIXED_A_BIAS => 'Preferenza costante per A',
            self::ALTERNATING_BIAS => 'Preferenza alternata A/B',
        };
    }

    /** @return list<int> Probability of choosing A in basis points, one value per step. */
    public function probabilities(int $biasBasisPoints): array
    {
        $biasBasisPoints = $this === self::UNIFORM ? 5_000 : $biasBasisPoints;

        if ($biasBasisPoints < 5_000 || $biasBasisPoints > 9_500) {
            throw new \InvalidArgumentException('La probabilità A deve essere compresa tra 50,00% e 95,00%.');
        }

        return match ($this) {
            self::UNIFORM, self::FIXED_A_BIAS => array_fill(0, 20, $biasBasisPoints),
            self::ALTERNATING_BIAS => array_map(
                static fn (int $step): int => $step % 2 === 1 ? $biasBasisPoints : 10_000 - $biasBasisPoints,
                range(1, 20),
            ),
        };
    }
}
