# Library deploy Siakad-API — dot-source dari install.ps1 / update.ps1
$ErrorActionPreference = "Stop"

$script:DeployAppDir = (Resolve-Path (Join-Path $PSScriptRoot "..\..")).Path
$script:DeployGitRemote = "https://github.com/ypgscto/siakad-api.git"
$script:DeployGitBranch = "main"

function Write-DeployStep {
    param([int]$Number, [int]$Total, [string]$Message)
    Write-Host ""
    Write-Host "[$Number/$Total] $Message" -ForegroundColor Cyan
}

function Write-DeployOk([string]$Message) {
    Write-Host "  OK - $Message" -ForegroundColor Green
}

function Write-DeployWarn([string]$Message) {
    Write-Host "  ! $Message" -ForegroundColor Yellow
}

function Get-ToolRoots {
    return @($env:LARAGON_ROOT, "C:\laragon", "C:\webserver", "C:\xampp") |
        Where-Object { $_ -and (Test-Path $_) } | Select-Object -Unique
}

function Get-DeployPhp {
    if ($env:SIAKAD_API_PHP -and (Test-Path $env:SIAKAD_API_PHP)) { return $env:SIAKAD_API_PHP }

    $candidates = @()
    foreach ($root in Get-ToolRoots) {
        $candidates += Get-ChildItem "$root\bin\php\*\php.exe" -ErrorAction SilentlyContinue
    }

    if ($candidates.Count -eq 0) { return "php" }

    $php82 = $candidates |
        Where-Object { $_.Directory.Name -match '^php-8\.2\.' } |
        Sort-Object { $_.Directory.Name } -Descending |
        Select-Object -First 1
    if ($php82) { return $php82.FullName }

    return ($candidates | Sort-Object { $_.Directory.Name } -Descending | Select-Object -First 1).FullName
}

function Get-DeployComposer {
    if ($env:SIAKAD_API_COMPOSER -and (Test-Path $env:SIAKAD_API_COMPOSER)) {
        return $env:SIAKAD_API_COMPOSER
    }
    foreach ($root in Get-ToolRoots) {
        $composerBat = Join-Path $root "bin\composer\composer.bat"
        if (Test-Path $composerBat) { return $composerBat }
    }
    return "composer"
}

function Invoke-DeployCommand {
    param([string]$Executable, [string[]]$Arguments)
    Write-Host "  >> $Executable $($Arguments -join ' ')" -ForegroundColor DarkGray
    & $Executable @Arguments
    if ($LASTEXITCODE -ne 0) {
        throw "Perintah gagal (exit $LASTEXITCODE): $Executable $($Arguments -join ' ')"
    }
}

function Ensure-DeployBackupDir {
    $dir = Join-Path $script:DeployAppDir ".deploy-backup"
    if (-not (Test-Path $dir)) { New-Item -ItemType Directory -Path $dir -Force | Out-Null }
    return $dir
}

function Backup-DeployProtectedFiles {
    $backupDir = Ensure-DeployBackupDir
    $stamp = Get-Date -Format "yyyyMMdd-HHmmss"
    $envPath = Join-Path $script:DeployAppDir ".env"
    if (Test-Path $envPath) {
        Copy-Item $envPath (Join-Path $backupDir "env-$stamp.bak") -Force
        Write-DeployOk ".env di-backup ke .deploy-backup\env-$stamp.bak"
    }
}

function Ensure-DeployDirectories {
    foreach ($dir in @(
        "storage\framework\cache",
        "storage\framework\sessions",
        "storage\framework\views",
        "storage\logs",
        "bootstrap\cache"
    )) {
        $full = Join-Path $script:DeployAppDir $dir
        if (-not (Test-Path $full)) {
            New-Item -ItemType Directory -Path $full -Force | Out-Null
        }
    }
}

function Initialize-DeployGitRepo {
    if (Test-Path (Join-Path $script:DeployAppDir ".git")) { return }

    Write-DeployWarn "Folder belum punya .git - inisialisasi ke GitHub"
    Set-Location $script:DeployAppDir
    Invoke-DeployCommand "git" @("init")
    $remotes = & git remote 2>$null
    if ($remotes -notcontains "origin") {
        Invoke-DeployCommand "git" @("remote", "add", "origin", $script:DeployGitRemote)
    }
}

function Sync-DeployFromGitHub {
    Set-Location $script:DeployAppDir
    Initialize-DeployGitRepo
    Backup-DeployProtectedFiles

    Write-Host "  >> git fetch origin $($script:DeployGitBranch)"
    & git fetch origin $script:DeployGitBranch
    if ($LASTEXITCODE -ne 0) { throw "git fetch gagal - cek koneksi internet dan akses GitHub" }

    Write-Host "  >> git checkout -B $($script:DeployGitBranch) origin/$($script:DeployGitBranch)"
    & git checkout -B $script:DeployGitBranch "origin/$($script:DeployGitBranch)"
    if ($LASTEXITCODE -ne 0) { throw "git checkout gagal" }

    Write-Host "  >> git reset --hard origin/$($script:DeployGitBranch)"
    & git reset --hard "origin/$($script:DeployGitBranch)"
    if ($LASTEXITCODE -ne 0) { throw "git reset gagal" }

    Write-Host "  >> git clean -fd (kecuali .env, storage)"
    & git clean -fd -e .env -e storage -e ".deploy-backup"
    if ($LASTEXITCODE -ne 0) { Write-DeployWarn "git clean mengembalikan peringatan" }

    Write-DeployOk "Kode sama dengan GitHub branch $($script:DeployGitBranch)"
}

function Invoke-DeployBuild {
    param([string]$Php, [string]$Composer)

    Ensure-DeployDirectories

    Invoke-DeployCommand $Composer @("install", "--no-dev", "--prefer-dist", "--no-interaction", "--optimize-autoloader")
    Invoke-DeployCommand $Php @("artisan", "config:clear")
    Invoke-DeployCommand $Php @("artisan", "migrate", "--force")
    Invoke-DeployCommand $Php @("artisan", "config:cache")
    Invoke-DeployCommand $Php @("artisan", "route:cache")

    Write-DeployOk "Composer + migrate + cache selesai"
}

function Show-DeployFinishMessage {
    Write-Host ""
    Write-Host "Deploy selesai - $(Get-Date -Format 'yyyy-MM-dd HH:mm:ss')"
    Write-Host "Health: curl.exe -s http://98.142.245.18/siakad-api/public/api/health"
    Write-Host ""
}
