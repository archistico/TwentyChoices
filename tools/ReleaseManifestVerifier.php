<?php

declare(strict_types=1);

final class ReleaseManifestVerifier
{
    /**
     * @return list<string>
     */
    public function violations(string $projectRoot): array
    {
        $root = rtrim($projectRoot, '/\\');
        $manifestPath = $root.DIRECTORY_SEPARATOR.'release-manifest.json';
        if (!is_file($manifestPath)) {
            return ['release-manifest.json is missing'];
        }

        try {
            /** @var mixed $decoded */
            $decoded = json_decode((string) file_get_contents($manifestPath), true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            return ['release-manifest.json is invalid JSON: '.$exception->getMessage()];
        }

        if (!is_array($decoded) || ($decoded['format'] ?? null) !== 1 || !is_array($decoded['files'] ?? null)) {
            return ['release-manifest.json has an unsupported structure'];
        }

        /** @var array<string, mixed> $files */
        $files = $decoded['files'];
        $violations = [];
        $normalizedManifestPaths = [];

        foreach ($files as $relative => $expectedHash) {
            if (!is_string($relative) || !is_string($expectedHash)) {
                $violations[] = 'release-manifest.json contains a non-string path/hash entry';
                continue;
            }

            $relative = $this->normalizeRelativePath($relative);
            $normalizedManifestPaths[$relative] = true;

            if (!$this->isValidRelativePath($relative)) {
                $violations[] = 'manifest path is invalid: '.$relative;
                continue;
            }

            if ($this->isRuntimeOrSecretPath($relative)) {
                $violations[] = 'manifest illegally includes runtime/secret path: '.$relative;
                continue;
            }

            if (preg_match('/^[a-f0-9]{64}$/', $expectedHash) !== 1) {
                $violations[] = 'manifest hash is invalid for: '.$relative;
                continue;
            }

            $absolute = $root.DIRECTORY_SEPARATOR.str_replace('/', DIRECTORY_SEPARATOR, $relative);
            if (!is_file($absolute)) {
                $violations[] = 'release file is missing: '.$relative;
                continue;
            }

            $actualHash = hash_file('sha256', $absolute);
            if (!is_string($actualHash) || !hash_equals($expectedHash, $actualHash)) {
                $violations[] = 'release file hash mismatch: '.$relative;
            }
        }

        foreach ($this->sourceFilesPresent($root) as $relative) {
            if ($relative === 'release-manifest.json') {
                continue;
            }

            if (!isset($normalizedManifestPaths[$relative])) {
                $violations[] = 'unexpected source file not present in release manifest: '.$relative;
            }
        }

        $violations = array_values(array_unique($violations));
        sort($violations);

        return $violations;
    }

    /**
     * @return list<string>
     */
    private function sourceFilesPresent(string $root): array
    {
        $files = [];
        $stack = [$root];
        $rootLength = strlen($root) + 1;

        while ($stack !== []) {
            $directory = array_pop($stack);
            if (!is_string($directory)) {
                continue;
            }

            $entries = scandir($directory);
            if ($entries === false) {
                continue;
            }

            foreach ($entries as $entry) {
                if ($entry === '.' || $entry === '..') {
                    continue;
                }

                $absolute = $directory.DIRECTORY_SEPARATOR.$entry;
                $relative = $this->normalizeRelativePath(substr($absolute, $rootLength));

                if (is_dir($absolute)) {
                    if ($this->isRuntimeDirectory($relative)) {
                        continue;
                    }

                    $stack[] = $absolute;
                    continue;
                }

                if (!is_file($absolute) || $this->isRuntimeOrSecretPath($relative)) {
                    continue;
                }

                $files[] = $relative;
            }
        }

        sort($files);

        return $files;
    }

    private function isRuntimeDirectory(string $relative): bool
    {
        return $relative === '.git'
            || str_starts_with($relative, '.git/')
            || $relative === 'vendor'
            || $relative === 'bin/.phpunit'
            || str_starts_with($relative, 'bin/.phpunit/')
            || $relative === 'var/cache'
            || str_starts_with($relative, 'var/cache/')
            || $relative === 'var/log'
            || str_starts_with($relative, 'var/log/')
            || $relative === 'var/composer-cache'
            || str_starts_with($relative, 'var/composer-cache/');
    }

    private function isRuntimeOrSecretPath(string $relative): bool
    {
        if ($relative === '.env.local' || $relative === '.env.local.php' || preg_match('/^\.env\..+\.local$/', $relative) === 1) {
            return true;
        }

        if ($relative === 'vendor' || str_starts_with($relative, 'vendor/')) {
            return true;
        }

        if ($relative === 'bin/.phpunit' || str_starts_with($relative, 'bin/.phpunit/')) {
            return true;
        }

        if (str_starts_with($relative, 'var/') && $relative !== 'var/.gitignore') {
            return true;
        }

        if ($relative === '.phpunit.result.cache' || $relative === 'phpunit.xml') {
            return true;
        }

        return preg_match('/\.(db|sqlite|sqlite3)(-wal|-shm|-journal)?$/i', basename($relative)) === 1;
    }

    private function isValidRelativePath(string $relative): bool
    {
        return $relative !== ''
            && !str_starts_with($relative, '/')
            && !preg_match('/^[A-Za-z]:\//', $relative)
            && !str_contains('/'.$relative.'/', '/../')
            && !str_contains($relative, "\0");
    }

    private function normalizeRelativePath(string $relative): string
    {
        return str_replace('\\', '/', $relative);
    }
}
