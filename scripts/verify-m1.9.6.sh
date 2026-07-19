#!/usr/bin/env sh
set -eu

SCRIPT_DIR=$(CDPATH= cd -- "$(dirname -- "$0")" && pwd)
PROJECT_DIR=$(CDPATH= cd -- "$SCRIPT_DIR/.." && pwd)
cd "$PROJECT_DIR"

run() {
    printf '> %s\n' "$*"
    "$@"
}

printf '%s\n' '=== M1.9.6 inherited validated baseline regression ==='
"$SCRIPT_DIR/verify-m1.9.5.sh"

printf '%s\n' '=== M1.9.6 test database schema synchronization ==='
run php bin/console doctrine:migrations:migrate --no-interaction --env=test

printf '%s\n' '=== M1.9.6 transactional full losing journey gate ==='
run php bin/console app:verification:full-losing-journey --env=test

printf '%s\n' '=== M1.9.6 final release integrity recheck ==='
run php tools/release-manifest-check.php

printf '%s\n' 'M1.9.6 full losing journey verification completed successfully.'
