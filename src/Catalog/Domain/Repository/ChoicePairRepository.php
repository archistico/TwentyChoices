<?php

declare(strict_types=1);

namespace App\Catalog\Domain\Repository;

use App\Catalog\Domain\Model\ChoicePair;

interface ChoicePairRepository
{
    /** @return list<ChoicePair> */
    public function findAll(): array;

    public function find(string $id): ?ChoicePair;

    public function findByCode(string $code): ?ChoicePair;

    /** @return list<ChoicePair> */
    public function findActiveRegular(int $limit = 19): array;

    /** @return list<ChoicePair> */
    public function findAllActiveRegular(): array;

    public function findFinalDoor(): ?ChoicePair;

    public function nextSortOrder(): int;

    public function save(ChoicePair $pair): void;

    public function remove(ChoicePair $pair): void;
}
