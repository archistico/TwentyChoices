<?php

declare(strict_types=1);

namespace App\Game\Domain\ValueObject;

use App\Game\Domain\Exception\DomainRuleViolation;

final class PlayPath
{
    /** @var list<Choice> */
    private array $choices = [];

    /** @param list<Choice> $choices */
    public static function fromChoices(array $choices): self
    {
        $path = new self();

        foreach ($choices as $choice) {
            $path->append($choice);
        }

        return $path;
    }

    public function append(Choice $choice): void
    {
        if ($this->isComplete()) {
            throw new DomainRuleViolation('A completed path cannot accept more choices.');
        }

        $this->choices[] = $choice;
    }

    public function count(): int
    {
        return count($this->choices);
    }

    public function nextStep(): int
    {
        return $this->count() + 1;
    }

    public function isComplete(): bool
    {
        return $this->count() === WinningPath::STEPS;
    }

    public function toBitString(): string
    {
        return implode('', array_map(
            static fn (Choice $choice): string => (string) $choice->bit(),
            $this->choices,
        ));
    }

    public function matches(WinningPath $winningPath): bool
    {
        return $this->isComplete() && hash_equals($winningPath->toBitString(), $this->toBitString());
    }
}
