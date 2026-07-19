#!/usr/bin/env sh
set -eu

printf '%s\n' '=== M1.9.2 package audit ==='
php tools/package-audit.php

printf '%s\n' '=== M1.9.2 bootstrap and full regression suite ==='
./scripts/bootstrap.sh

printf '%s\n' '=== M1.9.2 transactional catalog/round gate ==='
php bin/console app:verification:catalog-round --env=test

printf '%s\n' 'M1.9.2 verification completed successfully.'
