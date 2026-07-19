<?php

declare(strict_types=1);

namespace App\Infrastructure\Bootstrap;

use App\Infrastructure\Database\SqliteRuntimeConfigurator;
use Doctrine\DBAL\Connection;

final readonly class InstallationVerifier
{
    public function __construct(
        private Connection $connection,
        private SqliteRuntimeConfigurator $sqlite,
        private string $projectDir,
        private string $kernelEnvironment,
        private string $applicationSecret,
    ) {
    }

    /** @return array{status:string, checks:list<array{name:string,status:string,value:string,detail:string}>} */
    public function inspect(): array
    {
        $this->sqlite->configure();
        $checks = [];

        $databasePath = $this->databasePath();
        $expectedPath = $this->expectedDatabasePath();
        $checks[] = $this->check(
            'Database path',
            $databasePath !== null && $this->samePath($databasePath, $expectedPath),
            $databasePath ?? '(memory)',
            'Atteso: '.$expectedPath,
        );
        $separateConfiguration = $this->databaseConfigurationSeparatesEnvironments();
        $checks[] = $this->check(
            'Dev/test separation',
            $separateConfiguration,
            $separateConfiguration ? 'data.db / test.db' : 'invalid configuration',
            'Doctrine deve configurare var/data.db in dev e var/test.db in test.',
        );

        $quickCheck = strtolower((string) $this->connection->fetchOne('PRAGMA quick_check'));
        $checks[] = $this->check('SQLite quick_check', $quickCheck === 'ok', $quickCheck, 'Integrità strutturale SQLite.');

        $foreignKeys = (int) $this->connection->fetchOne('PRAGMA foreign_keys');
        $checks[] = $this->check('Foreign keys', $foreignKeys === 1, (string) $foreignKeys, 'PRAGMA foreign_keys deve essere 1.');

        $busyTimeout = (int) $this->connection->fetchOne('PRAGMA busy_timeout');
        $checks[] = $this->check('Busy timeout', $busyTimeout >= 5000, $busyTimeout.' ms', 'Atteso almeno 5000 ms.');

        $synchronous = (int) $this->connection->fetchOne('PRAGMA synchronous');
        $checks[] = $this->check('Synchronous', $synchronous >= 2, (string) $synchronous, 'Atteso FULL o livello più robusto.');

        $journalMode = strtolower((string) $this->connection->fetchOne('PRAGMA journal_mode'));
        $journalOk = $this->kernelEnvironment === 'test' || $journalMode === 'wal';
        $checks[] = $this->check(
            'Journal mode',
            $journalOk,
            $journalMode,
            $this->kernelEnvironment === 'test' ? 'In test WAL non è obbligatorio.' : 'Fuori dai test è richiesto WAL.',
        );

        [$expectedMigrations, $appliedMigrations] = $this->migrationVersions();
        $missing = array_values(array_diff($expectedMigrations, $appliedMigrations));
        $unexpected = array_values(array_diff($appliedMigrations, $expectedMigrations));
        $migrationOk = $missing === [] && $unexpected === [] && $expectedMigrations !== [];
        $migrationDetail = sprintf('%d attese, %d applicate', count($expectedMigrations), count($appliedMigrations));
        if ($missing !== []) {
            $migrationDetail .= '; mancanti: '.implode(', ', $missing);
        }
        if ($unexpected !== []) {
            $migrationDetail .= '; non riconosciute: '.implode(', ', $unexpected);
        }
        $checks[] = $this->check('Migrations complete', $migrationOk, $migrationDetail, 'Tutte e sole le migrazioni del progetto devono risultare applicate.');

        $regularCount = (int) $this->connection->fetchOne("SELECT COUNT(*) FROM choice_pair WHERE pair_type = 'REGULAR' AND is_active = 1");
        $finalDoorCount = (int) $this->connection->fetchOne("SELECT COUNT(*) FROM choice_pair WHERE pair_type = 'FINAL_DOOR' AND is_active = 1 AND is_system = 1");
        $checks[] = $this->check(
            'Catalog seed',
            $regularCount >= 19 && $finalDoorCount === 1,
            sprintf('%d regular active, %d final door', $regularCount, $finalDoorCount),
            'Servono almeno 19 coppie regolari attive e una sola porta finale di sistema.',
        );

        $secretOk = $this->kernelEnvironment === 'test'
            ? $this->applicationSecret !== ''
            : strlen($this->applicationSecret) >= 32 && $this->applicationSecret !== 'change-this-development-secret';
        $checks[] = $this->check(
            'Application secret',
            $secretOk,
            $secretOk ? 'configured' : 'invalid',
            $this->kernelEnvironment === 'test'
                ? 'Il test environment deve avere un secret esplicito e isolato.'
                : 'Fuori dai test APP_SECRET deve essere locale, non-placeholder e di almeno 32 caratteri.',
        );

        $status = in_array('error', array_column($checks, 'status'), true) ? 'error' : 'ok';

        return ['status' => $status, 'checks' => $checks];
    }

    /** @return array{list<string>,list<string>} */
    private function migrationVersions(): array
    {
        $expected = [];
        foreach (glob($this->projectDir.'/migrations/Version*.php') ?: [] as $path) {
            $expected[] = 'DoctrineMigrations\\'.pathinfo($path, PATHINFO_FILENAME);
        }
        sort($expected);

        try {
            $applied = array_map(
                static fn (mixed $version): string => (string) $version,
                $this->connection->fetchFirstColumn('SELECT version FROM doctrine_migration_versions ORDER BY version'),
            );
        } catch (\Throwable) {
            $applied = [];
        }
        sort($applied);

        return [$expected, $applied];
    }

    private function databasePath(): ?string
    {
        foreach ($this->connection->fetchAllAssociative('PRAGMA database_list') as $row) {
            if (($row['name'] ?? null) === 'main') {
                $path = (string) ($row['file'] ?? '');

                return $path === '' ? null : $path;
            }
        }

        return null;
    }

    private function expectedDatabasePath(): string
    {
        return $this->projectDir.'/var/'.($this->kernelEnvironment === 'test' ? 'test.db' : 'data.db');
    }

    private function databaseConfigurationSeparatesEnvironments(): bool
    {
        $path = $this->projectDir.'/config/packages/doctrine.yaml';
        if (!is_file($path)) {
            return false;
        }

        $configuration = (string) file_get_contents($path);

        return str_contains($configuration, "path: '%kernel.project_dir%/var/data.db'")
            && str_contains($configuration, 'when@test:')
            && str_contains($configuration, "path: '%kernel.project_dir%/var/test.db'");
    }

    private function samePath(string $left, string $right): bool
    {
        $normalize = static function (string $path): string {
            $path = str_replace('\\', '/', $path);
            $path = preg_replace('#/+#', '/', $path) ?? $path;

            return DIRECTORY_SEPARATOR === '\\' ? strtolower($path) : $path;
        };

        return $normalize($left) === $normalize($right);
    }

    /** @return array{name:string,status:string,value:string,detail:string} */
    private function check(string $name, bool $ok, string $value, string $detail): array
    {
        return [
            'name' => $name,
            'status' => $ok ? 'ok' : 'error',
            'value' => $value,
            'detail' => $detail,
        ];
    }
}
