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

Write-Host "=== M1.9.6 inherited validated baseline regression ===" -ForegroundColor Cyan
& (Join-Path $PSScriptRoot "verify-m1.9.5.ps1")
if ($LASTEXITCODE -ne 0) {
    throw "Baseline M1.9.5 non verde: M1.9.6 interrotta."
}

Write-Host "=== M1.9.6 test database schema synchronization ===" -ForegroundColor Cyan
Invoke-Checked "php" @("bin/console", "doctrine:migrations:migrate", "--no-interaction", "--env=test")

Write-Host "=== M1.9.6 transactional full losing journey gate ===" -ForegroundColor Cyan
Invoke-Checked "php" @("bin/console", "app:verification:full-losing-journey", "--env=test")

Write-Host "=== M1.9.6 final release integrity recheck ===" -ForegroundColor Cyan
Invoke-Checked "php" @("tools/release-manifest-check.php")

Write-Host "M1.9.6 full losing journey verification completed successfully." -ForegroundColor Green
