<?php

declare(strict_types=1);

namespace App\Security\Application;

use App\Shared\Time\Clock;
use Throwable;

final readonly class SecurityEventLogger
{
    public function __construct(
        private Clock $clock,
        private string $projectDir,
    ) {
    }

    /** @param array<string, mixed> $context */
    public function log(string $eventType, array $context = []): void
    {
        try {
            $directory = $this->projectDir.'/var/log';
            if (!is_dir($directory) && !mkdir($directory, 0700, true) && !is_dir($directory)) {
                throw new \RuntimeException('Impossibile creare la directory dei log di sicurezza.');
            }

            $record = [
                'timestamp' => $this->clock->now()->format(DATE_ATOM),
                'event' => $eventType,
                'context' => self::sanitize($context),
            ];
            $line = json_encode(
                $record,
                JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
            ).PHP_EOL;

            $path = $directory.'/security.jsonl';
            $handle = fopen($path, 'ab');
            if ($handle === false) {
                throw new \RuntimeException('Impossibile aprire il log di sicurezza.');
            }

            try {
                if (!flock($handle, LOCK_EX)) {
                    throw new \RuntimeException('Impossibile acquisire il lock del log di sicurezza.');
                }
                fwrite($handle, $line);
                fflush($handle);
                flock($handle, LOCK_UN);
            } finally {
                fclose($handle);
            }
        } catch (Throwable $exception) {
            // Il logging di sicurezza non deve mascherare l'errore applicativo originale.
            error_log('[TwentyChoices security log failure] '.$exception::class);
        }
    }

    private static function sanitize(mixed $value, ?string $key = null): mixed
    {
        if ($key !== null && preg_match('/(?:token|secret|password|nonce|cookie|authorization|challenge|winning[_-]?path|chosen[_-]?path)/i', $key) === 1) {
            return '[REDACTED]';
        }

        if (is_array($value)) {
            $clean = [];
            foreach ($value as $itemKey => $item) {
                $clean[$itemKey] = self::sanitize($item, (string) $itemKey);
            }

            return $clean;
        }

        if (is_object($value)) {
            return '[OBJECT '.get_debug_type($value).']';
        }

        if (is_string($value)) {
            $value = str_replace(["\r", "\n"], ['\\r', '\\n'], $value);

            return strlen($value) > 500 ? substr($value, 0, 500).'…' : $value;
        }

        return $value;
    }
}
