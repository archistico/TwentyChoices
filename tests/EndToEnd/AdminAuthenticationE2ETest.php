<?php

declare(strict_types=1);

namespace App\Tests\EndToEnd;

use App\Security\Admin\AdminPasswordHasher;
use App\Security\Admin\AdminRole;
use App\Security\Admin\AdminUserRepository;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use App\Tests\Support\TransactionalWebTestCase;

final class AdminAuthenticationE2ETest extends TransactionalWebTestCase
{
    public function testSuperAdminCanLoginManageAndLogout(): void
    {
        $client = $this->createTransactionalClient();
        $username = 'super_'.substr(hash('sha256', __METHOD__), 0, 10);
        $this->ensureUser($username, AdminRole::SUPER_ADMIN);

        $this->login($client, $username);
        $client->followRedirect();
        self::assertSelectorTextContains('body', $username);

        $client->request('GET', '/admin/utenti');
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h1', 'Amministratori');

        $crawler = $client->request('GET', '/admin');
        $logout = $crawler->filter('form.logout-form')->form();
        $client->submit($logout);
        self::assertTrue($client->getResponse()->isRedirect('/admin/login'));
    }

    public function testAuditorCanReadDiagnosticsButCannotOperateRounds(): void
    {
        $client = $this->createTransactionalClient();
        $username = 'auditor_'.substr(hash('sha256', __METHOD__), 0, 10);
        $this->ensureUser($username, AdminRole::AUDITOR);
        $this->login($client, $username);

        $client->request('GET', '/admin/diagnostica');
        self::assertResponseIsSuccessful();
        $client->request('GET', '/admin/round');
        self::assertSame(403, $client->getResponse()->getStatusCode());
        self::assertStringContainsString('Operazione non autorizzata', (string) $client->getResponse()->getContent());
    }

    public function testOperatorCanOperateRoundsButCannotReadDiagnosticsOrUsers(): void
    {
        $client = $this->createTransactionalClient();
        $username = 'operator_'.substr(hash('sha256', __METHOD__), 0, 10);
        $this->ensureUser($username, AdminRole::OPERATOR);
        $this->login($client, $username);

        $client->request('GET', '/admin/round');
        self::assertResponseIsSuccessful();
        $client->request('GET', '/admin/diagnostica');
        self::assertSame(403, $client->getResponse()->getStatusCode());
        $client->request('GET', '/admin/utenti');
        self::assertSame(403, $client->getResponse()->getStatusCode());
    }


    public function testPasswordChangeInvalidatesExistingAdminSession(): void
    {
        $client = $this->createTransactionalClient();
        $username = 'revoke_'.substr(hash('sha256', __METHOD__), 0, 10);
        $this->ensureUser($username, AdminRole::AUDITOR);
        $this->login($client, $username);

        $users = self::getContainer()->get(AdminUserRepository::class);
        $hasher = self::getContainer()->get(AdminPasswordHasher::class);
        $row = $users->findByUsername($username);
        self::assertIsArray($row);
        $users->updatePassword((string) $row['id'], $hasher->hash('TwentyChoices2027!'));

        $client->request('GET', '/admin/diagnostica');
        self::assertTrue($client->getResponse()->isRedirect('/admin/login'));
    }

    private function ensureUser(string $username, AdminRole $role): void
    {
        $container = self::getContainer();
        $users = $container->get(AdminUserRepository::class);
        if ($users->findByUsername($username) === null) {
            $hasher = $container->get(AdminPasswordHasher::class);
            $users->create($username, $hasher->hash('TwentyChoices2026!'), $role);
        }
    }

    private function login(KernelBrowser $client, string $username): void
    {
        self::getContainer()->get(Connection::class)->executeStatement("DELETE FROM request_rate_limit WHERE scope = 'admin_login_ip'");
        $crawler = $client->request('GET', '/admin/login');
        $form = $crawler->selectButton('Accedi')->form([
            'username' => $username,
            'password' => 'TwentyChoices2026!',
        ]);
        $client->submit($form);
        self::assertTrue($client->getResponse()->isRedirect('/admin'));
    }
}
