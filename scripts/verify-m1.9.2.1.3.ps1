$ErrorActionPreference = "Stop"

& (Join-Path $PSScriptRoot "verify-m1.9.2.1.2.ps1")
if ($LASTEXITCODE -ne 0) {
    throw "Verifica M1.9.2.1.3 fallita."
}

Write-Host "M1.9.2.1.3 snapshot reference detachment hotfix validated successfully." -ForegroundColor Green
