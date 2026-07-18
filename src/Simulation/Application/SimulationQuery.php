<?php

declare(strict_types=1);

namespace App\Simulation\Application;

use DateTimeImmutable;
use DateTimeZone;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;

final readonly class SimulationQuery
{
    public function __construct(private Connection $connection)
    {
    }

    /** @return list<SimulationRunView> */
    public function recent(int $limit = 20): array
    {
        $rows = $this->connection->fetchAllAssociative(<<<'SQL'
SELECT *
FROM simulation_run
ORDER BY completed_at DESC, id DESC
LIMIT :limit
SQL, ['limit' => $limit], ['limit' => ParameterType::INTEGER]);

        return array_map(fn (array $row): SimulationRunView => $this->hydrate($row), $rows);
    }

    public function byPublicCode(string $publicCode): ?SimulationRunView
    {
        $row = $this->connection->fetchAssociative(
            'SELECT * FROM simulation_run WHERE public_code = :code LIMIT 1',
            ['code' => $publicCode],
        );
        if ($row === false) {
            return null;
        }

        $choiceRows = $this->connection->fetchAllAssociative(<<<'SQL'
SELECT step_number, probability_a_basis_points, option_a_count, option_b_count
FROM simulation_choice_stat
WHERE run_id = :runId
ORDER BY step_number
SQL, ['runId' => $row['id']]);
        $pathRows = $this->connection->fetchAllAssociative(<<<'SQL'
SELECT rank_number, path_value, path_bits, hit_count
FROM simulation_path_stat
WHERE run_id = :runId
ORDER BY rank_number
SQL, ['runId' => $row['id']]);

        return $this->hydrate(
            $row,
            array_map(static fn (array $stat): array => [
                'step' => (int) $stat['step_number'],
                'probabilityABasisPoints' => (int) $stat['probability_a_basis_points'],
                'optionACount' => (int) $stat['option_a_count'],
                'optionBCount' => (int) $stat['option_b_count'],
            ], $choiceRows),
            array_map(static fn (array $stat): array => [
                'rank' => (int) $stat['rank_number'],
                'pathValue' => (int) $stat['path_value'],
                'pathBits' => (string) $stat['path_bits'],
                'hitCount' => (int) $stat['hit_count'],
            ], $pathRows),
        );
    }

    /** @return array<string, int|float|null> */
    public function realGameMetrics(): array
    {
        $row = $this->connection->fetchAssociative(<<<'SQL'
SELECT
     (SELECT COUNT(*) FROM play) AS total_plays
    ,(SELECT COUNT(*) FROM play WHERE status = 'COMPLETED_WON') AS won_plays
    ,(SELECT COUNT(*) FROM play WHERE status = 'COMPLETED_LOST') AS lost_plays
    ,(SELECT COUNT(*) FROM play WHERE status IN ('INTERRUPTED', 'CREDITED')) AS interrupted_plays
    ,(SELECT COUNT(DISTINCT chosen_path_bits) FROM play WHERE current_step = 20) AS distinct_completed_paths
    ,(SELECT COALESCE(AVG((julianday(completed_at) - julianday(started_at)) * 86400.0), 0) FROM play WHERE completed_at IS NOT NULL) AS avg_play_seconds
    ,(SELECT COALESCE(AVG((julianday(settled_at) - julianday(started_at)) * 86400.0), 0) FROM game_round WHERE status = 'SETTLED' AND settled_at IS NOT NULL) AS avg_round_seconds
    ,(SELECT COALESCE(SUM(amount_cents), 0) FROM ledger_entry WHERE entry_type = 'JACKPOT_CONTRIBUTION') AS jackpot_contribution_cents
    ,(SELECT COALESCE(SUM(amount_cents), 0) FROM ledger_entry WHERE entry_type = 'ORGANIZER_SHARE') AS organizer_share_cents
SQL);

        return [
            'totalPlays' => (int) ($row['total_plays'] ?? 0),
            'wonPlays' => (int) ($row['won_plays'] ?? 0),
            'lostPlays' => (int) ($row['lost_plays'] ?? 0),
            'interruptedPlays' => (int) ($row['interrupted_plays'] ?? 0),
            'distinctCompletedPaths' => (int) ($row['distinct_completed_paths'] ?? 0),
            'avgPlaySeconds' => (float) ($row['avg_play_seconds'] ?? 0),
            'avgRoundSeconds' => (float) ($row['avg_round_seconds'] ?? 0),
            'jackpotContributionCents' => (int) ($row['jackpot_contribution_cents'] ?? 0),
            'organizerShareCents' => (int) ($row['organizer_share_cents'] ?? 0),
        ];
    }

    /**
     * @param list<array{step:int, probabilityABasisPoints:int, optionACount:int, optionBCount:int}> $choiceStats
     * @param list<array{rank:int, pathValue:int, pathBits:string, hitCount:int}> $topPaths
     */
    private function hydrate(array $row, array $choiceStats = [], array $topPaths = []): SimulationRunView
    {
        return new SimulationRunView(
            (string) $row['id'],
            (string) $row['public_code'],
            (string) $row['profile'],
            (int) $row['bias_basis_points'],
            (int) $row['completed_plays'],
            (int) $row['random_seed'],
            (int) $row['unique_paths'],
            (int) $row['duplicate_plays'],
            (int) $row['coverage_ppm'],
            (int) $row['shannon_entropy_millibits'],
            (int) $row['effective_path_count'],
            (int) $row['max_path_hits'],
            (int) $row['duration_ms'],
            new DateTimeImmutable((string) $row['started_at'], new DateTimeZone('UTC')),
            new DateTimeImmutable((string) $row['completed_at'], new DateTimeZone('UTC')),
            $choiceStats,
            $topPaths,
        );
    }
}
