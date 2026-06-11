# Deploy Siakad-API ke Produksi

API baca data **siakad_db** (MySQL Sisfo) untuk **SI-Tercapai** dan aplikasi OBE. Tidak menyimpan data akademik sendiri — hanya cache/log lokal Laravel.

## 1. Persiapan di server

**Windows — update dari GitHub (production):**

```powershell
cd C:\webserver\www\siakad-api
powershell -ExecutionPolicy Bypass -File deploy\update.ps1
```

Repo: **https://github.com/ypgscto/siakad-api** — skrip **tidak menyentuh `.env`**.

Panduan Windows: [DEPLOY-WINDOWS.md](DEPLOY-WINDOWS.md) · deploy bersama Feeder: [Siakad-Feeder DEPLOY-RELEASE](https://github.com/ypgscto/siakad-feeder/blob/main/docs/DEPLOY-RELEASE.md)

**Instalasi pertama:** `git clone` → edit `.env` → `deploy\install.ps1`

**Linux / manual:**

```bash
composer install --no-dev --optimize-autoloader
cp .env.example .env
php artisan key:generate
# Edit .env (lihat bawah)
php artisan migrate --force
php artisan app:prepare-production --force
php artisan config:cache
php artisan route:cache
```

Panduan lengkap fresh Windows + Siakad-Feeder: `Siakad-Feeder/docs/DEPLOY-FRESH-WINDOWS.md`

## 2. Variabel `.env` produksi

| Variabel | Keterangan |
|----------|------------|
| `APP_ENV` | `production` |
| `APP_DEBUG` | `false` |
| `APP_URL` | URL publik API, mis. `https://siakad-api.stikes-gunungsari.ac.id` |
| `LOG_LEVEL` | `error` |
| `SIAKAD_API_TOKEN` | Token panjang & rahasia — **harus sama** dengan `SIAKAD_API_TOKEN` di SI-Tercapai |
| `SIAKAD_KODE_ID` | Kode institusi Sisfo (mis. `093146`) |
| `SIAKAD_DB_*` | Host/port/database/user/password **MySQL Siakad produksi** |
| `SIAKAD_USER_TABLE` | Biasanya `users` (login login-app) |

Disarankan: user MySQL **hanya SELECT** pada `siakad_db`.

## 3. Sinkron dengan SI-Tercapai

Di `.env` SI-Tercapai:

```env
SIAKAD_API_BASE_URL=http://98.142.245.18/siakad-api/public
SIAKAD_API_TOKEN=<token yang sama>
SIAKAD_API_USE_DUMMY=false
SIAKAD_KODE_ID=093146
```

Jangan set `SIAKAD_API_HOST=siakad-api.test` di produksi (IP/domain).

Jika SI-Tercapai dan API di server berbeda, pastikan DNS/HTTPS dan firewall mengizinkan outbound dari server SI-Tercapai.

## 4. Verifikasi

Jika document root **bukan** folder `public` (URL memuat `/public`), gunakan path itu:

```powershell
# Coba dulu (paling umum di Windows / alias Apache):
curl.exe -s http://IP/siakad-api/public/api/health

# Setelah upload web.config + .htaccess di root proyek, bisa juga:
curl.exe -s http://IP/siakad-api/api/health
```

Respons sukses: `{"ok":true,"service":"siakad-api","siakad_db":"ok"}`

**404 HTML Apache** (bukan JSON Laravel) = request tidak sampai `public/index.php`. Upload `web.config`, `.htaccess`, dan `public/web.config` dari repo, lalu set di `.env`:

```env
APP_URL=http://98.142.245.18/siakad-api/public
APP_SUBDIRECTORY=/siakad-api/public
```

Di **SI-Tercapai** samakan base URL (tanpa `/api` di akhir):

```env
SIAKAD_API_BASE_URL=http://98.142.245.18/siakad-api/public
```

```powershell
curl.exe -s -H "Authorization: Bearer <TOKEN>" "http://IP/siakad-api/public/api/prodi"
```

## 5. Endpoint utama

| Method | Path | Keterangan |
|--------|------|------------|
| GET | `/api/health` | Publik — cek API + koneksi siakad_db |
| POST | `/api/auth/login-app` | Login SI-Tercapai (tabel `users`, argon) |
| GET | `/api/prodi` | Program studi |
| GET | `/api/kurikulum?prodi_id=` | Kurikulum per prodi |
| GET | `/api/mata-kuliah?prodi_id=` | Mata kuliah |
| GET | `/api/dosen?prodi_id=` | Dosen |
| GET | `/api/mahasiswa-sync` | Mahasiswa lengkap untuk Siakad-Feeder (filter: `program_id`, `prodi_id`, `tahun_id`, `status_awal_id`) |
| GET | `/api/mahasiswa-sync?nims=25222067,25222068` | **Baru** — hanya NIM terpilih (untuk kirim mahasiswa tercentang) |
| GET | `/api/mahasiswa?prodi_id=` | Mahasiswa |
| GET | `/api/kelas` | Kelas/jadwal |
| GET | `/api/kelas-peserta?jadwal_id=` | Peserta kelas |

Semua endpoint data (kecuali `/api/health`) memerlukan header `Authorization: Bearer <SIAKAD_API_TOKEN>`.

## 6. Reset sebelum go-live

```bash
php artisan app:prepare-production --force
```

Menghapus: cache, log, file uji (`storage/login-test.json`), tabel lokal Laravel. **Tidak** mengubah data di `siakad_db`.
