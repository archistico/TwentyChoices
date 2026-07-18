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

function Reset-TestSqliteDatabase {
    $varDir = Join-Path $PSScriptRoot "..\var"
    $testDatabase = Join-Path $varDir "test.db"

    if (-not (Test-Path -LiteralPath $varDir)) {
        New-Item -ItemType Directory -Path $varDir | Out-Null
    }

    foreach ($path in @($testDatabase, "$testDatabase-wal", "$testDatabase-shm")) {
        if (Test-Path -LiteralPath $path) {
            Remove-Item -LiteralPath $path -Force
        }
    }
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

# Verifica anche che PDO riesca ad aprire realmente una connessione SQLite.
Invoke-Checked "php" @("-r", "`$pdo = new PDO('sqlite::memory:'); echo 'PDO SQLite operativo.' . PHP_EOL;")

# URL di base usato da Symfony quando genera URL fuori da una richiesta HTTP.
# Viene impostato anche nel processo corrente per funzionare con eventuali env compilati.
if ([string]::IsNullOrWhiteSpace($env:DEFAULT_URI)) {
    $env:DEFAULT_URI = "http://localhost"
}

Invoke-Checked "composer" @("install", "--no-interaction", "--no-scripts")
Invoke-Checked "php" @("bin/console", "cache:clear")
Invoke-Checked "php" @("bin/console", "doctrine:migrations:migrate", "--no-interaction")

# Il database di test deve essere sempre ricreato per rendere deterministici seed e test di persistenza.
Reset-TestSqliteDatabase
Invoke-Checked "php" @("bin/console", "doctrine:migrations:migrate", "--no-interaction", "--env=test")
Invoke-Checked "php" @("tools/domain-tests.php")
Invoke-Checked "php" @("bin/phpunit")

Write-Host "TwentyChoices inizializzato correttamente." -ForegroundColor Green
