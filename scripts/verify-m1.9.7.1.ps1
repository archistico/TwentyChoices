$ErrorActionPreference = "Stop"

Write-Host "=== M1.9.7.1 late-fault audit baseline hotfix regression ===" -ForegroundColor Cyan
& (Join-Path $PSScriptRoot "verify-m1.9.7.ps1")
if ($LASTEXITCODE -ne 0) {
    throw "M1.9.7 non verde dopo la hotfix della baseline audit: M1.9.7.1 interrotta."
}

Write-Host "M1.9.7.1 late-fault audit baseline hotfix verification completed successfully." -ForegroundColor Green
