<?php

declare(strict_types=1);

namespace App\Catalog\Infrastructure\Persistence;

use App\Catalog\Domain\Model\ChoicePair;
use App\Catalog\Domain\Model\ChoicePairType;
use App\Catalog\Domain\Repository\ChoicePairRepository;
use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;

final readonly class DoctrineDbalChoicePairRepository implements ChoicePairRepository
{
    public function __construct(private Connection $connection)
    {
    }

    public function findAll(): array
    {
        $rows = $this->connection->fetchAllAssociative(<<<'SQL'
SELECT
     id
    ,code
    ,option_a_text
    ,option_b_text
    ,category
    ,pair_type
    ,is_active
    ,is_system
    ,sort_order
    ,created_at
    ,updated_at
FROM choice_pair
ORDER BY
     CASE pair_type WHEN 'FINAL_DOOR' THEN 1 ELSE 0 END
    ,sort_order
    ,code
SQL);

        return array_map($this->hydrate(...), $rows);
    }

    public function find(string $id): ?ChoicePair
    {
        $row = $this->connection->fetchAssociative(
            'SELECT * FROM choice_pair WHERE id = :id',
            ['id' => $id],
        );

        return $row === false ? null : $this->hydrate($row);
    }

    public function findByCode(string $code): ?ChoicePair
    {
        $row = $this->connection->fetchAssociative(
            'SELECT * FROM choice_pair WHERE code = :code',
            ['code' => strtolower(trim($code))],
        );

        return $row === false ? null : $this->hydrate($row);
    }

    public function findActiveRegular(int $limit = 19): array
    {
        $rows = $this->connection->fetchAllAssociative(<<<'SQL'
SELECT *
FROM choice_pair
WHERE pair_type = 'REGULAR'
  AND is_active = 1
ORDER BY sort_order, code
LIMIT :limit
SQL, ['limit' => $limit], ['limit' => ParameterType::INTEGER]);

        return array_map($this->hydrate(...), $rows);
    }

    public function findFinalDoor(): ?ChoicePair
    {
        $row = $this->connection->fetchAssociative(<<<'SQL'
SELECT *
FROM choice_pair
WHERE pair_type = 'FINAL_DOOR'
LIMIT 1
SQL);

        return $row === false ? null : $this->hydrate($row);
    }

    public function nextSortOrder(): int
    {
        return (int) $this->connection->fetchOne(<<<'SQL'
SELECT COALESCE(MAX(sort_order), 0) + 10
FROM choice_pair
WHERE pair_type = 'REGULAR'
SQL);
    }

    public function save(ChoicePair $pair): void
    {
        $values = [
            'id' => $pair->id(),
            'code' => $pair->code(),
            'option_a_text' => $pair->optionAText(),
            'option_b_text' => $pair->optionBText(),
            'category' => $pair->category(),
            'pair_type' => $pair->type()->value,
            'is_active' => $pair->isActive() ? 1 : 0,
            'is_system' => $pair->isSystem() ? 1 : 0,
            'sort_order' => $pair->sortOrder(),
            'created_at' => $pair->createdAt()->format('Y-m-d H:i:s.u'),
            'updated_at' => $pair->updatedAt()->format('Y-m-d H:i:s.u'),
        ];

        if ($this->connection->fetchOne('SELECT 1 FROM choice_pair WHERE id = :id', ['id' => $pair->id()]) === false) {
            $this->connection->insert('choice_pair', $values);

            return;
        }

        unset($values['id'], $values['created_at']);
        $this->connection->update('choice_pair', $values, ['id' => $pair->id()]);
    }

    public function remove(ChoicePair $pair): void
    {
        $pair->assertDeletable();
        $this->connection->delete('choice_pair', ['id' => $pair->id()]);
    }

    /** @param array<string, mixed> $row */
    private function hydrate(array $row): ChoicePair
    {
        return ChoicePair::reconstitute(
            (string) $row['id'],
            (string) $row['code'],
            (string) $row['option_a_text'],
            (string) $row['option_b_text'],
            (string) $row['category'],
            ChoicePairType::from((string) $row['pair_type']),
            (bool) $row['is_active'],
            (bool) $row['is_system'],
            (int) $row['sort_order'],
            new DateTimeImmutable((string) $row['created_at']),
            new DateTimeImmutable((string) $row['updated_at']),
        );
    }
}
