<?php

declare(strict_types=1);

namespace App\Infrastructure\Database;

use Doctrine\DBAL\Connection;
use Symfony\Component\Console\ConsoleEvents;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;

final class SqliteRuntimeConfigurator implements EventSubscriberInterface
{
    private bool $configured = false;

    public function __construct(
        private readonly Connection $connection,
        private readonly string $kernelEnvironment,
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        return [
            KernelEvents::REQUEST => ['onRuntimeStart', 120],
            ConsoleEvents::COMMAND => ['onRuntimeStart', 120],
        ];
    }

    public function onRuntimeStart(mixed $event = null): void
    {
        if ($event instanceof RequestEvent && $event->getRequest()->getPathInfo() === '/health') {
            return;
        }

        $this->configure();
    }

    public function configure(): void
    {
        if ($this->configured) {
            return;
        }

        $this->connection->executeStatement('PRAGMA foreign_keys = ON');
        $this->connection->executeStatement('PRAGMA busy_timeout = 5000');
        $this->connection->executeStatement('PRAGMA synchronous = FULL');

        if ($this->kernelEnvironment !== 'test') {
            $this->connection->fetchOne('PRAGMA journal_mode = WAL');
            $this->connection->executeStatement('PRAGMA wal_autocheckpoint = 1000');
        }

        $this->configured = true;
    }
}
