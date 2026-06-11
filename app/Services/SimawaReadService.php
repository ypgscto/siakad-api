<?php

namespace App\Services;

use App\Support\Simawa\SimawaCollectionHelper;
use App\Support\Simawa\SimawaListQuery;

/**
 * Lapisan SIMAWA: memetakan output SiakadReadService ke format SIMAWA-GS.
 * Query utama tetap di SiakadReadService (method lama tidak diubah).
 */
class SimawaReadService
{
    use SimawaCollectionHelper;

    public function __construct(
        protected SiakadReadService $siakad,
        protected SimawaUserReadService $users,
    ) {}

    /**
     * @return array{error: string|null, total: int, data: list<array<string, mixed>>}
     */
    public function prodi(SimawaListQuery $query): array
    {
        $rows = array_map(
            fn (array $row): array => [
                'siakad_id' => (string) ($row['siakad_id'] ?? $row['id'] ?? ''),
                'kode_prodi' => (string) ($row['kode'] ?? $row['siakad_id'] ?? ''),
                'nama_prodi' => (string) ($row['nama'] ?? ''),
                'jenjang' => $row['jenjang'] ?? null,
                'is_active' => (bool) ($row['is_active'] ?? true),
            ],
            $this->siakad->prodi(),
        );

        $page = $this->slicePage(
            $rows,
            $query,
            $query->prodiId !== null
                ? fn (array $r): bool => ($r['siakad_id'] ?? '') === $query->prodiId
                : null,
        );

        return ['error' => null, 'total' => $page['total'], 'data' => $page['data']];
    }

    /**
     * @return array{error: string|null, total: int, data: list<array<string, mixed>>}
     */
    public function tahunAkademik(SimawaListQuery $query): array
    {
        $rows = array_map(
            fn (array $row): array => [
                'siakad_id' => (string) ($row['tahun_id'] ?? ''),
                'nama_tahun_akademik' => (string) ($row['nama_tahun'] ?? $row['tahun_id'] ?? ''),
                'semester' => $row['jenis_semester'] ?? null,
                'is_active' => (bool) ($row['is_active'] ?? true),
            ],
            $this->siakad->tahunAkademik(),
        );

        $filter = null;
        if ($query->status !== null) {
            $wantActive = in_array(strtolower($query->status), ['aktif', 'active', '1', 'n'], true);
            $filter = fn (array $r): bool => (bool) $r['is_active'] === $wantActive;
        }

        $page = $this->slicePage($rows, $query, $filter);

        return ['error' => null, 'total' => $page['total'], 'data' => $page['data']];
    }

    /**
     * @return array{error: string|null, total: int, data: list<array<string, mixed>>}
     */
    public function statusMahasiswa(SimawaListQuery $query): array
    {
        $tipe = strtolower((string) ($query->tipe ?? 'all'));
        $allowed = ['all', 'operasional', 'awal', 'kelulusan'];
        if (! in_array($tipe, $allowed, true)) {
            return ['error' => 'Parameter tipe harus: all, operasional, awal, atau kelulusan.', 'total' => 0, 'data' => []];
        }

        $merged = [];

        if ($tipe === 'all' || $tipe === 'operasional') {
            foreach ($this->siakad->statusMhsw() as $row) {
                $merged[] = [
                    'siakad_id' => $row['id'],
                    'nama_status' => $row['nama'],
                    'tipe' => 'operasional',
                    'keluar' => $row['keluar'] ?? null,
                ];
            }
        }

        if ($tipe === 'all' || $tipe === 'awal') {
            foreach ($this->siakad->statusAwal() as $row) {
                $merged[] = [
                    'siakad_id' => $row['id'],
                    'nama_status' => $row['nama'],
                    'tipe' => 'awal',
                    'keluar' => null,
                ];
            }
        }

        if ($tipe === 'all' || $tipe === 'kelulusan') {
            foreach ($this->siakad->statusLulus() as $row) {
                $merged[] = [
                    'siakad_id' => $row['id'],
                    'nama_status' => $row['nama'],
                    'tipe' => 'kelulusan',
                    'keluar' => null,
                ];
            }
        }

        if ($query->status !== null) {
            $merged = array_values(array_filter(
                $merged,
                fn (array $r): bool => (string) ($r['siakad_id'] ?? '') === $query->status,
            ));
        }

        $page = $this->slicePage($merged, $query);

        return ['error' => null, 'total' => $page['total'], 'data' => $page['data']];
    }

    /**
     * @return array{error: string|null, total: int, data: list<array<string, mixed>>}
     */
    public function mahasiswa(SimawaListQuery $query): array
    {
        $statusMhsw = $query->status;
        $statusAwal = null;
        if ($query->status !== null && ! $this->looksLikeStatusMhswFilter($query)) {
            $statusMhsw = null;
            $statusAwal = $query->status;
        }

        $raw = $this->siakad->mahasiswaSimawa(
            $query->programId,
            $query->prodiId,
            null,
            $query->angkatan,
            $statusAwal,
            $statusMhsw,
        );

        $prodiNames = $this->prodiNameIndex();
        $fotoBase = rtrim((string) config('siakad_api.simawa.foto_base_url', ''), '/');

        $rows = [];
        foreach ($raw as $row) {
            $rows[] = $this->mapMahasiswaRow($row, $prodiNames, $fotoBase);
        }

        $page = $this->slicePage($rows, $query);

        return ['error' => null, 'total' => $page['total'], 'data' => $page['data']];
    }

    /**
     * @return array{error: string|null, total: int, data: list<array<string, mixed>>}
     */
    public function dosen(SimawaListQuery $query): array
    {
        $prodiNames = $this->prodiNameIndex();

        $rows = array_map(
            function (array $row) use ($prodiNames): array {
                $homebase = $this->nullableString($row['prodi_kode'] ?? null);

                return [
                    'siakad_id' => (string) ($row['siakad_id'] ?? $row['id'] ?? ''),
                    'nidn' => $this->nullableString($row['nidn'] ?? null),
                    'nip' => $this->nullableString($row['nip'] ?? null),
                    'nuptk' => $this->nullableString($row['nuptk'] ?? null),
                    'nama' => (string) ($row['nama'] ?? ''),
                    'homebase_prodi_id' => $homebase,
                    'nama_prodi_homebase' => $homebase !== null ? ($prodiNames[$homebase] ?? null) : null,
                    'email' => $this->nullableString($row['email'] ?? null),
                    'nomor_hp' => $this->nullableString($row['handphone'] ?? null),
                    'is_active' => (bool) ($row['is_active'] ?? true),
                ];
            },
            $this->siakad->dosenSimawa(),
        );

        $page = $this->slicePage(
            $rows,
            $query,
            $query->prodiId !== null
                ? fn (array $r): bool => ($r['homebase_prodi_id'] ?? '') === $query->prodiId
                : null,
        );

        return ['error' => null, 'total' => $page['total'], 'data' => $page['data']];
    }

    /**
     * @return array{error: string|null, total: int, data: list<array<string, mixed>>}
     */
    /**
     * @return array{error: string|null, total: int, data: list<array<string, mixed>>}
     */
    public function loginUsers(SimawaListQuery $query): array
    {
        return $this->users->loginUsers($query);
    }

    public function alumni(SimawaListQuery $query): array
    {
        $raw = $this->siakad->mahasiswaKeluar(
            $query->programId,
            $query->prodiId,
            null,
            $query->angkatan,
            $query->status,
        );

        $prodiNames = $this->prodiNameIndex();

        $rows = array_map(function (array $row) use ($prodiNames): array {
            $prodiId = $this->nullableString($row['prodi_id'] ?? null);
            $tglKeluar = $this->nullableString($row['tanggal_keluar'] ?? null);
            $tahunLulus = null;
            if ($tglKeluar !== null && preg_match('/^(\d{4})/', $tglKeluar, $m)) {
                $tahunLulus = $m[1];
            }

            return [
                'siakad_id' => (string) ($row['mhsw_id'] ?? ''),
                'nim' => (string) ($row['nim'] ?? ''),
                'nama' => (string) ($row['nama'] ?? ''),
                'prodi_siakad_id' => $prodiId,
                'nama_prodi' => $prodiId !== null ? ($prodiNames[$prodiId] ?? null) : null,
                'angkatan' => $this->angkatanFromTahunId($row['tahun_id_masuk'] ?? null),
                'tahun_masuk_id' => $this->nullableString($row['tahun_id_masuk'] ?? null),
                'tanggal_keluar' => $tglKeluar,
                'tahun_lulus' => $tahunLulus,
                'nomor_ijazah' => $this->nullableString($row['nomor_ijazah'] ?? null),
                'status_kelulusan_id' => $this->nullableString($row['status_lulus_id'] ?? null),
                'status_kelulusan_nama' => $this->nullableString($row['status_lulus_nama'] ?? null),
                'ipk' => $this->nullableFloat($row['ipk'] ?? null),
            ];
        }, $raw);

        $page = $this->slicePage($rows, $query);

        return ['error' => null, 'total' => $page['total'], 'data' => $page['data']];
    }

    /**
     * @return array<string, string>
     */
    protected function prodiNameIndex(): array
    {
        $index = [];
        foreach ($this->siakad->prodi() as $row) {
            $id = (string) ($row['siakad_id'] ?? $row['id'] ?? '');
            if ($id !== '') {
                $index[$id] = (string) ($row['nama'] ?? '');
            }
        }

        return $index;
    }

    /**
     * @param  array<string, mixed>  $row
     * @param  array<string, string>  $prodiNames
     * @return array<string, mixed>
     */
    protected function mapMahasiswaRow(array $row, array $prodiNames, string $fotoBase): array
    {
        $id = (string) ($row['mhsw_id'] ?? '');
        $prodiId = $this->nullableString($row['prodi_id'] ?? null);
        $fotoPath = $this->nullableString($row['foto'] ?? null);
        $fotoUrl = null;
        if ($fotoPath !== null && $fotoBase !== '') {
            $fotoUrl = $fotoBase.'/'.ltrim($fotoPath, '/');
        }

        $kelaminNama = strtolower((string) ($row['kelamin_nama'] ?? ''));
        $jk = (str_contains($kelaminNama, 'pria') || str_contains($kelaminNama, 'laki'))
            ? 'L'
            : ($row['jenis_kelamin_feeder'] ?? 'P');

        return [
            'siakad_id' => $id,
            'nim' => (string) ($row['nim'] ?? ''),
            'nama' => (string) ($row['nama'] ?? ''),
            'jenis_kelamin' => $this->nullableString($row['kelamin_nama'] ?? null),
            'jenis_kelamin_kode' => $jk,
            'tempat_lahir' => $this->nullableString($row['tempat_lahir'] ?? null),
            'tanggal_lahir' => $this->nullableString($row['tanggal_lahir'] ?? null),
            'prodi_siakad_id' => $prodiId,
            'nama_prodi' => $prodiId !== null ? ($prodiNames[$prodiId] ?? null) : null,
            'angkatan' => $this->angkatanFromTahunId($row['tahun_id'] ?? null),
            'tahun_akademik_masuk_id' => $this->nullableString($row['tahun_id'] ?? null),
            'status_mahasiswa_id' => $this->nullableString($row['status_mhsw_id'] ?? null),
            'status_mahasiswa_nama' => $this->nullableString($row['status_mhsw_nama'] ?? null),
            'status_awal_id' => $this->nullableString($row['status_awal_id'] ?? null),
            'status_awal_nama' => $this->nullableString($row['status_awal_nama'] ?? null),
            'nomor_hp' => $this->nullableString($row['handphone'] ?? null)
                ?? $this->nullableString($row['telepon'] ?? null),
            'email' => $this->nullableString($row['email'] ?? null),
            'alamat' => $this->nullableString($row['alamat'] ?? null),
            'foto_path' => $fotoPath,
            'foto_url' => $fotoUrl,
        ];
    }

    protected function angkatanFromTahunId(mixed $tahunId): ?string
    {
        $t = trim((string) $tahunId);
        if ($t === '' || strlen($t) < 4) {
            return null;
        }

        return substr($t, 0, 4);
    }

    protected function looksLikeStatusMhswFilter(SimawaListQuery $query): bool
    {
        if ($query->status === null) {
            return false;
        }

        foreach ($this->siakad->statusMhsw() as $row) {
            if ($row['id'] === $query->status) {
                return true;
            }
        }

        return false;
    }
}
