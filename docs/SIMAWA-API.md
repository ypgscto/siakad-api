# API SIMAWA-GS (Siakad-API)

Endpoint read-only khusus integrasi **SIMAWA-GS**. Route lama (`/api/prodi`, `/api/mahasiswa-sync`, dll.) **tidak berubah**.

## Arsitektur

- **`SimawaReadService`** — memetakan data ke format SIMAWA + pagination di memori.
- **`SiakadReadService` (method lama)** — dipakai ulang: `prodi()`, `statusAwal()`, `statusLulus()`, `mahasiswaKeluar()`, dll.
- **Method baru di `SiakadReadService`** (hanya jika hasil berbeda, tanpa mengubah method lama):
  - `statusMhsw()` — status operasional
  - `tahunAkademik()` — nama tahun + flag `NA`
  - `mahasiswaSimawa()` — biodata + HP, foto, status mhsw
  - `dosenSimawa()` — email dan handphone terpisah

## Autentikasi

```
Authorization: Bearer <SIAKAD_API_TOKEN>
```

## Format respons

Sukses:

```json
{
  "success": true,
  "message": "Data berhasil diambil",
  "data": [],
  "meta": { "total": 0, "limit": 50, "offset": 0 }
}
```

Error:

```json
{
  "success": false,
  "message": "Pesan error",
  "data": [],
  "meta": null
}
```

## Query umum

| Parameter | Keterangan |
|-----------|------------|
| `limit` | Default 50, maks 500 |
| `offset` | Default 0 |
| `updated_after` | Belum dipakai pada lapisan SIMAWA (gunakan endpoint sync lama jika diperlukan) |
| `prodi_id` | Filter prodi (jika relevan) |
| `angkatan` | 4 digit tahun masuk |
| `status` | Makna berbeda per endpoint (lihat bawah) |
| `program_id` | Filter program (mahasiswa, alumni) |
| `tipe` | Hanya `/status-mahasiswa`: `all`, `operasional`, `awal`, `kelulusan` |

## Endpoint

| Method | Path |
|--------|------|
| GET | `/api/simawa/prodi` |
| GET | `/api/simawa/tahun-akademik` |
| GET | `/api/simawa/status-mahasiswa` |
| GET | `/api/simawa/mahasiswa` |
| GET | `/api/simawa/dosen` |
| GET | `/api/simawa/alumni` |
| GET | `/api/simawa/login-users` |

### `/api/simawa/login-users`

Daftar akun Siakad yang boleh disinkronkan ke SIMAWA-GS (pegawai, dosen, mahasiswa, alumni). Setiap baris memuat `siakad_user_id`, `siakad_login`, `email`, `name`, `user_category`, `is_active`, `is_allowed_login`, dan `simawa_roles` (array peran SIMAWA).

Login aplikasi tetap memakai `POST /api/auth/login-app` (verifikasi password di database Siakad).

### Parameter `status`

- **tahun-akademik:** `aktif` / `nonaktif` (kolom `tahun.NA`)
- **mahasiswa:** `StatusMhswID` (atau `StatusAwalID` jika kolom status mhsw tidak ada)
- **alumni:** `StatusLulusID`
- **status-mahasiswa:** filter `siakad_id` setelah merge master

## Contoh

```bash
curl -H "Authorization: Bearer TOKEN" \
  "http://localhost/siakad-api/public/api/simawa/mahasiswa?limit=20&offset=0&prodi_id=XX&angkatan=2024"
```
