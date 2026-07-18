<?php

declare(strict_types=1);

namespace App\Simulation\Domain;

final readonly class SimulationResult
{
    /**
     * @param list<array{step:int, probabilityABasisPoints:int, optionACount:int, optionBCount:int}> $choiceStats
     * @param list<array{pathValue:int, pathBits:string, hitCount:int}> $topPaths
     */
    public function __construct(
        public int $plays,
        public int $uniquePaths,
        public int $duplicatePlays,
        public int $coveragePpm,
        public int $shannonEntropyMillibits,
        public int $effectivePathCount,
        public int $maxPathHits,
        public array $choiceStats,
        public array $topPaths,
    ) {
    }

    public function coveragePercent(): float
    {
        return $this->coveragePpm / 10_000;
    }

    public function shannonEntropyBits(): float
    {
        return $this->shannonEntropyMillibits / 1_000;
    }
}
