#!/usr/bin/env php
<?php

declare(strict_types=1);

require __DIR__.'/PackageAudit.php';

$auditor = new PackageAudit();
$violations = $auditor->violations(dirname(__DIR__));

if ($violations !== []) {
    fwrite(STDERR, "Package audit FAILED. Runtime or secret-bearing artifacts found:\n");
    foreach ($violations as $violation) {
        fwrite(STDERR, ' - '.$violation.PHP_EOL);
    }

    exit(1);
}

echo "Package audit OK: no local secrets, databases, vendor tree or runtime artifacts detected.\n";
