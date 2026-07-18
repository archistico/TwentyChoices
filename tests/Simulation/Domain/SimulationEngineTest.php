<?php

declare(strict_types=1);

namespace App\Tests\Simulation\Domain;

use App\Simulation\Domain\SimulationEngine;
use App\Simulation\Domain\SimulationProfile;
use App\Simulation\Domain\SimulationRequest;
use PHPUnit\Framework\TestCase;

final class SimulationEngineTest extends TestCase
{
    public function testUniformSimulationIsDeterministicForTheSameSeed(): void
    {
        $engine = new SimulationEngine();
        $request = new SimulationRequest(5_000, SimulationProfile::UNIFORM, 5_000, 123456);

        $first = $engine->run($request);
        $second = $engine->run($request);

        self::assertSame($first->uniquePaths, $second->uniquePaths);
        self::assertSame($first->duplicatePlays, $second->duplicatePlays);
        self::assertSame($first->choiceStats, $second->choiceStats);
        self::assertSame($first->topPaths, $second->topPaths);
        self::assertSame(100_000, array_sum(array_column($first->choiceStats, 'optionACount')) + array_sum(array_column($first->choiceStats, 'optionBCount')));
    }

    public function testStrongBiasConcentratesPathsMoreThanUniform(): void
    {
        $engine = new SimulationEngine();
        $uniform = $engine->run(new SimulationRequest(20_000, SimulationProfile::UNIFORM, 5_000, 77));
        $biased = $engine->run(new SimulationRequest(20_000, SimulationProfile::FIXED_A_BIAS, 8_500, 77));

        self::assertGreaterThan($biased->uniquePaths, $uniform->uniquePaths);
        self::assertGreaterThan($uniform->duplicatePlays, $biased->duplicatePlays);
        self::assertGreaterThan($biased->effectivePathCount, $uniform->effectivePathCount);
        self::assertGreaterThan(1, $biased->maxPathHits);
    }

    public function testAlternatingProfileBuildsComplementaryStepProbabilities(): void
    {
        $probabilities = SimulationProfile::ALTERNATING_BIAS->probabilities(7_000);

        self::assertCount(20, $probabilities);
        self::assertSame(7_000, $probabilities[0]);
        self::assertSame(3_000, $probabilities[1]);
        self::assertSame(7_000, $probabilities[18]);
        self::assertSame(3_000, $probabilities[19]);
    }
}
