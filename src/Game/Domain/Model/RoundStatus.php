<?php

declare(strict_types=1);

namespace App\Game\Domain\Model;

enum RoundStatus: string
{
    case Preparing = 'PREPARING';
    case Active = 'ACTIVE';
    case Won = 'WON';
    case Settled = 'SETTLED';
    case Cancelled = 'CANCELLED';
}
