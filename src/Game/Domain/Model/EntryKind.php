<?php

declare(strict_types=1);

namespace App\Game\Domain\Model;

enum EntryKind: string
{
    case Standard = 'STANDARD';
    case RestartCredit = 'RESTART_CREDIT';
    case AdminTest = 'ADMIN_TEST';
}
