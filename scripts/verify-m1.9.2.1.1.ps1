$ErrorActionPreference = "Stop"

& (Join-Path $PSScriptRoot "verify-m1.9.2.1.ps1")
if ($LASTEXITCODE -ne 0) {
    throw "Verifica M1.9.2.1.1 fallita."
}

Write-Host "M1.9.2.1.1 verification workflow hotfix validated successfully." -ForegroundColor Green
