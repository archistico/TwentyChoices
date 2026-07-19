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

Write-Host "=== M1.9.7 inherited validated baseline regression ===" -ForegroundColor Cyan
& (Join-Path $PSScriptRoot "verify-m1.9.6.ps1")
if ($LASTEXITCODE -ne 0) {
    throw "Baseline M1.9.6 non verde: M1.9.7 interrotta."
}

Write-Host "=== M1.9.7 test database schema synchronization ===" -ForegroundColor Cyan
Invoke-Checked "php" @("bin/console", "doctrine:migrations:migrate", "--no-interaction", "--env=test")

Write-Host "=== M1.9.7 transactional winning settlement gate ===" -ForegroundColor Cyan
Invoke-Checked "php" @("bin/console", "app:verification:winning-settlement", "--env=test")

Write-Host "=== M1.9.7 final release integrity recheck ===" -ForegroundColor Cyan
Invoke-Checked "php" @("tools/release-manifest-check.php")

Write-Host "M1.9.7 winning settlement verification completed successfully." -ForegroundColor Green
