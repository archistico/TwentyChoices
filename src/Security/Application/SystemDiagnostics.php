<?php

declare(strict_types=1);

namespace App\Security\Application;

use App\Audit\Application\AuditIntegrityVerifier;
use App\Infrastructure\Database\SqliteRuntimeConfigurator;
use Doctrine\DBAL\Connection;

final readonly class SystemDiagnostics
{
    public function __construct(
        private Connection $connection,
        private SqliteRuntimeConfigurator $sqlite,
        private AuditIntegrityVerifier $audit,
        private string $projectDir,
        private string $kernelEnvironment,
    ) {
    }

    /** @return array{status:string, checks:list<array{name:string,status:string,value:string,detail:string}>} */
    public function inspect(): array
    {
        $this->sqlite->configure();
        $checks = [];

        $quickCheck = strtolower((string) $this->connection->fetchOne('PRAGMA quick_check'));
        $checks[] = $this->check('SQLite quick_check', $quickCheck === 'ok', $quickCheck, 'Integrità strutturale SQLite.');

        $foreignKeys = (int) $this->connection->fetchOne('PRAGMA foreign_keys');
        $checks[] = $this->check('Foreign keys', $foreignKeys === 1, (string) $foreignKeys, 'Deve essere 1 per ogni connessione runtime.');

        $busyTimeout = (int) $this->connection->fetchOne('PRAGMA busy_timeout');
        $checks[] = $this->check('Busy timeout', $busyTimeout >= 5000, $busyTimeout.' ms', 'Attesa minima per contesa tra writer SQLite.');

        $journalMode = strtolower((string) $this->connection->fetchOne('PRAGMA journal_mode'));
        $journalExpected = $this->kernelEnvironment === 'test' || $journalMode === 'wal';
        $checks[] = $this->check('Journal mode', $journalExpected, $journalMode, 'WAL è richiesto fuori dall’ambiente test.');

        $synchronous = (int) $this->connection->fetchOne('PRAGMA synchronous');
        $checks[] = $this->check('Durabilità synchronous', $synchronous >= 2, (string) $synchronous, '2 corrisponde a FULL nelle build SQLite standard.');

        $audit = $this->audit->verify();
        $checks[] = $this->check(
            'Catena audit',
            $audit->valid,
            $audit->eventCount.' eventi',
            $audit->valid ? 'Hash e continuità validi.' : 'Prima sequenza non valida: '.($audit->firstInvalidSequence ?? 0),
        );

        $databasePath = $this->databasePath();
        $databaseBytes = $databasePath !== null && is_file($databasePath) ? (int) filesize($databasePath) : 0;
        $checks[] = [
            'name' => 'Database SQLite',
            'status' => 'ok',
            'value' => $databasePath ?? '(memory)',
            'detail' => self::formatBytes($databaseBytes),
        ];

        $freeBytes = $databasePath !== null ? @disk_free_space(dirname($databasePath)) : false;
        $diskOk = $freeBytes === false || $freeBytes >= 100 * 1024 * 1024;
        $checks[] = [
            'name' => 'Spazio libero',
            'status' => $diskOk ? 'ok' : 'warning',
            'value' => $freeBytes === false ? 'n/d' : self::formatBytes((int) $freeBytes),
            'detail' => 'Warning sotto 100 MiB liberi.',
        ];

        $logDirectory = $this->projectDir.'/var/log';
        $logWritable = (is_dir($logDirectory) && is_writable($logDirectory))
            || (!is_dir($logDirectory) && is_writable(dirname($logDirectory)));
        $checks[] = $this->check('Log sicurezza', $logWritable, $logDirectory, 'Directory destinata a security.jsonl.');

        $adminCount = (int) $this->connection->fetchOne('SELECT COUNT(*) FROM admin_user');
        $checks[] = [
            'name' => 'Account amministrativi',
            'status' => $adminCount > 0 ? 'ok' : 'warning',
            'value' => (string) $adminCount,
            'detail' => $adminCount > 0
                ? 'È presente almeno un account amministrativo.'
                : 'Crea il primo account con app:admin:create prima di usare /admin.',
        ];

        $statuses = array_column($checks, 'status');
        $status = in_array('error', $statuses, true)
            ? 'error'
            : (in_array('warning', $statuses, true) ? 'warning' : 'ok');

        return ['status' => $status, 'checks' => $checks];
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

    private function databasePath(): ?string
    {
        foreach ($this->connection->fetchAllAssociative('PRAGMA database_list') as $row) {
            if (($row['name'] ?? null) === 'main') {
                $file = (string) ($row['file'] ?? '');

                return $file === '' ? null : $file;
            }
        }

        return null;
    }

    private static function formatBytes(int $bytes): string
    {
        if ($bytes < 1024) {
            return $bytes.' B';
        }
        if ($bytes < 1024 * 1024) {
            return number_format($bytes / 1024, 1, ',', '.').' KiB';
        }
        if ($bytes < 1024 * 1024 * 1024) {
            return number_format($bytes / (1024 * 1024), 1, ',', '.').' MiB';
        }

        return number_format($bytes / (1024 * 1024 * 1024), 1, ',', '.').' GiB';
    }
}
