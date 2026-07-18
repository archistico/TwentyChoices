<?php

declare(strict_types=1);

namespace App\Tests\Security;

use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class SecurityHttpTest extends WebTestCase
{
    public function testSecurityHeadersAndRequestIdAreApplied(): void
    {
        $client = self::createClient();
        $client->request('GET', '/');
        $response = $client->getResponse();

        self::assertTrue($response->isSuccessful());
        self::assertSame('nosniff', $response->headers->get('X-Content-Type-Options'));
        self::assertSame('DENY', $response->headers->get('X-Frame-Options'));
        self::assertStringContainsString("script-src 'self'", (string) $response->headers->get('Content-Security-Policy'));
        self::assertNotSame('', (string) $response->headers->get('X-Request-Id'));

        $client->request('GET', '/health');
        self::assertSame(200, $client->getResponse()->getStatusCode());
        self::assertSame('ok', json_decode((string) $client->getResponse()->getContent(), true, flags: JSON_THROW_ON_ERROR)['status']);

        $client->request('GET', '/ready');
        self::assertSame(200, $client->getResponse()->getStatusCode());
        self::assertSame('ready', json_decode((string) $client->getResponse()->getContent(), true, flags: JSON_THROW_ON_ERROR)['status']);

        $client->request('GET', '/admin/diagnostica');
        self::assertSame(200, $client->getResponse()->getStatusCode());
    }

    public function testAdminAreaRejectsNonAllowedRemoteAddress(): void
    {
        $client = self::createClient();
        $client->request('GET', '/admin/diagnostica', [], [], ['REMOTE_ADDR' => '203.0.113.10']);

        self::assertSame(403, $client->getResponse()->getStatusCode());
        self::assertStringContainsString('no-store', (string) $client->getResponse()->headers->get('Cache-Control'));
    }
}
