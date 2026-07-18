<?php

declare(strict_types=1);

namespace App\Game\Application;

use DateTimeImmutable;
use DateTimeZone;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;

final readonly class RoundQuery
{
    public function __construct(private Connection $connection)
    {
    }

    public function active(): ?RoundOverview
    {
        $row = $this->connection->fetchAssociative(<<<'SQL'
SELECT
     r.id
    ,r.public_code
    ,r.status
    ,r.question_set_hash
    ,r.secret_commitment
    ,r.initial_jackpot_cents
    ,r.entry_contribution_cents
    ,r.frozen_jackpot_cents
    ,r.started_at
    ,r.won_at
    ,r.settled_at
    ,r.revealed_winning_path
    ,r.revealed_secret_nonce_hex
    ,r.verification_published_at
    ,winner.public_code AS winner_play_public_code
    ,COUNT(q.id) AS question_count
FROM game_round r
LEFT JOIN round_question q ON q.round_id = r.id
LEFT JOIN play winner ON winner.id = r.winner_play_id
WHERE r.status = 'ACTIVE'
GROUP BY r.id
LIMIT 1
SQL);

        return $row === false ? null : $this->hydrate($row);
    }

    public function byPublicCode(string $publicCode): ?RoundOverview
    {
        $row = $this->connection->fetchAssociative(<<<'SQL'
SELECT
     r.id
    ,r.public_code
    ,r.status
    ,r.question_set_hash
    ,r.secret_commitment
    ,r.initial_jackpot_cents
    ,r.entry_contribution_cents
    ,r.frozen_jackpot_cents
    ,r.started_at
    ,r.won_at
    ,r.settled_at
    ,r.revealed_winning_path
    ,r.revealed_secret_nonce_hex
    ,r.verification_published_at
    ,winner.public_code AS winner_play_public_code
    ,COUNT(q.id) AS question_count
FROM game_round r
LEFT JOIN round_question q ON q.round_id = r.id
LEFT JOIN play winner ON winner.id = r.winner_play_id
WHERE r.public_code = :publicCode
GROUP BY r.id
LIMIT 1
SQL, ['publicCode' => $publicCode]);

        return $row === false ? null : $this->hydrate($row);
    }

    /** @return list<RoundOverview> */
    public function recent(int $limit = 20): array
    {
        $rows = $this->connection->fetchAllAssociative(<<<'SQL'
SELECT
     r.id
    ,r.public_code
    ,r.status
    ,r.question_set_hash
    ,r.secret_commitment
    ,r.initial_jackpot_cents
    ,r.entry_contribution_cents
    ,r.frozen_jackpot_cents
    ,r.started_at
    ,r.won_at
    ,r.settled_at
    ,r.revealed_winning_path
    ,r.revealed_secret_nonce_hex
    ,r.verification_published_at
    ,winner.public_code AS winner_play_public_code
    ,COUNT(q.id) AS question_count
FROM game_round r
LEFT JOIN round_question q ON q.round_id = r.id
LEFT JOIN play winner ON winner.id = r.winner_play_id
GROUP BY r.id
ORDER BY COALESCE(r.started_at, '0000-01-01') DESC, r.id DESC
LIMIT :limit
SQL, ['limit' => $limit], ['limit' => ParameterType::INTEGER]);

        return array_map($this->hydrate(...), $rows);
    }

    /** @return list<array{step: int, optionA: string, optionB: string, category: string, type: string}> */
    public function questions(string $roundId): array
    {
        $rows = $this->connection->fetchAllAssociative(<<<'SQL'
SELECT
     step_number
    ,option_a_text_snapshot
    ,option_b_text_snapshot
    ,category_snapshot
    ,pair_type_snapshot
FROM round_question
WHERE round_id = :roundId
ORDER BY step_number
SQL, ['roundId' => $roundId]);

        return array_map(static fn (array $row): array => [
            'step' => (int) $row['step_number'],
            'optionA' => (string) $row['option_a_text_snapshot'],
            'optionB' => (string) $row['option_b_text_snapshot'],
            'category' => (string) $row['category_snapshot'],
            'type' => (string) $row['pair_type_snapshot'],
        ], $rows);
    }

    /** @param array<string, mixed> $row */
    private function hydrate(array $row): RoundOverview
    {
        return new RoundOverview(
            (string) $row['id'],
            (string) $row['public_code'],
            (string) $row['status'],
            (string) $row['question_set_hash'],
            (string) $row['secret_commitment'],
            (int) $row['initial_jackpot_cents'],
            (int) $row['entry_contribution_cents'],
            $row['frozen_jackpot_cents'] === null ? null : (int) $row['frozen_jackpot_cents'],
            self::dateOrNull($row['started_at']),
            self::dateOrNull($row['won_at']),
            self::dateOrNull($row['settled_at']),
            (int) $row['question_count'],
            $row['revealed_winning_path'] === null ? null : (string) $row['revealed_winning_path'],
            $row['revealed_secret_nonce_hex'] === null ? null : (string) $row['revealed_secret_nonce_hex'],
            self::dateOrNull($row['verification_published_at']),
            $row['winner_play_public_code'] === null ? null : (string) $row['winner_play_public_code'],
        );
    }

    private static function dateOrNull(mixed $value): ?DateTimeImmutable
    {
        return $value === null || $value === ''
            ? null
            : new DateTimeImmutable((string) $value, new DateTimeZone('UTC'));
    }
}
