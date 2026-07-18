<?php

declare(strict_types=1);

namespace App\Tests\Catalog\Infrastructure;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class CatalogPersistenceTest extends KernelTestCase
{
    private Connection $connection;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->connection = self::getContainer()->get(Connection::class);
        $this->connection->beginTransaction();
    }

    protected function tearDown(): void
    {
        if (isset($this->connection) && $this->connection->isTransactionActive()) {
            $this->connection->rollBack();
        }

        parent::tearDown();
    }

    public function testTheSeedContainsEnoughRegularPairsAndOneFinalDoor(): void
    {
        self::assertSame(44, (int) $this->connection->fetchOne(<<<'SQL'
SELECT COUNT(*)
FROM choice_pair
WHERE pair_type = 'REGULAR'
  AND is_active = 1
SQL));
        self::assertSame(1, (int) $this->connection->fetchOne(<<<'SQL'
SELECT COUNT(*)
FROM choice_pair
WHERE pair_type = 'FINAL_DOOR'
  AND is_active = 1
  AND is_system = 1
SQL));
    }

    public function testSQLiteAlsoProtectsTheFinalDoor(): void
    {
        $finalDoorId = (string) $this->connection->fetchOne(
            "SELECT id FROM choice_pair WHERE pair_type = 'FINAL_DOOR'",
        );

        $this->expectException(Exception::class);
        $this->connection->executeStatement(
            'UPDATE choice_pair SET is_active = 0 WHERE id = :id',
            ['id' => $finalDoorId],
        );
    }
    public function testSQLiteKeepsAtLeastNineteenRegularPairsActive(): void
    {
        $ids = $this->connection->fetchFirstColumn(<<<'SQL'
SELECT id
FROM choice_pair
WHERE pair_type = 'REGULAR'
  AND is_active = 1
ORDER BY sort_order, id
SQL);

        foreach (array_slice($ids, 0, 25) as $id) {
            $this->connection->executeStatement(
                'UPDATE choice_pair SET is_active = 0 WHERE id = :id',
                ['id' => $id],
            );
        }

        self::assertSame(19, (int) $this->connection->fetchOne(<<<'SQL'
SELECT COUNT(*)
FROM choice_pair
WHERE pair_type = 'REGULAR'
  AND is_active = 1
SQL));

        $this->expectException(Exception::class);
        $this->connection->executeStatement(
            'UPDATE choice_pair SET is_active = 0 WHERE id = :id',
            ['id' => $ids[25]],
        );
    }

}
