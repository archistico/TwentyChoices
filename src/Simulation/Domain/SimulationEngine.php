<?php

declare(strict_types=1);

namespace App\Simulation\Domain;

use App\Game\Domain\ValueObject\WinningPath;
use Random\Engine\Mt19937;
use Random\Randomizer;

final class SimulationEngine
{
    private const TOP_PATHS = 50;

    public function run(SimulationRequest $request): SimulationResult
    {
        $randomizer = new Randomizer(new Mt19937($request->seed));
        $probabilities = $request->profile->probabilities($request->biasBasisPoints);
        $aCounts = array_fill(0, 20, 0);
        $bCounts = array_fill(0, 20, 0);
        $seen = str_repeat("\0", intdiv(WinningPath::COMBINATIONS + 7, 8));
        /** @var array<int, int> $duplicateCounts */
        $duplicateCounts = [];
        $unique = 0;

        for ($play = 0; $play < $request->plays; ++$play) {
            if ($request->profile === SimulationProfile::UNIFORM) {
                $path = $randomizer->getInt(0, WinningPath::MAX_VALUE);
                for ($step = 0; $step < 20; ++$step) {
                    $bit = ($path >> (19 - $step)) & 1;
                    if ($bit === 0) {
                        ++$aCounts[$step];
                    } else {
                        ++$bCounts[$step];
                    }
                }
            } else {
                $path = 0;
                for ($step = 0; $step < 20; ++$step) {
                    $chooseA = $randomizer->getInt(1, 10_000) <= $probabilities[$step];
                    $path = ($path << 1) | ($chooseA ? 0 : 1);
                    if ($chooseA) {
                        ++$aCounts[$step];
                    } else {
                        ++$bCounts[$step];
                    }
                }
            }

            $byteIndex = intdiv($path, 8);
            $mask = 1 << ($path & 7);
            $byte = ord($seen[$byteIndex]);
            if (($byte & $mask) === 0) {
                $seen[$byteIndex] = chr($byte | $mask);
                ++$unique;
            } else {
                $duplicateCounts[$path] = ($duplicateCounts[$path] ?? 1) + 1;
            }
        }

        arsort($duplicateCounts, SORT_NUMERIC);
        $topPaths = [];
        foreach (array_slice($duplicateCounts, 0, self::TOP_PATHS, true) as $path => $hits) {
            $topPaths[] = [
                'pathValue' => (int) $path,
                'pathBits' => str_pad(decbin((int) $path), 20, '0', STR_PAD_LEFT),
                'hitCount' => $hits,
            ];
        }

        $entropyPenalty = 0.0;
        foreach ($duplicateCounts as $hits) {
            $entropyPenalty += $hits * log($hits, 2);
        }
        $entropy = log($request->plays, 2) - ($entropyPenalty / $request->plays);
        $effectivePathCount = (int) max(1, min(WinningPath::COMBINATIONS, round(2 ** $entropy)));
        $coveragePpm = (int) round(($unique / WinningPath::COMBINATIONS) * 1_000_000);

        $choiceStats = [];
        foreach ($probabilities as $index => $probability) {
            $choiceStats[] = [
                'step' => $index + 1,
                'probabilityABasisPoints' => $probability,
                'optionACount' => $aCounts[$index],
                'optionBCount' => $bCounts[$index],
            ];
        }

        return new SimulationResult(
            $request->plays,
            $unique,
            $request->plays - $unique,
            $coveragePpm,
            (int) round($entropy * 1_000),
            $effectivePathCount,
            $duplicateCounts === [] ? 1 : max($duplicateCounts),
            $choiceStats,
            $topPaths,
        );
    }
}
