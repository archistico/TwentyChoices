#!/usr/bin/env sh
set -eu

run() {
    printf '> %s\n' "$*"
    "$@"
}

echo "=== M1.9.2.1 release/runtime policy audit ==="
run php tools/release-manifest-check.php
run php tools/runtime-baseline-check.php
run php tools/client-timing-check.php

echo "=== M1.9.2.1 clean bootstrap and full regression suite ==="
"$(dirname "$0")/bootstrap.sh"

echo "=== M1.9.2 inherited catalog/round gate ==="
run php bin/console app:verification:catalog-round --env=test

echo "=== M1.9.2.1 final policy recheck ==="
run php tools/runtime-baseline-check.php
run php tools/client-timing-check.php

echo "M1.9.2.1 verification completed successfully."
