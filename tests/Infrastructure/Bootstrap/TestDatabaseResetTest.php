<?php

declare(strict_types=1);

namespace App\Tests\Infrastructure\Bootstrap;

use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 3).'/tools/TestDatabaseReset.php';

final class TestDatabaseResetTest extends TestCase
{
    public function testResetRemovesDatabaseAndSqliteSidecarsDeterministically(): void
    {
        $root = sys_get_temp_dir().'/twentychoices-db-reset-'.bin2hex(random_bytes(6));
        $database = $root.'/test.db';

        mkdir($root, 0775, true);
        file_put_contents($database, 'database');
        file_put_contents($database.'-wal', 'wal');
        file_put_contents($database.'-shm', 'shm');
        file_put_contents($database.'-journal', 'journal');

        try {
            \TestDatabaseReset::reset($database);
            \TestDatabaseReset::reset($database);

            self::assertFileDoesNotExist($database);
            self::assertFileDoesNotExist($database.'-wal');
            self::assertFileDoesNotExist($database.'-shm');
            self::assertFileDoesNotExist($database.'-journal');
        } finally {
            foreach ([$database, $database.'-wal', $database.'-shm', $database.'-journal'] as $path) {
                @unlink($path);
            }
            @rmdir($root);
        }
    }
}
