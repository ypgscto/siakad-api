<?php

use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    if (app()->environment('production')) {
        return response()->json([
            'service' => 'siakad-api',
            'status' => 'ok',
            'health' => url('/api/health'),
        ]);
    }

    return response()->json([
        'service' => 'Siakad API',
        'health' => url('/api/health'),
        'docs' => 'GET /api/* dengan Authorization: Bearer token. Login SI-Tercapai: POST /api/auth/login-app.',
    ]);
});
