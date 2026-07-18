<?php

declare(strict_types=1);

namespace App\Simulation\Application;

use App\Shared\Time\Clock;
use App\Simulation\Domain\SimulationEngine;
use App\Simulation\Domain\SimulationRequest;
use Doctrine\DBAL\Connection;
use Symfony\Component\Uid\Ulid;
use Throwable;

final readonly class RunSimulation
{
    public function __construct(
        private Connection $connection,
        private SimulationEngine $engine,
        private Clock $clock,
    ) {
    }

    public function run(SimulationRequest $request): string
    {
        $startedAt = $this->clock->now();
        $timerStart = hrtime(true);
        $result = $this->engine->run($request);
        $durationMs = (int) round((hrtime(true) - $timerStart) / 1_000_000);
        $completedAt = $this->clock->now();
        $id = (string) new Ulid();
        $publicCode = sprintf('S-%s-%s', $startedAt->format('Ymd'), strtoupper(bin2hex(random_bytes(6))));

        $this->connection->beginTransaction();
        try {
            $this->connection->insert('simulation_run', [
                'id' => $id,
                'public_code' => $publicCode,
                'profile' => $request->profile->value,
                'bias_basis_points' => $request->profile->value === 'UNIFORM' ? 5_000 : $request->biasBasisPoints,
                'requested_plays' => $request->plays,
                'completed_plays' => $result->plays,
                'random_seed' => $request->seed,
                'unique_paths' => $result->uniquePaths,
                'duplicate_plays' => $result->duplicatePlays,
                'coverage_ppm' => $result->coveragePpm,
                'shannon_entropy_millibits' => $result->shannonEntropyMillibits,
                'effective_path_count' => $result->effectivePathCount,
                'max_path_hits' => $result->maxPathHits,
                'duration_ms' => $durationMs,
                'started_at' => self::formatDate($startedAt),
                'completed_at' => self::formatDate($completedAt),
            ]);

            foreach ($result->choiceStats as $stat) {
                $this->connection->insert('simulation_choice_stat', [
                    'run_id' => $id,
                    'step_number' => $stat['step'],
                    'probability_a_basis_points' => $stat['probabilityABasisPoints'],
                    'option_a_count' => $stat['optionACount'],
                    'option_b_count' => $stat['optionBCount'],
                ]);
            }

            foreach ($result->topPaths as $index => $path) {
                $this->connection->insert('simulation_path_stat', [
                    'run_id' => $id,
                    'rank_number' => $index + 1,
                    'path_value' => $path['pathValue'],
                    'path_bits' => $path['pathBits'],
                    'hit_count' => $path['hitCount'],
                ]);
            }

            $this->connection->commit();
        } catch (Throwable $exception) {
            if ($this->connection->isTransactionActive()) {
                $this->connection->rollBack();
            }
            throw $exception;
        }

        return $publicCode;
    }

    private static function formatDate(\DateTimeImmutable $date): string
    {
        return $date->format('Y-m-d H:i:s.u');
    }
}
