$ErrorActionPreference = "Stop"

function Invoke-Checked {
    param (
        [Parameter(Mandatory = $true)]
        [string] $Executable,

        [Parameter(Mandatory = $false)]
        [string[]] $Arguments = @()
    )

    Write-Host "> $Executable $($Arguments -join ' ')" -ForegroundColor DarkGray
    & $Executable @Arguments

    if ($LASTEXITCODE -ne 0) {
        throw ("Comando fallito con codice {0}: {1} {2}" -f $LASTEXITCODE, $Executable, ($Arguments -join ' '))
    }
}

function Get-PhpRuntimeInfo {
    $binary = (& php -r "echo PHP_BINARY;")
    if ($LASTEXITCODE -ne 0) {
        throw "Impossibile determinare l'eseguibile PHP in uso."
    }

    $ini = (& php -r "echo php_ini_loaded_file() ?: '(nessun php.ini caricato)';")
    if ($LASTEXITCODE -ne 0) {
        throw "Impossibile determinare il php.ini in uso."
    }

    return [PSCustomObject]@{
        Binary = $binary
        Ini = $ini
    }
}

function Assert-SqlitePdoDriver {
    & php -r "exit(in_array('sqlite', PDO::getAvailableDrivers(), true) ? 0 : 1);"

    if ($LASTEXITCODE -eq 0) {
        return
    }

    $runtime = Get-PhpRuntimeInfo

    $message = @"
Il PHP usato dal terminale non dispone del driver PDO SQLite.

Eseguibile PHP: $($runtime.Binary)
php.ini caricato: $($runtime.Ini)

Aprire il php.ini indicato e verificare queste righe:

extension_dir = "ext"
extension=pdo_sqlite
extension=sqlite3

Se le estensioni sono commentate con ';', rimuovere il punto e virgola.
Chiudere e riaprire il terminale, quindi verificare con:

php -r "print_r(PDO::getAvailableDrivers());"

L'elenco deve contenere 'sqlite'. Se Composer usa un PHP diverso, confrontare anche:

where.exe php
php --ini
"@

    throw $message
}

$varDir = Join-Path $PSScriptRoot "..\var"
if (-not (Test-Path -LiteralPath $varDir)) {
    New-Item -ItemType Directory -Path $varDir | Out-Null
}

$env:COMPOSER_CACHE_DIR = Join-Path $varDir "composer-cache"
if (-not (Test-Path -LiteralPath $env:COMPOSER_CACHE_DIR)) {
    New-Item -ItemType Directory -Path $env:COMPOSER_CACHE_DIR | Out-Null
}

if (-not (Get-Command php -ErrorAction SilentlyContinue)) {
    throw "PHP non trovato. Installare PHP 8.3 o 8.4 e riaprire il terminale."
}

if (-not (Get-Command composer -ErrorAction SilentlyContinue)) {
    throw "Composer non trovato. Installare Composer e riaprire il terminale."
}

Assert-SqlitePdoDriver

Invoke-Checked "php" @("-r", "exit(function_exists('sodium_crypto_secretbox') || (function_exists('openssl_encrypt') && in_array('aes-256-gcm', openssl_get_cipher_methods(), true)) ? 0 : 1);")

# Verifica anche che PDO riesca ad aprire realmente una connessione SQLite.
Invoke-Checked "php" @("-r", "`$pdo = new PDO('sqlite::memory:'); echo 'PDO SQLite operativo.' . PHP_EOL;")

# URL di base usato da Symfony quando genera URL fuori da una richiesta HTTP.
# Viene impostato anche nel processo corrente per funzionare con eventuali env compilati.
if ([string]::IsNullOrWhiteSpace($env:DEFAULT_URI)) {
    $env:DEFAULT_URI = "http://localhost"
}

Invoke-Checked "php" @("tools/ensure-local-secret.php")
Invoke-Checked "composer" @("install", "--no-interaction", "--no-scripts")
Invoke-Checked "php" @("bin/console", "cache:clear")
Invoke-Checked "php" @("bin/console", "doctrine:migrations:migrate", "--no-interaction")
Invoke-Checked "php" @("bin/console", "app:system:check")

# bin/phpunit ricrea autonomamente var/test.db e applica tutte le migrazioni,
# rendendo deterministica anche l'esecuzione diretta della suite dopo un test fallito.
Invoke-Checked "php" @("tools/domain-tests.php")
Invoke-Checked "php" @("bin/phpunit")

Write-Host "TwentyChoices inizializzato correttamente." -ForegroundColor Green
Write-Host "Se non hai ancora un account amministrativo: php bin/console app:admin:create admin --role=SUPER_ADMIN" -ForegroundColor Yellow
