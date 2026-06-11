<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\SimawaReadService;
use App\Support\Simawa\SimawaApiResponse;
use App\Support\Simawa\SimawaListQuery;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SimawaSyncController extends Controller
{
    public function __construct(
        protected SimawaReadService $reader,
    ) {}

    public function prodi(Request $request): JsonResponse
    {
        $query = SimawaListQuery::fromRequest($request);

        return $this->respond($this->reader->prodi($query), $query);
    }

    public function tahunAkademik(Request $request): JsonResponse
    {
        $query = SimawaListQuery::fromRequest($request);

        return $this->respond($this->reader->tahunAkademik($query), $query);
    }

    public function statusMahasiswa(Request $request): JsonResponse
    {
        $query = SimawaListQuery::fromRequest($request);

        return $this->respond($this->reader->statusMahasiswa($query), $query);
    }

    public function mahasiswa(Request $request): JsonResponse
    {
        $query = SimawaListQuery::fromRequest($request);

        return $this->respond($this->reader->mahasiswa($query), $query);
    }

    public function dosen(Request $request): JsonResponse
    {
        $query = SimawaListQuery::fromRequest($request);

        return $this->respond($this->reader->dosen($query), $query);
    }

    public function alumni(Request $request): JsonResponse
    {
        $query = SimawaListQuery::fromRequest($request);

        return $this->respond($this->reader->alumni($query), $query);
    }

    public function loginUsers(Request $request): JsonResponse
    {
        $query = SimawaListQuery::fromRequest($request);

        return $this->respond($this->reader->loginUsers($query), $query);
    }

    /**
     * @param  array{error: string|null, total: int, data: list<array<string, mixed>>}  $result
     */
    protected function respond(array $result, SimawaListQuery $query): JsonResponse
    {
        if ($result['error'] !== null) {
            $status = str_contains($result['error'], 'updated_after') ? 422 : 400;

            return SimawaApiResponse::error($result['error'], $status);
        }

        return SimawaApiResponse::success(
            $result['data'],
            'Data berhasil diambil',
            SimawaApiResponse::meta($result['total'], $query->limit, $query->offset),
        );
    }
}
