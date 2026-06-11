<?php

namespace App\Support\Simawa;

use Illuminate\Http\JsonResponse;

final class SimawaApiResponse
{
    /**
     * @param  array<int, array<string, mixed>>|array<string, mixed>  $data
     * @param  array{total: int, limit: int, offset: int}|null  $meta
     */
    public static function success(
        array $data,
        string $message = 'Data berhasil diambil',
        ?array $meta = null,
        int $status = 200,
    ): JsonResponse {
        return response()->json([
            'success' => true,
            'message' => $message,
            'data' => $data,
            'meta' => $meta,
        ], $status);
    }

    public static function error(
        string $message,
        int $status = 400,
    ): JsonResponse {
        return response()->json([
            'success' => false,
            'message' => $message,
            'data' => [],
            'meta' => null,
        ], $status);
    }

    /**
     * @return array{total: int, limit: int, offset: int}
     */
    public static function meta(int $total, int $limit, int $offset): array
    {
        return [
            'total' => $total,
            'limit' => $limit,
            'offset' => $offset,
        ];
    }
}
