<?php

declare(strict_types=1);

namespace App\Tests\Catalog\Infrastructure;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class CatalogPersistenceTest extends KernelTestCase
{
    public function testTheSeedContainsEnoughRegularPairsAndOneFinalDoor(): void
    {
        self::bootKernel();
        $connection = self::getContainer()->get(Connection::class);

        self::assertSame(44, (int) $connection->fetchOne(<<<'SQL'
SELECT COUNT(*)
FROM choice_pair
WHERE pair_type = 'REGULAR'
  AND is_active = 1
SQL));
        self::assertSame(1, (int) $connection->fetchOne(<<<'SQL'
SELECT COUNT(*)
FROM choice_pair
WHERE pair_type = 'FINAL_DOOR'
  AND is_active = 1
  AND is_system = 1
SQL));
    }

    public function testSQLiteAlsoProtectsTheFinalDoor(): void
    {
        self::bootKernel();
        $connection = self::getContainer()->get(Connection::class);
        $finalDoorId = (string) $connection->fetchOne(
            "SELECT id FROM choice_pair WHERE pair_type = 'FINAL_DOOR'",
        );

        $this->expectException(Exception::class);
        $connection->executeStatement(
            'UPDATE choice_pair SET is_active = 0 WHERE id = :id',
            ['id' => $finalDoorId],
        );
    }
}
