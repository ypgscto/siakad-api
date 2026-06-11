<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureSiakadApiToken
{
    public function handle(Request $request, Closure $next): Response
    {
        $expected = (string) config('siakad_api.token', '');
        if ($expected === '') {
            return response()->json([
                'message' => 'API token belum dikonfigurasi di server (SIAKAD_API_TOKEN).',
            ], 503);
        }

        $header = $request->header('Authorization', '');
        if (! preg_match('/^Bearer\s+(.+)$/i', $header, $m)) {
            return response()->json(['message' => 'Authorization Bearer token wajib.'], 401);
        }

        $provided = trim($m[1]);
        if (! hash_equals($expected, $provided)) {
            return response()->json(['message' => 'Token tidak valid.'], 403);
        }

        return $next($request);
    }
}
