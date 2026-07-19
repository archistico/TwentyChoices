<?php

declare(strict_types=1);

require_once __DIR__.'/RuntimeBaselinePolicy.php';

final class BootstrapPreflight
{
    /** @return list<array{name:string,status:string,value:string,detail:string}> */
    public function inspect(string $projectRoot): array
    {
        $checks = [];

        $checks[] = $this->check(
            'PHP version',
            RuntimeBaselinePolicy::supportsPhpVersion(PHP_VERSION),
            PHP_VERSION,
            'TwentyChoices richiede PHP '.RuntimeBaselinePolicy::MINIMUM_PHP_VERSION.' o superiore.',
        );

        foreach (['ctype', 'iconv', 'PDO', 'pdo_sqlite'] as $extension) {
            $loaded = extension_loaded($extension);
            $checks[] = $this->check(
                'Extension '.$extension,
                $loaded,
                $loaded ? 'loaded' : 'missing',
                'Estensione PHP obbligatoria.',
            );
        }

        $pdoDrivers = class_exists(PDO::class) ? PDO::getAvailableDrivers() : [];
        $loadedIni = php_ini_loaded_file() ?: '(none)';
        $checks[] = $this->check(
            'PDO SQLite driver',
            in_array('sqlite', $pdoDrivers, true),
            $pdoDrivers === [] ? '(none)' : implode(', ', $pdoDrivers),
            sprintf(
                'Il PHP CLI deve esporre sqlite tramite PDO. PHP: %s; php.ini: %s. Abilitare pdo_sqlite e sqlite3.',
                PHP_BINARY,
                $loadedIni,
            ),
        );

        $sqliteOperational = false;
        $sqliteDetail = 'PDO SQLite non disponibile.';
        if (in_array('sqlite', $pdoDrivers, true)) {
            try {
                $pdo = new PDO('sqlite::memory:');
                $sqliteOperational = (int) $pdo->query('SELECT 1')->fetchColumn() === 1;
                $sqliteDetail = $sqliteOperational
                    ? 'Connessione SQLite in-memory riuscita.'
                    : 'La query di smoke test non ha restituito il valore atteso.';
            } catch (Throwable $exception) {
                $sqliteDetail = $exception->getMessage();
            }
        }
        $checks[] = $this->check(
            'SQLite operational',
            $sqliteOperational,
            $sqliteOperational ? 'ok' : 'failed',
            $sqliteDetail,
        );

        $cryptoBackend = $this->cryptoBackend();
        $checks[] = $this->check(
            'Authenticated crypto',
            $cryptoBackend !== null,
            $cryptoBackend ?? '(missing)',
            'Richiesto Sodium secretbox oppure OpenSSL AES-256-GCM.',
        );

        $varDirectory = rtrim($projectRoot, '/\\').DIRECTORY_SEPARATOR.'var';
        $varReady = $this->ensureWritableDirectory($varDirectory);
        $checks[] = $this->check(
            'Runtime directory',
            $varReady,
            $varDirectory,
            'La directory var deve essere creabile e scrivibile dal processo PHP.',
        );

        return $checks;
    }

    /** @param list<array{name:string,status:string,value:string,detail:string}> $checks */
    public function hasErrors(array $checks): bool
    {
        foreach ($checks as $check) {
            if ($check['status'] === 'error') {
                return true;
            }
        }

        return false;
    }

    private function cryptoBackend(): ?string
    {
        if (function_exists('sodium_crypto_secretbox')) {
            return 'sodium/secretbox';
        }

        if (function_exists('openssl_encrypt') && in_array('aes-256-gcm', openssl_get_cipher_methods(), true)) {
            return 'openssl/aes-256-gcm';
        }

        return null;
    }

    private function ensureWritableDirectory(string $directory): bool
    {
        if (!is_dir($directory) && !@mkdir($directory, 0775, true) && !is_dir($directory)) {
            return false;
        }

        if (!is_writable($directory)) {
            return false;
        }

        $probe = $directory.DIRECTORY_SEPARATOR.'.m1-9-1-write-probe-'.bin2hex(random_bytes(4));
        $written = @file_put_contents($probe, 'ok', LOCK_EX);
        if ($written === false) {
            return false;
        }

        @unlink($probe);

        return true;
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
