<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\SiakadReadService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SiakadSyncController extends Controller
{
    public function __construct(
        protected SiakadReadService $reader
    ) {}

    public function semesterAktif(): JsonResponse
    {
        return $this->jsonData($this->reader->semesterAktif());
    }

    public function angkatanMahasiswa(): JsonResponse
    {
        return $this->jsonData($this->reader->angkatanMahasiswa());
    }

    public function prodi(): JsonResponse
    {
        return $this->jsonData($this->reader->prodi());
    }

    public function kurikulum(Request $request): JsonResponse
    {
        $prodiId = $request->query('prodi_id');

        return $this->jsonData($this->reader->kurikulum(is_string($prodiId) ? $prodiId : null));
    }

    public function dosen(): JsonResponse
    {
        return $this->jsonData($this->reader->dosen());
    }

    public function mahasiswa(): JsonResponse
    {
        return $this->jsonData($this->reader->mahasiswa());
    }

    public function mahasiswaSync(Request $request): JsonResponse
    {
        return $this->jsonData($this->reader->mahasiswaSync(
            $this->stringQuery($request, 'program_id'),
            $this->stringQuery($request, 'prodi_id'),
            $this->stringQuery($request, 'tahun_id'),
            $this->stringQuery($request, 'angkatan'),
            $this->stringQuery($request, 'status_awal_id'),
            $this->parseNimsQuery($request),
        ));
    }

    public function program(): JsonResponse
    {
        return $this->jsonData($this->reader->programStudi());
    }

    public function statusAwal(): JsonResponse
    {
        return $this->jsonData($this->reader->statusAwal());
    }

    public function statusLulus(): JsonResponse
    {
        return $this->jsonData($this->reader->statusLulus());
    }

    public function khs(Request $request): JsonResponse
    {
        $tahunId = $this->stringQuery($request, 'tahun_id');
        if ($tahunId === null || trim($tahunId) === '') {
            return response()->json([
                'message' => 'Query tahun_id wajib diisi (contoh: 20241).',
            ], 422);
        }

        return $this->jsonData($this->reader->khsPerSemester(
            trim($tahunId),
            $this->stringQuery($request, 'program_id'),
            $this->stringQuery($request, 'prodi_id'),
        ));
    }

    public function kelasPeserta(Request $request): JsonResponse
    {
        $jadwalId = $this->stringQuery($request, 'jadwal_id');
        if ($jadwalId === null || trim($jadwalId) === '') {
            return response()->json([
                'message' => 'Query jadwal_id wajib diisi.',
            ], 422);
        }

        return $this->jsonData($this->reader->kelasPeserta(
            trim($jadwalId),
            $this->stringQuery($request, 'tahun_id'),
            $this->stringQuery($request, 'prodi_id'),
            $this->stringQuery($request, 'mk_kode'),
            $this->stringQuery($request, 'nama_kelas'),
        ));
    }

    public function mahasiswaKeluar(Request $request): JsonResponse
    {
        return $this->jsonData($this->reader->mahasiswaKeluar(
            $this->stringQuery($request, 'program_id'),
            $this->stringQuery($request, 'prodi_id'),
            $this->stringQuery($request, 'tahun_id'),
            $this->stringQuery($request, 'angkatan'),
            $this->stringQuery($request, 'status_lulus_id'),
        ));
    }

    public function nilaiKonversi(Request $request): JsonResponse
    {
        return $this->jsonData($this->reader->nilaiKonversi(
            $this->stringQuery($request, 'angkatan'),
            $this->stringQuery($request, 'tahun_krs'),
            $this->stringQuery($request, 'prodi_id'),
            $this->stringQuery($request, 'mhsw_id'),
            $this->stringQuery($request, 'status_awal_id'),
            $this->stringQuery($request, 'program_id'),
            $this->stringQuery($request, 'nim'),
        ));
    }

    public function mataKuliah(): JsonResponse
    {
        return $this->jsonData($this->reader->mataKuliah());
    }

    public function kelas(Request $request): JsonResponse
    {
        $tahunId = $request->query('tahun_id');

        return $this->jsonData($this->reader->kelas(is_string($tahunId) ? $tahunId : null));
    }

    public function krs(Request $request): JsonResponse
    {
        $tahunId = $request->query('tahun_id');

        return $this->jsonData($this->reader->krs(is_string($tahunId) ? $tahunId : null));
    }

    public function nilai(Request $request): JsonResponse
    {
        $tahunId = $request->query('tahun_id');

        return $this->jsonData($this->reader->nilai(is_string($tahunId) ? $tahunId : null));
    }

    /**
     * @param  array<int, array<string, mixed>>  $rows
     */
    protected function jsonData(array $rows): JsonResponse
    {
        return response()->json([
            'data' => $rows,
        ]);
    }

    protected function stringQuery(Request $request, string $key): ?string
    {
        $v = $request->query($key);

        return is_string($v) ? $v : null;
    }

    /**
     * @return list<string>
     */
    protected function parseNimsQuery(Request $request): array
    {
        $raw = $request->query('nims');
        if (is_string($raw) && trim($raw) !== '') {
            return array_values(array_filter(array_map('trim', explode(',', $raw))));
        }

        if (is_array($raw)) {
            return array_values(array_filter(array_map(
                fn ($v) => is_string($v) ? trim($v) : '',
                $raw,
            )));
        }

        $nim = $request->query('nim');

        return is_string($nim) && trim($nim) !== '' ? [trim($nim)] : [];
    }
}
