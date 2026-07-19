<?php

declare(strict_types=1);

namespace App\Tests\Catalog\Application;

use App\Catalog\Application\ChoicePairCatalog;
use App\Catalog\Domain\Repository\ChoicePairRepository;
use App\Game\Domain\Exception\DomainRuleViolation;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class ChoicePairCatalogTest extends KernelTestCase
{
    private Connection $connection;
    private ChoicePairCatalog $catalog;
    private ChoicePairRepository $repository;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->connection = self::getContainer()->get(Connection::class);
        $this->catalog = self::getContainer()->get(ChoicePairCatalog::class);
        $this->repository = self::getContainer()->get(ChoicePairRepository::class);
        $this->connection->beginTransaction();
    }

    protected function tearDown(): void
    {
        if (isset($this->connection) && $this->connection->isTransactionActive()) {
            $this->connection->rollBack();
        }

        parent::tearDown();
    }

    public function testCatalogCrudPreservesCategoryOrderingAndActivationState(): void
    {
        $pair = $this->catalog->create(
            'm192-crud-test',
            'Prima A',
            'Prima B',
            'Categoria iniziale',
            9000,
        );

        $updated = $this->catalog->update(
            $pair->id(),
            'm192-crud-updated',
            'Seconda A',
            'Seconda B',
            'Categoria aggiornata',
            9010,
        );
        self::assertSame('m192-crud-updated', $updated->code());
        self::assertSame('Seconda A', $updated->optionAText());
        self::assertSame('Seconda B', $updated->optionBText());
        self::assertSame('Categoria aggiornata', $updated->category());
        self::assertSame(9010, $updated->sortOrder());

        self::assertFalse($this->catalog->toggle($pair->id())->isActive());
        self::assertTrue($this->catalog->toggle($pair->id())->isActive());

        $this->catalog->delete($pair->id());
        self::assertNull($this->repository->find($pair->id()));
    }

    public function testCatalogRejectsFinalDoorDeletionAtApplicationBoundary(): void
    {
        $finalDoor = $this->repository->findFinalDoor();
        self::assertNotNull($finalDoor);

        $this->expectException(DomainRuleViolation::class);
        $this->expectExceptionMessage('The mandatory final door cannot be deleted.');

        $this->catalog->delete($finalDoor->id());
    }
}
