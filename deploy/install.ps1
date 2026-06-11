# Siakad-API — instalasi pertama (Windows).
#   cd C:\webserver\www
#   git clone https://github.com/ypgscto/siakad-api.git siakad-api
#   cd siakad-api
#   copy .env.example .env
#   (edit .env — SIAKAD_DB_*, SIAKAD_API_TOKEN, APP_URL)
#   powershell -ExecutionPolicy Bypass -File deploy\install.ps1
$ErrorActionPreference = "Stop"
. (Join-Path $PSScriptRoot "lib\common.ps1")

Set-Location $script:DeployAppDir

Write-Host ""
Write-Host "========================================"
Write-Host " Siakad-API - INSTALASI PERTAMA"
Write-Host "========================================"
Write-Host ""

if (-not (Test-Path ".env")) {
    if (Test-Path ".env.example") {
        Copy-Item ".env.example" ".env"
        Write-DeployWarn "Buat .env dari .env.example — EDIT dulu SIAKAD_DB_* dan TOKEN sebelum production"
    } else {
        throw "File .env tidak ada. Buat manual dari .env.example"
    }
}

$php = Get-DeployPhp
$composer = Get-DeployComposer
Write-Host "PHP: $php"
Write-Host ""

Invoke-DeployCommand $php @("artisan", "key:generate", "--force")
Invoke-DeployBuild -Php $php -Composer $composer
Show-DeployFinishMessage
