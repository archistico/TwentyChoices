<?php

declare(strict_types=1);

namespace App\Tests\Security;

use App\Security\Admin\AdminPasswordHasher;
use App\Security\Admin\AdminRole;
use App\Security\Admin\AdminUserRepository;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class AdminUserPersistenceTest extends KernelTestCase
{
    public function testLastActiveSuperAdminCannotBeDemotedOrDisabled(): void
    {
        self::bootKernel();
        $container = self::getContainer();
        $connection = $container->get(Connection::class);
        $users = $container->get(AdminUserRepository::class);
        $hasher = $container->get(AdminPasswordHasher::class);

        $connection->beginTransaction();
        try {
            $activeSupers = $connection->fetchFirstColumn("SELECT id FROM admin_user WHERE role = 'SUPER_ADMIN' AND is_active = 1 ORDER BY id");
            if ($activeSupers === []) {
                $id = $users->create('guard_super', $hasher->hash('TwentyChoices2026!'), AdminRole::SUPER_ADMIN);
                $activeSupers = [$id];
            }
            $keeper = (string) array_pop($activeSupers);
            foreach ($activeSupers as $id) {
                $users->setActive((string) $id, false);
            }

            try {
                $users->updateRole($keeper, AdminRole::AUDITOR);
                self::fail('Il database ha consentito di declassare l ultimo SUPER_ADMIN.');
            } catch (\Throwable $exception) {
                self::assertStringContainsString('last active SUPER_ADMIN', $exception->getMessage());
            }

            try {
                $users->setActive($keeper, false);
                self::fail('Il database ha consentito di disattivare l ultimo SUPER_ADMIN.');
            } catch (\Throwable $exception) {
                self::assertStringContainsString('last active SUPER_ADMIN', $exception->getMessage());
            }
        } finally {
            $connection->rollBack();
        }
    }

    public function testPasswordChangeIncrementsAuthenticationVersion(): void
    {
        self::bootKernel();
        $container = self::getContainer();
        $connection = $container->get(Connection::class);
        $users = $container->get(AdminUserRepository::class);
        $hasher = $container->get(AdminPasswordHasher::class);

        $connection->beginTransaction();
        try {
            $username = 'version_'.substr(hash('sha256', __METHOD__), 0, 8);
            $id = $users->create($username, $hasher->hash('TwentyChoices2026!'), AdminRole::AUDITOR);
            $before = $users->findById($id);
            $users->updatePassword($id, $hasher->hash('TwentyChoices2027!'));
            $after = $users->findById($id);
            self::assertSame((int) $before['auth_version'] + 1, (int) $after['auth_version']);
        } finally {
            $connection->rollBack();
        }
    }
}
