# Deploy Siakad-API — Windows (Apache)

Repo: **https://github.com/ypgscto/siakad-api**

API read-only ke **siakad_db** (MySQL Sisfo). Dipakai Siakad-Feeder, SI-Tercapai, SIMAWA, dll.

---

## A. Update rutin (server sudah jalan)

```powershell
cd C:\webserver\www\siakad-api
powershell -ExecutionPolicy Bypass -File deploy\update.ps1
```

Skrip ini:
1. `git fetch` + `reset --hard origin/main` (backup `.env` dulu)
2. `composer install --no-dev`
3. `php artisan migrate --force`
4. `php artisan config:cache` + `route:cache`

**`.env` tidak disentuh.**

---

## B. Instalasi pertama

```powershell
cd C:\webserver\www
git clone https://github.com/ypgscto/siakad-api.git siakad-api
cd siakad-api
copy .env.example .env
notepad .env
powershell -ExecutionPolicy Bypass -File deploy\install.ps1
```

### `.env` production (contoh)

```env
APP_URL=http://98.142.245.18/siakad-api/public
APP_SUBDIRECTORY=/siakad-api/public

SIAKAD_API_TOKEN=<token rahasia — sama dengan Siakad-Feeder>
SIAKAD_KODE_ID=093146

SIAKAD_DB_HOST=127.0.0.1
SIAKAD_DB_DATABASE=siakad_db
SIAKAD_DB_USERNAME=...
SIAKAD_DB_PASSWORD=...
```

---

## C. Verifikasi

```powershell
curl.exe -s http://98.142.245.18/siakad-api/public/api/health
```

Harus JSON: `{"ok":true,"service":"siakad-api","siakad_db":"ok"}`

Tes mahasiswa-sync (ganti TOKEN):

```powershell
curl.exe -s -H "Authorization: Bearer TOKEN" ^
  "http://98.142.245.18/siakad-api/public/api/mahasiswa-sync?nims=25222067"
```

Harus ada field `"handphone"` dan `"tgl_kuliah_mulai"`.

---

## D. Deploy bersama Siakad-Feeder

Urutan disarankan:

```powershell
cd C:\webserver\www\siakad-api
powershell -ExecutionPolicy Bypass -File deploy\update.ps1

cd C:\webserver\www\siakad-feeder
powershell -ExecutionPolicy Bypass -File deploy\update.ps1
```

Lihat: https://github.com/ypgscto/siakad-feeder/blob/main/docs/DEPLOY-RELEASE.md

---

## E. Troubleshooting

| Gejala | Solusi |
|--------|--------|
| HTTP 404 HTML (bukan JSON) | Akses lewat `.../siakad-api/public/` — upload `web.config` + `.htaccess` |
| Deploy pakai PHP 8.3 | Set `SIAKAD_API_PHP` ke path php-8.2 |
| `git fetch` gagal | Cek internet / credential GitHub |
| Feeder HP kosong | Pastikan API sudah versi terbaru (field `handphone` di mahasiswa-sync) |
