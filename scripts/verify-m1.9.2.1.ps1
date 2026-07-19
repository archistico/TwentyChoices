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

Write-Host "=== M1.9.2.1 release/runtime policy audit ===" -ForegroundColor Cyan
Invoke-Checked "php" @("tools/release-manifest-check.php")
Invoke-Checked "php" @("tools/runtime-baseline-check.php")
Invoke-Checked "php" @("tools/client-timing-check.php")

Write-Host "=== M1.9.2.1 clean bootstrap and full regression suite ===" -ForegroundColor Cyan
& (Join-Path $PSScriptRoot "bootstrap.ps1")
if ($LASTEXITCODE -ne 0) {
    throw "Bootstrap/suite M1.9.2.1 falliti."
}

Write-Host "=== M1.9.2 inherited catalog/round gate ===" -ForegroundColor Cyan
Invoke-Checked "php" @("bin/console", "app:verification:catalog-round", "--env=test")

Write-Host "=== M1.9.2.1 final policy recheck ===" -ForegroundColor Cyan
Invoke-Checked "php" @("tools/runtime-baseline-check.php")
Invoke-Checked "php" @("tools/client-timing-check.php")

Write-Host "M1.9.2.1 verification completed successfully." -ForegroundColor Green
