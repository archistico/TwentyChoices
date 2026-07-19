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

Write-Host "=== M1.9.2 package audit ===" -ForegroundColor Cyan
Invoke-Checked "php" @("tools/package-audit.php")

Write-Host "=== M1.9.2 bootstrap and full regression suite ===" -ForegroundColor Cyan
& (Join-Path $PSScriptRoot "bootstrap.ps1")
if ($LASTEXITCODE -ne 0) {
    throw "Bootstrap/suite M1.9.2 falliti."
}

Write-Host "=== M1.9.2 transactional catalog/round gate ===" -ForegroundColor Cyan
Invoke-Checked "php" @("bin/console", "app:verification:catalog-round", "--env=test")

Write-Host "M1.9.2 verification completed successfully." -ForegroundColor Green
