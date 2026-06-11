# Deploy Siakad-API — Server Windows

Repo: https://github.com/ypgscto/siakad-api

| Situasi | Perintah |
|---------|----------|
| **Instalasi pertama** | `deploy\install.ps1` (setelah `git clone` + edit `.env`) |
| **Update rutin** | `deploy\update.ps1` |

Skrip otomatis: `git pull` dari GitHub, `composer install`, `migrate`, `config:cache`. **`.env` tidak pernah ditimpa.**

## Update production

```powershell
cd C:\webserver\www\siakad-api
powershell -ExecutionPolicy Bypass -File deploy\update.ps1
```

Verifikasi:

```powershell
curl.exe -s http://98.142.245.18/siakad-api/public/api/health
```

## Paksa PHP 8.2

```powershell
$env:SIAKAD_API_PHP = "C:\webserver\bin\php\php-8.2.xx-Win32-vs16-x64\php.exe"
powershell -ExecutionPolicy Bypass -File deploy\update.ps1
```

Panduan lengkap: [docs/DEPLOY-WINDOWS.md](../docs/DEPLOY-WINDOWS.md)

Deploy bersama Siakad-Feeder: [Siakad-Feeder/docs/DEPLOY-RELEASE.md](https://github.com/ypgscto/siakad-feeder/blob/main/docs/DEPLOY-RELEASE.md)
