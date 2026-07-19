#!/usr/bin/env sh
set -eu

export DEFAULT_URI="${DEFAULT_URI:-http://localhost}"
export COMPOSER_CACHE_DIR="${PWD}/var/composer-cache"
mkdir -p var "$COMPOSER_CACHE_DIR"

command -v php >/dev/null 2>&1 || {
    echo "PHP 8.3 or 8.4 is required." >&2
    exit 1
}

command -v composer >/dev/null 2>&1 || {
    echo "Composer is required." >&2
    exit 1
}

php tools/bootstrap-preflight.php

run() {
    printf '> %s\n' "$*"
    "$@"
}

run php tools/ensure-local-secret.php
run composer install --no-interaction --no-scripts
run php bin/console cache:clear
run php bin/console doctrine:migrations:migrate --no-interaction
run php bin/console app:installation:verify
run php bin/console app:system:check

# bin/phpunit recreates var/test.db and applies all migrations itself, so direct
# executions remain deterministic even after an interrupted/failing test run.
run php tools/domain-tests.php
run php bin/phpunit

echo "TwentyChoices initialized successfully."
echo "If needed, create the first admin: php bin/console app:admin:create admin --role=SUPER_ADMIN"
