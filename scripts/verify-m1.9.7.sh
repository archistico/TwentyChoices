#!/usr/bin/env sh
set -eu

SCRIPT_DIR=$(CDPATH= cd -- "$(dirname -- "$0")" && pwd)
PROJECT_DIR=$(CDPATH= cd -- "$SCRIPT_DIR/.." && pwd)
cd "$PROJECT_DIR"

run() {
    printf '> %s\n' "$*"
    "$@"
}

printf '%s\n' '=== M1.9.7 inherited validated baseline regression ==='
"$SCRIPT_DIR/verify-m1.9.6.sh"

printf '%s\n' '=== M1.9.7 test database schema synchronization ==='
run php bin/console doctrine:migrations:migrate --no-interaction --env=test

printf '%s\n' '=== M1.9.7 transactional winning settlement gate ==='
run php bin/console app:verification:winning-settlement --env=test

printf '%s\n' '=== M1.9.7 final release integrity recheck ==='
run php tools/release-manifest-check.php

printf '%s\n' 'M1.9.7 winning settlement verification completed successfully.'
