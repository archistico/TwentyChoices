<?php

declare(strict_types=1);

namespace App\Tests\Security;

use App\Tests\Support\TransactionalWebTestCase;

final class SecurityHttpTest extends TransactionalWebTestCase
{
    public function testSecurityHeadersUseStrictExternalStylePolicy(): void
    {
        $client = $this->createTransactionalClient();
        $client->request('GET', '/');
        $response = $client->getResponse();

        self::assertTrue($response->isSuccessful());
        self::assertSame('nosniff', $response->headers->get('X-Content-Type-Options'));
        self::assertSame('DENY', $response->headers->get('X-Frame-Options'));
        $csp = (string) $response->headers->get('Content-Security-Policy');
        self::assertStringContainsString("script-src 'self'", $csp);
        self::assertStringContainsString("style-src 'self'", $csp);
        self::assertStringNotContainsString("'unsafe-inline'", $csp);
        self::assertNotSame('', (string) $response->headers->get('X-Request-Id'));

        $client->request('GET', '/health');
        self::assertSame(200, $client->getResponse()->getStatusCode());
        $client->request('GET', '/ready');
        self::assertSame(200, $client->getResponse()->getStatusCode());
    }

    public function testAdminAreaRequiresAuthenticationEvenFromAllowedIp(): void
    {
        $client = $this->createTransactionalClient();
        $client->request('GET', '/admin/diagnostica');

        self::assertTrue($client->getResponse()->isRedirect('/admin/login'));
    }

    public function testAdminAreaRejectsNonAllowedRemoteAddressBeforeLogin(): void
    {
        $client = $this->createTransactionalClient();
        $client->request('GET', '/admin/login', [], [], ['REMOTE_ADDR' => '203.0.113.10']);

        self::assertSame(403, $client->getResponse()->getStatusCode());
        self::assertStringContainsString('no-store', (string) $client->getResponse()->headers->get('Cache-Control'));
        self::assertStringNotContainsString('203.0.113.10', (string) $client->getResponse()->getContent());
    }

    public function testNotFoundPageIsGeneric(): void
    {
        $client = $this->createTransactionalClient();
        $client->request('GET', '/pagina-che-non-esiste');
        self::assertSame(404, $client->getResponse()->getStatusCode());
        self::assertStringContainsString('Pagina non trovata', (string) $client->getResponse()->getContent());
        self::assertStringNotContainsString('No route found', (string) $client->getResponse()->getContent());
    }
}
