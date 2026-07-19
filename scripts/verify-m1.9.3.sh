#!/usr/bin/env sh
set -eu

SCRIPT_DIR=$(CDPATH= cd -- "$(dirname -- "$0")" && pwd)
PROJECT_DIR=$(CDPATH= cd -- "$SCRIPT_DIR/.." && pwd)
cd "$PROJECT_DIR"

run() {
    printf '> %s\n' "$*"
    "$@"
}

printf '%s\n' '=== M1.9.3 inherited validated baseline regression ==='
"$SCRIPT_DIR/verify-m1.9.2.1.3.sh"

printf '%s\n' '=== M1.9.3 transactional cryptographic commitment gate ==='
run php bin/console app:verification:cryptographic-commitment --env=test

printf '%s\n' '=== M1.9.3 final release integrity recheck ==='
run php tools/release-manifest-check.php

printf '%s\n' 'M1.9.3 cryptographic commitment verification completed successfully.'
