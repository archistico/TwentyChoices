<?php

declare(strict_types=1);

namespace App\Catalog\Domain\Model;

use App\Game\Domain\Exception\DomainRuleViolation;
use DateTimeImmutable;

final class ChoicePair
{
    private function __construct(
        private readonly string $id,
        private string $code,
        private string $optionAText,
        private string $optionBText,
        private string $category,
        private ChoicePairType $type,
        private bool $active,
        private bool $system,
        private int $sortOrder,
        private readonly DateTimeImmutable $createdAt,
        private DateTimeImmutable $updatedAt,
    ) {
        $this->validate();
    }

    public static function createRegular(
        string $id,
        string $code,
        string $optionAText,
        string $optionBText,
        string $category,
        int $sortOrder,
        ?DateTimeImmutable $now = null,
    ): self {
        $now ??= new DateTimeImmutable();

        return new self(
            $id,
            self::normalizeCode($code),
            self::normalizeText($optionAText),
            self::normalizeText($optionBText),
            self::normalizeCategory($category),
            ChoicePairType::Regular,
            true,
            false,
            $sortOrder,
            $now,
            $now,
        );
    }

    public static function reconstitute(
        string $id,
        string $code,
        string $optionAText,
        string $optionBText,
        string $category,
        ChoicePairType $type,
        bool $active,
        bool $system,
        int $sortOrder,
        DateTimeImmutable $createdAt,
        DateTimeImmutable $updatedAt,
    ): self {
        return new self(
            $id,
            $code,
            $optionAText,
            $optionBText,
            $category,
            $type,
            $active,
            $system,
            $sortOrder,
            $createdAt,
            $updatedAt,
        );
    }

    public function update(
        string $code,
        string $optionAText,
        string $optionBText,
        string $category,
        int $sortOrder,
        ?DateTimeImmutable $now = null,
    ): void {
        if ($this->system && self::normalizeCode($code) !== $this->code) {
            throw new DomainRuleViolation('The code of a system choice pair cannot be changed.');
        }

        $this->code = self::normalizeCode($code);
        $this->optionAText = self::normalizeText($optionAText);
        $this->optionBText = self::normalizeText($optionBText);
        $this->category = self::normalizeCategory($category);
        $this->sortOrder = $sortOrder;
        $this->updatedAt = $now ?? new DateTimeImmutable();
        $this->validate();
    }

    public function activate(?DateTimeImmutable $now = null): void
    {
        $this->active = true;
        $this->updatedAt = $now ?? new DateTimeImmutable();
    }

    public function deactivate(?DateTimeImmutable $now = null): void
    {
        if ($this->type === ChoicePairType::FinalDoor || $this->system) {
            throw new DomainRuleViolation('The mandatory final door cannot be deactivated.');
        }

        $this->active = false;
        $this->updatedAt = $now ?? new DateTimeImmutable();
    }

    public function assertDeletable(): void
    {
        if ($this->type === ChoicePairType::FinalDoor || $this->system) {
            throw new DomainRuleViolation('The mandatory final door cannot be deleted.');
        }
    }

    public function id(): string
    {
        return $this->id;
    }

    public function code(): string
    {
        return $this->code;
    }

    public function optionAText(): string
    {
        return $this->optionAText;
    }

    public function optionBText(): string
    {
        return $this->optionBText;
    }

    public function category(): string
    {
        return $this->category;
    }

    public function type(): ChoicePairType
    {
        return $this->type;
    }

    public function isActive(): bool
    {
        return $this->active;
    }

    public function isSystem(): bool
    {
        return $this->system;
    }

    public function sortOrder(): int
    {
        return $this->sortOrder;
    }

    public function createdAt(): DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function updatedAt(): DateTimeImmutable
    {
        return $this->updatedAt;
    }

    private function validate(): void
    {
        if ($this->id === '') {
            throw new DomainRuleViolation('The choice pair identifier is required.');
        }

        if (!preg_match('/^[a-z0-9]+(?:-[a-z0-9]+)*$/D', $this->code) || strlen($this->code) > 60) {
            throw new DomainRuleViolation('The code must use lowercase letters, digits and single hyphens.');
        }

        if ($this->optionAText === '' || strlen($this->optionAText) > 120) {
            throw new DomainRuleViolation('Option A must contain between 1 and 120 characters.');
        }

        if ($this->optionBText === '' || strlen($this->optionBText) > 120) {
            throw new DomainRuleViolation('Option B must contain between 1 and 120 characters.');
        }

        if (strtolower($this->optionAText) === strtolower($this->optionBText)) {
            throw new DomainRuleViolation('The two options must be different.');
        }

        if ($this->category === '' || strlen($this->category) > 60) {
            throw new DomainRuleViolation('The category must contain between 1 and 60 characters.');
        }

        if ($this->sortOrder < 0) {
            throw new DomainRuleViolation('The sort order cannot be negative.');
        }

        if ($this->type === ChoicePairType::FinalDoor && (!$this->system || !$this->active)) {
            throw new DomainRuleViolation('The final door must be a permanent active system pair.');
        }
    }

    private static function normalizeCode(string $code): string
    {
        return strtolower(trim($code));
    }

    private static function normalizeText(string $text): string
    {
        return trim(preg_replace('/\s+/u', ' ', $text) ?? $text);
    }

    private static function normalizeCategory(string $category): string
    {
        return trim(preg_replace('/\s+/u', ' ', $category) ?? $category);
    }
}
