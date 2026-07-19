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

Write-Host "=== M1.9.3 inherited validated baseline regression ===" -ForegroundColor Cyan
& (Join-Path $PSScriptRoot "verify-m1.9.2.1.3.ps1")
if ($LASTEXITCODE -ne 0) {
    throw "Baseline M1.9.2.1.3 non verde: M1.9.3 interrotta."
}

Write-Host "=== M1.9.3 transactional cryptographic commitment gate ===" -ForegroundColor Cyan
Invoke-Checked "php" @("bin/console", "app:verification:cryptographic-commitment", "--env=test")

Write-Host "=== M1.9.3 final release integrity recheck ===" -ForegroundColor Cyan
Invoke-Checked "php" @("tools/release-manifest-check.php")

Write-Host "M1.9.3 cryptographic commitment verification completed successfully." -ForegroundColor Green
