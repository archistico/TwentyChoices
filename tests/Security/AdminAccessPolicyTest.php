<?php

declare(strict_types=1);

namespace App\Tests\Security;

use App\Security\Admin\AdminAccessPolicy;
use App\Security\Admin\AdminIdentity;
use App\Security\Admin\AdminRole;
use PHPUnit\Framework\TestCase;

final class AdminAccessPolicyTest extends TestCase
{
    public function testRolesHaveExplicitDifferentPermissions(): void
    {
        $policy = new AdminAccessPolicy();
        $super = new AdminIdentity('1', 'super', AdminRole::SUPER_ADMIN, 1);
        $operator = new AdminIdentity('2', 'operator', AdminRole::OPERATOR, 1);
        $auditor = new AdminIdentity('3', 'auditor', AdminRole::AUDITOR, 1);

        self::assertTrue($policy->allows($super, 'admin_user_index'));
        self::assertTrue($policy->allows($operator, 'admin_round_open'));
        self::assertFalse($policy->allows($operator, 'admin_diagnostics_index'));
        self::assertTrue($policy->allows($auditor, 'admin_diagnostics_index'));
        self::assertTrue($policy->allows($auditor, 'admin_simulation_show'));
        self::assertFalse($policy->allows($auditor, 'admin_simulation_run'));
        self::assertFalse($policy->allows($auditor, 'admin_round_index'));
    }
}
