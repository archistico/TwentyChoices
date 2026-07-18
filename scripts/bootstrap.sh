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

php -r "exit(in_array('sqlite', PDO::getAvailableDrivers(), true) ? 0 : 1);" || {
    echo "The PHP CLI runtime does not provide the PDO SQLite driver." >&2
    echo "Loaded php.ini: $(php -r \"echo php_ini_loaded_file() ?: '(none)';\")" >&2
    echo "Enable pdo_sqlite (and preferably sqlite3) before continuing." >&2
    exit 1
}

php -r '$pdo = new PDO("sqlite::memory:"); echo "PDO SQLite operational." . PHP_EOL;'

run() {
    printf '> %s\n' "$*"
    "$@"
}

run composer install --no-interaction --no-scripts
run php bin/console cache:clear
run php bin/console doctrine:migrations:migrate --no-interaction

# Recreate the test database so persistence tests always start from a deterministic seed.
rm -f var/test.db var/test.db-wal var/test.db-shm
run php bin/console doctrine:migrations:migrate --no-interaction --env=test
run php tools/domain-tests.php
run php bin/phpunit

echo "TwentyChoices initialized successfully."
