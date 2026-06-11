<?php

use App\Http\Controllers\Api\SiakadAuthController;
use App\Http\Controllers\Api\SiakadSyncController;
use App\Http\Controllers\Api\SigantengSyncController;
use App\Http\Controllers\Api\SipepengSyncController;
use App\Http\Controllers\Api\SimawaSyncController;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;

Route::get('/health', function () {
    $payload = [
        'ok' => true,
        'service' => 'siakad-api',
        'siakad_db' => 'ok',
    ];

    try {
        DB::connection('siakad')->select('SELECT 1 AS ok');
    } catch (Throwable $e) {
        $payload['ok'] = false;
        $payload['siakad_db'] = 'error';
        if (! app()->isProduction()) {
            $payload['detail'] = $e->getMessage();
        }

        return response()->json($payload, 503);
    }

    if ((string) config('siakad_api.token', '') === '') {
        $payload['ok'] = false;
        $payload['token_configured'] = false;

        return response()->json($payload, 503);
    }

    return response()->json($payload);
});

Route::middleware('siakad.token')->group(function (): void {
    Route::middleware('throttle:30,1')->group(function (): void {
        Route::post('/auth/login-app', [SiakadAuthController::class, 'loginApp']);
        Route::post('/auth/login', [SiakadAuthController::class, 'loginSimutu']);
        Route::post('/auth/login-password-hash', [SiakadAuthController::class, 'loginPasswordHash']);
        Route::post('/auth/login-mysql-legacy', [SiakadAuthController::class, 'loginMysqlLegacy']);
    });

    Route::get('/users/sso-lookup', [SiakadAuthController::class, 'lookupSso']);
    Route::get('/users/by-username/{username}', [SiakadAuthController::class, 'userByUsername']);
    Route::get('/users/by-id/{siakadUserId}', [SiakadAuthController::class, 'userById'])->whereNumber('siakadUserId');

    Route::get('/semester-aktif', [SiakadSyncController::class, 'semesterAktif']);
    Route::get('/angkatan-mahasiswa', [SiakadSyncController::class, 'angkatanMahasiswa']);
    Route::get('/prodi', [SiakadSyncController::class, 'prodi']);
    Route::get('/program', [SiakadSyncController::class, 'program']);
    Route::get('/status-awal', [SiakadSyncController::class, 'statusAwal']);
    Route::get('/status-lulus', [SiakadSyncController::class, 'statusLulus']);
    Route::get('/kurikulum', [SiakadSyncController::class, 'kurikulum']);
    Route::get('/dosen', [SiakadSyncController::class, 'dosen']);
    Route::get('/mahasiswa', [SiakadSyncController::class, 'mahasiswa']);
    Route::get('/mahasiswa-sync', [SiakadSyncController::class, 'mahasiswaSync']);
    Route::get('/khs', [SiakadSyncController::class, 'khs']);
    Route::get('/kelas-peserta', [SiakadSyncController::class, 'kelasPeserta']);
    Route::get('/mahasiswa-keluar', [SiakadSyncController::class, 'mahasiswaKeluar']);
    Route::get('/nilai-konversi', [SiakadSyncController::class, 'nilaiKonversi']);
    Route::get('/mata-kuliah', [SiakadSyncController::class, 'mataKuliah']);
    Route::get('/kelas', [SiakadSyncController::class, 'kelas']);
    Route::get('/krs', [SiakadSyncController::class, 'krs']);
    Route::get('/nilai', [SiakadSyncController::class, 'nilai']);

    /*
    | SIMAWA-GS — endpoint read-only dengan envelope { success, message, data, meta }.
    | Tidak mengubah route sync lama di atas.
    */
    Route::prefix('simawa')->group(function (): void {
        Route::get('/prodi', [SimawaSyncController::class, 'prodi']);
        Route::get('/tahun-akademik', [SimawaSyncController::class, 'tahunAkademik']);
        Route::get('/status-mahasiswa', [SimawaSyncController::class, 'statusMahasiswa']);
        Route::get('/mahasiswa', [SimawaSyncController::class, 'mahasiswa']);
        Route::get('/dosen', [SimawaSyncController::class, 'dosen']);
        Route::get('/alumni', [SimawaSyncController::class, 'alumni']);
        Route::get('/login-users', [SimawaSyncController::class, 'loginUsers']);
    });

    /*
    | SiPepeng — endpoint read-only dengan envelope { success, message, data, meta }.
    */
    Route::prefix('sipepeng')->group(function (): void {
        Route::get('/login-users', [SipepengSyncController::class, 'loginUsers']);
    });

    /*
    | SiGanteng — lookup & sinkron akun login SSO.
    */
    Route::prefix('siganteng')->group(function (): void {
        Route::get('/login-users', [SigantengSyncController::class, 'loginUsers']);
        Route::get('/lookup-user', [SigantengSyncController::class, 'lookupUser']);
    });
});
