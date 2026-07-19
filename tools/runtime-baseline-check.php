#!/usr/bin/env php
<?php

declare(strict_types=1);

require __DIR__.'/RuntimeBaselinePolicy.php';

$policy = new RuntimeBaselinePolicy();
$checks = $policy->inspect(dirname(__DIR__));

foreach ($checks as $check) {
    printf('[%s] %-28s %s%s', strtoupper($check['status']), $check['name'], $check['value'], PHP_EOL);
    if ($check['status'] === 'error') {
        fwrite(STDERR, '       '.$check['detail'].PHP_EOL);
    }
}

exit($policy->hasErrors($checks) ? 1 : 0);
