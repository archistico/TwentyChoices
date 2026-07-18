<?php

declare(strict_types=1);

namespace App\Game\Domain\ValueObject;

use App\Game\Domain\Exception\DomainRuleViolation;

final readonly class WinningPath
{
    public const STEPS = 20;
    public const COMBINATIONS = 1 << self::STEPS;
    public const MAX_VALUE = self::COMBINATIONS - 1;

    private function __construct(public int $value)
    {
        if ($value < 0 || $value > self::MAX_VALUE) {
            throw new DomainRuleViolation(sprintf(
                'The winning path must be between 0 and %d.',
                self::MAX_VALUE,
            ));
        }
    }

    public static function generate(): self
    {
        return new self(random_int(0, self::MAX_VALUE));
    }

    public static function fromInt(int $value): self
    {
        return new self($value);
    }

    public static function fromBitString(string $bits): self
    {
        if (!preg_match('/^[01]{20}$/D', $bits)) {
            throw new DomainRuleViolation('A winning path must contain exactly twenty binary digits.');
        }

        return new self((int) bindec($bits));
    }

    public function toBitString(): string
    {
        return str_pad(decbin($this->value), self::STEPS, '0', STR_PAD_LEFT);
    }

    public function choiceAt(int $step): Choice
    {
        if ($step < 1 || $step > self::STEPS) {
            throw new DomainRuleViolation('The step must be between 1 and 20.');
        }

        return Choice::fromBit((int) $this->toBitString()[$step - 1]);
    }

    public function equals(self $other): bool
    {
        return $this->value === $other->value;
    }
}
