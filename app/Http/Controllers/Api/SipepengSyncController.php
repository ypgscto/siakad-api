<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\SipepengUserReadService;
use App\Support\Simawa\SimawaApiResponse;
use App\Support\Simawa\SimawaListQuery;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SipepengSyncController extends Controller
{
    public function __construct(
        protected SipepengUserReadService $reader,
    ) {}

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
