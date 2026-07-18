<?php

declare(strict_types=1);

namespace App\Game\Domain\ValueObject;

use App\Game\Domain\Exception\DomainRuleViolation;

final readonly class VirtualMoney
{
    private function __construct(public int $cents)
    {
        if ($cents < 0) {
            throw new DomainRuleViolation('Virtual money cannot be negative.');
        }
    }

    public static function fromCents(int $cents): self
    {
        return new self($cents);
    }

    public static function euros(int $euros): self
    {
        if ($euros < 0) {
            throw new DomainRuleViolation('Virtual euros cannot be negative.');
        }

        return new self($euros * 100);
    }

    public function add(self $other): self
    {
        return new self($this->cents + $other->cents);
    }

    public function subtract(self $other): self
    {
        if ($other->cents > $this->cents) {
            throw new DomainRuleViolation('The subtraction would make virtual money negative.');
        }

        return new self($this->cents - $other->cents);
    }

    public function format(string $decimalSeparator = ',', string $thousandsSeparator = '.'): string
    {
        return number_format($this->cents / 100, 2, $decimalSeparator, $thousandsSeparator).' €';
    }
}
