<?php

declare(strict_types=1);

namespace App\Security\Admin;

enum AdminRole: string
{
    case SUPER_ADMIN = 'SUPER_ADMIN';
    case OPERATOR = 'OPERATOR';
    case AUDITOR = 'AUDITOR';

    public function label(): string
    {
        return match ($this) {
            self::SUPER_ADMIN => 'Super amministratore',
            self::OPERATOR => 'Operatore',
            self::AUDITOR => 'Auditor',
        };
    }
}
