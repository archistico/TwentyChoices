<?php

declare(strict_types=1);

namespace App\Simulation\Domain;

final readonly class SimulationRequest
{
    public function __construct(
        public int $plays,
        public SimulationProfile $profile,
        public int $biasBasisPoints,
        public int $seed,
    ) {
        if ($plays < 1 || $plays > 1_000_000) {
            throw new \InvalidArgumentException('Il numero di giocate deve essere compreso tra 1 e 1.000.000.');
        }

        if ($seed < 0 || $seed > 2_147_483_647) {
            throw new \InvalidArgumentException('Il seed deve essere compreso tra 0 e 2.147.483.647.');
        }

        $profile->probabilities($biasBasisPoints);
    }
}
