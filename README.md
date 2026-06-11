# Siakad-API

Layanan REST **read-only** ke database **SiAkad (Sisfo/MySQL)** untuk sinkronisasi dan autentikasi **SI-Tercapai** (Sistem Informasi Terintegrasi Capaian Lulusan).

## Fitur

- Sinkron master: tahun akademik, prodi, kurikulum, mata kuliah, dosen, mahasiswa, kelas, KRS, peserta kelas
- Login aplikasi OBE: `POST /api/auth/login-app` (verifikasi password argon di tabel `users`)
- Proteksi Bearer token (`SIAKAD_API_TOKEN`)
- Filter institusi opsional (`SIAKAD_KODE_ID`)

## Instalasi cepat (development)

```bash
composer install
cp .env.example .env
php artisan key:generate
# Sesuaikan SIAKAD_DB_* ke MySQL siakad_db
php artisan serve
```

Health check: `GET /api/health`

## Deploy produksi

Lihat **[docs/DEPLOY-PRODUCTION.md](docs/DEPLOY-PRODUCTION.md)**.

```bash
php artisan app:prepare-production --force
php artisan config:cache
php artisan route:cache
```

## Dokumentasi tambahan

- [docs/DEPLOY-PRODUCTION.md](docs/DEPLOY-PRODUCTION.md)
- [docs/api-sync-extensions.json](docs/api-sync-extensions.json)
- [docs/SIFeeder-Laravel-Blueprint.md](docs/SIFeeder-Laravel-Blueprint.md)
- [docs/SIMAWA-API.md](docs/SIMAWA-API.md) — endpoint `/api/simawa/*` untuk SIMAWA-GS

## Lisensi

Proyek internal STIKES Gunung Sari — YPGS IT Division.
