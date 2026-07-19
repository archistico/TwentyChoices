$ErrorActionPreference = "Stop"

& (Join-Path $PSScriptRoot "verify-m1.9.4.ps1")
if ($LASTEXITCODE -ne 0) {
    throw "Verifica M1.9.4.1 fallita."
}

Write-Host "M1.9.4.1 accounting schema enforcement hotfix validated successfully." -ForegroundColor Green
