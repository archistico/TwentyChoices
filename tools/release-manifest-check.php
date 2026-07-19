#!/usr/bin/env php
<?php

declare(strict_types=1);

require __DIR__.'/ReleaseManifestVerifier.php';

$verifier = new ReleaseManifestVerifier();
$violations = $verifier->violations(dirname(__DIR__));

if ($violations !== []) {
    fwrite(STDERR, "Release manifest verification FAILED:\n");
    foreach ($violations as $violation) {
        fwrite(STDERR, ' - '.$violation.PHP_EOL);
    }

    exit(1);
}

echo "Release manifest OK: packaged source files are complete and unchanged. Runtime artifacts are outside the release integrity scope.\n";
