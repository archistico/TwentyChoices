<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$path = $root.'/.env.local';
$content = is_file($path) ? (string) file_get_contents($path) : '';

if (preg_match('/^APP_SECRET\s*=\s*\S+/m', $content) === 1) {
    echo "APP_SECRET locale già presente.\n";
    exit(0);
}

$separator = $content === '' || str_ends_with($content, "\n") ? '' : PHP_EOL;
$entry = 'APP_SECRET='.bin2hex(random_bytes(32)).PHP_EOL;

if (file_put_contents($path, $content.$separator.$entry, LOCK_EX) === false) {
    fwrite(STDERR, "Impossibile scrivere .env.local.\n");
    exit(1);
}

echo "APP_SECRET locale generato in .env.local.\n";
