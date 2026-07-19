<?php

declare(strict_types=1);

final class PackageAudit
{
    /** @return list<string> */
    public function violations(string $projectRoot): array
    {
        $root = rtrim($projectRoot, '/\\');
        $violations = [];

        $forbiddenExact = [
            '.env.local',
            '.env.local.php',
            '.phpunit.result.cache',
            'phpunit.xml',
        ];
        foreach ($forbiddenExact as $relative) {
            if (file_exists($root.DIRECTORY_SEPARATOR.str_replace('/', DIRECTORY_SEPARATOR, $relative))) {
                $violations[] = $relative;
            }
        }

        foreach (glob($root.DIRECTORY_SEPARATOR.'.env.*.local') ?: [] as $path) {
            $violations[] = basename($path);
        }

        if (is_dir($root.DIRECTORY_SEPARATOR.'vendor')) {
            $violations[] = 'vendor/';
        }

        if (is_dir($root.DIRECTORY_SEPARATOR.'bin'.DIRECTORY_SEPARATOR.'.phpunit')) {
            $violations[] = 'bin/.phpunit/';
        }

        $varDirectory = $root.DIRECTORY_SEPARATOR.'var';
        if (is_dir($varDirectory)) {
            $iterator = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($varDirectory, FilesystemIterator::SKIP_DOTS),
                RecursiveIteratorIterator::LEAVES_ONLY,
            );
            foreach ($iterator as $file) {
                if (!$file->isFile()) {
                    continue;
                }

                $relative = 'var/'.str_replace('\\', '/', substr($file->getPathname(), strlen($varDirectory) + 1));
                if ($relative !== 'var/.gitignore') {
                    $violations[] = $relative;
                }
            }
        }

        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::LEAVES_ONLY,
        );
        foreach ($iterator as $file) {
            if (!$file->isFile()) {
                continue;
            }

            $name = strtolower($file->getFilename());
            if (preg_match('/\.(db|sqlite|sqlite3)(-wal|-shm|-journal)?$/', $name) === 1) {
                $relative = str_replace('\\', '/', substr($file->getPathname(), strlen($root) + 1));
                $violations[] = $relative;
            }
        }

        $envPath = $root.DIRECTORY_SEPARATOR.'.env';
        if (is_file($envPath)) {
            $env = (string) file_get_contents($envPath);
            if (preg_match('/^APP_SECRET\s*=\s*(.+)$/m', $env, $matches) === 1) {
                $value = trim($matches[1], " \t\n\r\0\x0B\"'");
                if ($value !== '' && $value !== 'change-this-development-secret') {
                    $violations[] = '.env contains a non-placeholder APP_SECRET';
                }
            }
        }

        $violations = array_values(array_unique($violations));
        sort($violations);

        return $violations;
    }
}
