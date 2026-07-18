<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Infrastructure\Database\SqliteRuntimeConfigurator;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

abstract class TransactionalWebTestCase extends WebTestCase
{
    private ?Connection $testTransactionConnection = null;

    protected function createTransactionalClient(array $options = [], array $server = []): KernelBrowser
    {
        $client = self::createClient($options, $server);

        // Keep the same service container for the whole browser journey. This is
        // required both for the outer DB transaction and for test doubles such as
        // FrozenClock that are injected directly into the test container.
        $client->disableReboot();

        // Configure SQLite before opening the outer test transaction. Some
        // PRAGMAs (notably synchronous) cannot be changed inside a transaction.
        // The request subscriber will then see the configurator as already run.
        self::getContainer()->get(SqliteRuntimeConfigurator::class)->configure();

        $connection = self::getContainer()->get(Connection::class);
        $connection->beginTransaction();
        $this->testTransactionConnection = $connection;

        return $client;
    }

    protected function testConnection(): Connection
    {
        if ($this->testTransactionConnection === null) {
            throw new \LogicException('The transactional browser client has not been created yet.');
        }

        return $this->testTransactionConnection;
    }

    protected function tearDown(): void
    {
        if ($this->testTransactionConnection !== null && $this->testTransactionConnection->isTransactionActive()) {
            $this->testTransactionConnection->rollBack();
        }

        $this->testTransactionConnection = null;

        parent::tearDown();
    }
}
