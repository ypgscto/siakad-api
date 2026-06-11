# Alias: deploy rutin Siakad-API = update (tanpa sentuh .env).
# Lihat deploy\update.ps1
$ErrorActionPreference = "Stop"
& (Join-Path $PSScriptRoot "update.ps1")
