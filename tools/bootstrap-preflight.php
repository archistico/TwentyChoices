#!/usr/bin/env php
<?php

declare(strict_types=1);

require __DIR__.'/BootstrapPreflight.php';

$verifier = new BootstrapPreflight();
$checks = $verifier->inspect(dirname(__DIR__));

foreach ($checks as $check) {
    printf(
        "[%s] %-24s %s%s",
        strtoupper($check['status']),
        $check['name'],
        $check['value'],
        PHP_EOL,
    );

    if ($check['status'] === 'error') {
        fwrite(STDERR, '       '.$check['detail'].PHP_EOL);
    }
}

exit($verifier->hasErrors($checks) ? 1 : 0);
