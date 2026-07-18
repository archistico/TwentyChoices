<?php

declare(strict_types=1);

namespace App\Tests\Security;

use App\Security\Application\SecurityEventLogger;
use App\Tests\Support\FrozenClock;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;

final class SecurityEventLoggerTest extends TestCase
{
    public function testSensitiveContextIsRedactedBeforeWritingJsonl(): void
    {
        $root = sys_get_temp_dir().'/twentychoices-security-'.bin2hex(random_bytes(6));
        mkdir($root, 0700, true);
        $logger = new SecurityEventLogger(
            new FrozenClock(new DateTimeImmutable('2026-07-18 20:00:00 UTC')),
            $root,
        );

        $logger->log('TEST_EVENT', [
            'requestId' => 'req-1',
            'challengeToken' => 'must-not-leak',
            'nested' => ['secretNonce' => 'also-secret'],
        ]);

        $contents = (string) file_get_contents($root.'/var/log/security.jsonl');
        self::assertStringContainsString('TEST_EVENT', $contents);
        self::assertStringContainsString('[REDACTED]', $contents);
        self::assertStringNotContainsString('must-not-leak', $contents);
        self::assertStringNotContainsString('also-secret', $contents);

        @unlink($root.'/var/log/security.jsonl');
        @rmdir($root.'/var/log');
        @rmdir($root.'/var');
        @rmdir($root);
    }
}
