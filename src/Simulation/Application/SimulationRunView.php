<?php

declare(strict_types=1);

namespace App\Simulation\Application;

use DateTimeImmutable;

final readonly class SimulationRunView
{
    /**
     * @param list<array{step:int, probabilityABasisPoints:int, optionACount:int, optionBCount:int}> $choiceStats
     * @param list<array{rank:int, pathValue:int, pathBits:string, hitCount:int}> $topPaths
     */
    public function __construct(
        public string $id,
        public string $publicCode,
        public string $profile,
        public int $biasBasisPoints,
        public int $plays,
        public int $seed,
        public int $uniquePaths,
        public int $duplicatePlays,
        public int $coveragePpm,
        public int $shannonEntropyMillibits,
        public int $effectivePathCount,
        public int $maxPathHits,
        public int $durationMs,
        public DateTimeImmutable $startedAt,
        public DateTimeImmutable $completedAt,
        public array $choiceStats = [],
        public array $topPaths = [],
    ) {
    }

    public function coveragePercentFormatted(): string
    {
        return number_format($this->coveragePpm / 10_000, 4, ',', '.').'%';
    }

    public function duplicatePercentFormatted(): string
    {
        return number_format(($this->duplicatePlays / $this->plays) * 100, 2, ',', '.').'%';
    }

    public function entropyFormatted(): string
    {
        return number_format($this->shannonEntropyMillibits / 1_000, 3, ',', '.').' bit';
    }

    public function biasPercentFormatted(): string
    {
        return number_format($this->biasBasisPoints / 100, 2, ',', '.').'%';
    }
}
