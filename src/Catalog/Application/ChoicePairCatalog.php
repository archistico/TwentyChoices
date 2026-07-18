<?php

declare(strict_types=1);

namespace App\Catalog\Application;

use App\Catalog\Domain\Model\ChoicePair;
use App\Catalog\Domain\Repository\ChoicePairRepository;
use App\Game\Domain\Exception\DomainRuleViolation;
use Symfony\Component\Uid\Ulid;

final readonly class ChoicePairCatalog
{
    public function __construct(private ChoicePairRepository $repository)
    {
    }

    /** @return list<ChoicePair> */
    public function all(): array
    {
        return $this->repository->findAll();
    }

    public function nextSortOrder(): int
    {
        return $this->repository->nextSortOrder();
    }

    public function get(string $id): ChoicePair
    {
        return $this->repository->find($id)
            ?? throw new DomainRuleViolation('The requested choice pair does not exist.');
    }

    public function create(
        string $code,
        string $optionA,
        string $optionB,
        string $category,
        ?int $sortOrder = null,
    ): ChoicePair {
        $this->assertCodeAvailable($code);

        $pair = ChoicePair::createRegular(
            (string) new Ulid(),
            $code,
            $optionA,
            $optionB,
            $category,
            $sortOrder ?? $this->repository->nextSortOrder(),
        );
        $this->repository->save($pair);

        return $pair;
    }

    public function update(
        string $id,
        string $code,
        string $optionA,
        string $optionB,
        string $category,
        int $sortOrder,
    ): ChoicePair {
        $pair = $this->get($id);
        $other = $this->repository->findByCode($code);
        if ($other !== null && $other->id() !== $pair->id()) {
            throw new DomainRuleViolation('The selected code is already used by another pair.');
        }

        $pair->update($code, $optionA, $optionB, $category, $sortOrder);
        $this->repository->save($pair);

        return $pair;
    }

    public function toggle(string $id): ChoicePair
    {
        $pair = $this->get($id);
        if ($pair->isActive()) {
            $this->assertCanRemoveActiveRegular($pair);
            $pair->deactivate();
        } else {
            $pair->activate();
        }

        $this->repository->save($pair);

        return $pair;
    }

    public function delete(string $id): void
    {
        $pair = $this->get($id);
        if ($pair->isActive()) {
            $this->assertCanRemoveActiveRegular($pair);
        }
        $this->repository->remove($pair);
    }


    private function assertCanRemoveActiveRegular(ChoicePair $pair): void
    {
        if ($pair->isSystem()) {
            return;
        }

        if (count($this->repository->findAllActiveRegular()) <= 19) {
            throw new DomainRuleViolation(
                'Devono rimanere almeno 19 coppie regolari attive per garantire il reset automatico del round.',
            );
        }
    }

    private function assertCodeAvailable(string $code): void
    {
        if ($this->repository->findByCode($code) !== null) {
            throw new DomainRuleViolation('The selected code is already used by another pair.');
        }
    }
}
