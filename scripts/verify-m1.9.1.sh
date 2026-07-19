#!/usr/bin/env sh
set -eu

printf '%s\n' '=== M1.9.1 clean-package audit ==='
php tools/package-audit.php

printf '%s\n' '=== M1.9.1 bootstrap pass 1 ==='
./scripts/bootstrap.sh

printf '%s\n' '=== M1.9.1 bootstrap pass 2 (idempotence) ==='
./scripts/bootstrap.sh

printf '%s\n' '=== M1.9.1 final installation verification ==='
php bin/console app:installation:verify

printf '%s\n' 'M1.9.1 verification completed successfully.'
