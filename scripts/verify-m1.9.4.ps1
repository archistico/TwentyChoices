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

Write-Host "=== M1.9.4 inherited validated baseline regression ===" -ForegroundColor Cyan
& (Join-Path $PSScriptRoot "verify-m1.9.3.ps1")
if ($LASTEXITCODE -ne 0) {
    throw "Baseline M1.9.3 non verde: M1.9.4 interrotta."
}

Write-Host "=== M1.9.4 test database schema synchronization ===" -ForegroundColor Cyan
Invoke-Checked "php" @("bin/console", "doctrine:migrations:migrate", "--no-interaction", "--env=test")

Write-Host "=== M1.9.4 transactional play start/accounting gate ===" -ForegroundColor Cyan
Invoke-Checked "php" @("bin/console", "app:verification:play-start-accounting", "--env=test")

Write-Host "=== M1.9.4 final release integrity recheck ===" -ForegroundColor Cyan
Invoke-Checked "php" @("tools/release-manifest-check.php")

Write-Host "M1.9.4 play start and accounting verification completed successfully." -ForegroundColor Green
