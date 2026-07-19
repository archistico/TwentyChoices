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

Write-Host "=== M1.9.1 clean-package audit ===" -ForegroundColor Cyan
Invoke-Checked "php" @("tools/package-audit.php")

Write-Host "=== M1.9.1 bootstrap pass 1 ===" -ForegroundColor Cyan
& (Join-Path $PSScriptRoot "bootstrap.ps1")
if ($LASTEXITCODE -ne 0) {
    throw "Primo bootstrap M1.9.1 fallito."
}

Write-Host "=== M1.9.1 bootstrap pass 2 (idempotence) ===" -ForegroundColor Cyan
& (Join-Path $PSScriptRoot "bootstrap.ps1")
if ($LASTEXITCODE -ne 0) {
    throw "Secondo bootstrap M1.9.1 fallito."
}

Write-Host "=== M1.9.1 final installation verification ===" -ForegroundColor Cyan
Invoke-Checked "php" @("bin/console", "app:installation:verify")

Write-Host "M1.9.1 verification completed successfully." -ForegroundColor Green
