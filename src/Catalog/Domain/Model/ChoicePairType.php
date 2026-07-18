<?php

declare(strict_types=1);

namespace App\Catalog\Domain\Model;

enum ChoicePairType: string
{
    case Regular = 'REGULAR';
    case FinalDoor = 'FINAL_DOOR';
}
