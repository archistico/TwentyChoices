<?php

declare(strict_types=1);

namespace App\Tests\Security;

use App\Security\Application\RequestRateLimiter;
use App\Shared\Time\SystemClock;
use App\Tests\Support\FrozenClock;
use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class RequestRateLimiterTest extends KernelTestCase
{
    private Connection $connection;
    private FrozenClock $clock;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->connection = self::getContainer()->get(Connection::class);
        $this->connection->beginTransaction();
        $this->clock = new FrozenClock(new DateTimeImmutable('2026-07-18 20:00:00 UTC'));
        self::getContainer()->set(SystemClock::class, $this->clock);
    }

    protected function tearDown(): void
    {
        if (isset($this->connection) && $this->connection->isTransactionActive()) {
            $this->connection->rollBack();
        }
        parent::tearDown();
    }

    public function testFixedWindowBlocksAfterConfiguredLimit(): void
    {
        $limiter = self::getContainer()->get(RequestRateLimiter::class);

        self::assertTrue($limiter->consume('test', 'subject-a', 2, 60)->allowed);
        self::assertTrue($limiter->consume('test', 'subject-a', 2, 60)->allowed);
        $blocked = $limiter->consume('test', 'subject-a', 2, 60);

        self::assertFalse($blocked->allowed);
        self::assertSame(3, $blocked->consumed);
        self::assertSame(0, $blocked->remaining);
        self::assertSame(60, $blocked->retryAfterSeconds);
    }

    public function testNewWindowResetsCounterAndStoredKeyIsHashed(): void
    {
        $limiter = self::getContainer()->get(RequestRateLimiter::class);
        $limiter->consume('test', 'raw-sensitive-subject', 1, 60);

        $stored = (string) $this->connection->fetchOne("SELECT key_hash FROM request_rate_limit WHERE scope = 'test'");
        self::assertSame(64, strlen($stored));
        self::assertStringNotContainsString('raw-sensitive-subject', $stored);

        $this->clock->advance('+61 seconds');
        self::assertTrue($limiter->consume('test', 'raw-sensitive-subject', 1, 60)->allowed);
    }
}
