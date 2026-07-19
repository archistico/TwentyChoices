<?php

declare(strict_types=1);

final class TestDatabaseReset
{
    public static function reset(string $databasePath): void
    {
        $directory = dirname($databasePath);
        if (!is_dir($directory) && !mkdir($directory, 0775, true) && !is_dir($directory)) {
            throw new RuntimeException('Unable to create directory for the test database: '.$directory);
        }

        foreach ([$databasePath, $databasePath.'-wal', $databasePath.'-shm', $databasePath.'-journal'] as $path) {
            if (is_file($path) && !unlink($path)) {
                throw new RuntimeException('Unable to remove stale test database file: '.$path);
            }
        }
    }
}
