<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\SigantengUserReadService;
use App\Support\Simawa\SimawaApiResponse;
use App\Support\Simawa\SimawaListQuery;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SigantengSyncController extends Controller
{
    public function __construct(
        protected SigantengUserReadService $reader,
    ) {}

    public function loginUsers(Request $request): JsonResponse
    {
        $query = SimawaListQuery::fromRequest($request);

        return $this->respond($this->reader->loginUsers($query), $query);
    }

    public function lookupUser(Request $request): JsonResponse
    {
        $v = $request->validate([
            'login' => ['required', 'string', 'max:150'],
        ]);

        $profile = $this->reader->lookupByIdentifier(trim($v['login']));
        if ($profile === null) {
            return SimawaApiResponse::error(
                'Akun tidak ditemukan di Siakad atau jenis akun tidak diizinkan untuk SiGanteng (admin/dosen/karyawan).',
                404,
            );
        }

        return SimawaApiResponse::success($profile, 'Akun Siakad ditemukan.');
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
