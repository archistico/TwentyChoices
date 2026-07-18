<?php

declare(strict_types=1);

namespace App\Security\Admin;

final class AdminAccessPolicy
{
    public function allows(AdminIdentity $identity, string $route): bool
    {
        if ($identity->role === AdminRole::SUPER_ADMIN) {
            return true;
        }

        if ($route === 'admin_dashboard' || $route === 'admin_logout') {
            return true;
        }

        if ($identity->role === AdminRole::OPERATOR) {
            return str_starts_with($route, 'admin_choice_pair_')
                || str_starts_with($route, 'admin_round_')
                || in_array($route, ['admin_simulation_index', 'admin_simulation_run', 'admin_simulation_show', 'admin_simulation_csv'], true);
        }

        if ($identity->role === AdminRole::AUDITOR) {
            return $route === 'admin_diagnostics_index'
                || in_array($route, ['admin_simulation_index', 'admin_simulation_show', 'admin_simulation_csv'], true);
        }

        return false;
    }
}
