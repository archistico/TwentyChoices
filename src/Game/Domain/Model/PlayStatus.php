<?php

declare(strict_types=1);

namespace App\Game\Domain\Model;

enum PlayStatus: string
{
    case Created = 'CREATED';
    case InProgress = 'IN_PROGRESS';
    case CompletedLost = 'COMPLETED_LOST';
    case CompletedWon = 'COMPLETED_WON';
    case Interrupted = 'INTERRUPTED';
    case Credited = 'CREDITED';
    case Expired = 'EXPIRED';
    case Cancelled = 'CANCELLED';
}
