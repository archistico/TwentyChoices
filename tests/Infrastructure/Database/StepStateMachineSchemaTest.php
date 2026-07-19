<?php

declare(strict_types=1);

namespace App\Tests\Infrastructure\Database;

use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class StepStateMachineSchemaTest extends KernelTestCase
{
    private Connection $connection;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->connection = self::getContainer()->get(Connection::class);
    }

    public function testStepInsertTriggerUsesExactIntegerMicrosecondBoundary(): void
    {
        $sql = (string) $this->connection->fetchOne(
            "SELECT sql FROM sqlite_master WHERE type = 'trigger' AND name = 'trg_play_step_validate_insert'",
        );

        self::assertStringContainsString('2000000', $sql);
        self::assertStringContainsString("substr(NEW.available_at, 21, 6)", $sql);
        self::assertStringNotContainsString('julianday', strtolower($sql));
    }

    public function testRequestIdUniquenessIsScopedToTheOwningPlay(): void
    {
        $sql = (string) $this->connection->fetchOne(
            "SELECT sql FROM sqlite_master WHERE type = 'index' AND name = 'uniq_play_step_request'",
        );
        $normalized = preg_replace('/\\s+/', ' ', strtolower($sql));

        self::assertIsString($normalized);
        self::assertStringContainsString('(play_id, request_id)', $normalized);
        self::assertStringContainsString('where request_id is not null', $normalized);
    }
}
