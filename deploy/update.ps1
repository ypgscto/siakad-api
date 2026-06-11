# Siakad-API — UPDATE dari GitHub (production Windows).
#   cd C:\webserver\www\siakad-api
#   powershell -ExecutionPolicy Bypass -File deploy\update.ps1
#
# .env TIDAK ditimpa.
$ErrorActionPreference = "Stop"
. (Join-Path $PSScriptRoot "lib\common.ps1")

Set-Location $script:DeployAppDir

Write-Host ""
Write-Host "========================================"
Write-Host " Siakad-API - UPDATE dari GitHub"
Write-Host "========================================"
Write-Host "Folder: $script:DeployAppDir"
Write-Host ""

$php = Get-DeployPhp
$composer = Get-DeployComposer
Write-Host "PHP: $php"
Write-Host ""

$total = 3

Write-DeployStep 1 $total "Sinkron kode dari GitHub"
Sync-DeployFromGitHub

if (-not (Test-Path ".env")) {
    throw '.env wajib sudah ada. Instalasi pertama: deploy\install.ps1'
}

Write-DeployStep 2 $total "Composer + migrate + cache"
Invoke-DeployBuild -Php $php -Composer $composer

Write-DeployStep 3 $total "Selesai"
Show-DeployFinishMessage
