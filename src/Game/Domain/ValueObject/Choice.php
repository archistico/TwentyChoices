<?php

declare(strict_types=1);

namespace App\Game\Domain\ValueObject;

enum Choice: string
{
    case A = 'A';
    case B = 'B';

    public function bit(): int
    {
        return $this === self::A ? 0 : 1;
    }

    public static function fromBit(int $bit): self
    {
        return match ($bit) {
            0 => self::A,
            1 => self::B,
            default => throw new \InvalidArgumentException('A choice bit must be 0 or 1.'),
        };
    }
}
