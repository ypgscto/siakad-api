# Tarik kode terbaru dari GitHub saja (tanpa composer).
# Berguna jika deploy\update.ps1 versi lama error di PowerShell.
#
#   powershell -ExecutionPolicy Bypass -File deploy\bootstrap-git.ps1
$ErrorActionPreference = "Stop"
. (Join-Path $PSScriptRoot "lib\common.ps1")

Set-Location $script:DeployAppDir
Sync-DeployFromGitHub

Write-Host ""
Write-Host "Selesai. Lanjutkan:"
Write-Host "  powershell -ExecutionPolicy Bypass -File deploy\update.ps1"
Write-Host ""
