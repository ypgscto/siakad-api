<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\SiakadAuthService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SiakadAuthController extends Controller
{
    public function __construct(
        protected SiakadAuthService $auth,
    ) {}

    /**
     * Login dengan metode Siakad-GS: mysql_native_password (10 karakter pertama atau hash *… penuh).
     */
    public function loginMysqlLegacy(Request $request): JsonResponse
    {
        $v = $request->validate([
            'login' => ['required', 'string', 'max:120'],
            'password' => ['nullable', 'string', 'max:200'],
            'level_id' => ['required', 'string', 'max:30'],
            'kode_id' => ['nullable', 'string', 'max:30'],
        ]);

        $kodeId = $this->resolveKodeId($v);
        if ($kodeId === null) {
            return response()->json(['message' => 'kode_id wajib (body) atau atur SIAKAD_KODE_ID di server.'], 422);
        }

        $levelId = trim($v['level_id']);
        $login = trim($v['login']);
        $pwd = array_key_exists('password', $v) ? $v['password'] : null;
        $pwd = is_string($pwd) ? $pwd : null;

        if (! $this->auth->passwordOptionalForLevel($levelId) && ($pwd === null || $pwd === '')) {
            return response()->json(['message' => 'password wajib untuk level_id ini.'], 422);
        }

        $user = $this->auth->attemptMysqlLegacyLogin($login, $pwd, $levelId, $kodeId);
        if ($user === null) {
            return response()->json(['message' => 'Login gagal.'], 401);
        }

        return response()->json(['data' => $user]);
    }

    /**
     * Login dengan password_hash PHP (bcrypt, argon2, dll.) — kolom Password harus hash modern.
     */
    /**
     * Login SI-Tercapai: username + password, verifikasi argon di tabel user.
     */
    /**
     * Lookup akun SSO aktif (email terdaftar) — untuk provisioning user di SI-Tercapai.
     */
    public function lookupSso(Request $request): JsonResponse
    {
        $v = $request->validate([
            'login' => ['required', 'string', 'max:150'],
            'kode_id' => ['nullable', 'string', 'max:30'],
        ]);

        $kodeId = $this->resolveKodeId($v);
        if ($kodeId === null) {
            return response()->json(['message' => 'kode_id wajib (query) atau atur SIAKAD_KODE_ID di server.'], 422);
        }

        $profile = $this->auth->lookupSsoAccount(trim($v['login']), $kodeId);
        if ($profile === null) {
            return response()->json([
                'message' => 'Akun tidak ditemukan, tidak aktif, atau belum memiliki email SSO di Siakad.',
            ], 404);
        }

        return response()->json(['data' => $profile]);
    }

    public function loginApp(Request $request): JsonResponse
    {
        $v = $request->validate([
            'login' => ['required', 'string', 'max:120'],
            'password' => ['required', 'string', 'max:255'],
            'kode_id' => ['nullable', 'string', 'max:30'],
        ]);

        $kodeId = $this->resolveKodeId($v, $request);
        if ($kodeId === null) {
            return response()->json(['message' => 'kode_id wajib (body) atau atur SIAKAD_KODE_ID di server.'], 422);
        }

        $user = $this->auth->attemptAppLogin(
            trim($v['login']),
            (string) $v['password'],
            $kodeId,
        );

        if ($user === null) {
            return response()->json(['message' => 'Login gagal. Periksa username, password, dan jenis akun.'], 401);
        }

        return response()->json(['data' => $user]);
    }

    /**
     * SSO SiMutu — envelope { success, message, data }.
     */
    public function loginSimutu(Request $request): JsonResponse
    {
        $v = $request->validate([
            'username' => ['required', 'string', 'max:120'],
            'password' => ['required', 'string', 'max:255'],
            'kode_id' => ['nullable', 'string', 'max:30'],
        ]);

        $kodeId = $this->resolveKodeId($v, $request);
        if ($kodeId === null) {
            return response()->json([
                'success' => false,
                'message' => 'kode_id wajib (body) atau atur SIAKAD_KODE_ID di server.',
            ], 422);
        }

        $profile = $this->auth->attemptSimutuLogin(trim($v['username']), (string) $v['password'], $kodeId);

        if ($profile === null) {
            return response()->json([
                'success' => false,
                'message' => 'Username atau password salah',
            ], 401);
        }

        if (($profile['status'] ?? 'aktif') !== 'aktif') {
            return response()->json([
                'success' => false,
                'message' => 'Akun SIAKAD tidak aktif.',
            ], 403);
        }

        return response()->json([
            'success' => true,
            'message' => 'Login valid',
            'data' => $profile,
        ]);
    }

    public function userByUsername(string $username, Request $request): JsonResponse
    {
        $kodeId = $this->resolveKodeId(['kode_id' => $request->query('kode_id')]);
        $profile = $this->auth->lookupUserProfileByLogin($username, $kodeId, false);

        if ($profile === null) {
            return response()->json([
                'success' => false,
                'message' => 'User tidak ditemukan.',
            ], 404);
        }

        return response()->json([
            'success' => true,
            'data' => $profile,
        ]);
    }

    public function userById(int $siakadUserId, Request $request): JsonResponse
    {
        $kodeId = $this->resolveKodeId(['kode_id' => $request->query('kode_id')]);
        $profile = $this->auth->lookupUserProfileByLogin((string) $siakadUserId, $kodeId, false);

        if ($profile === null) {
            return response()->json([
                'success' => false,
                'message' => 'User tidak ditemukan.',
            ], 404);
        }

        return response()->json([
            'success' => true,
            'data' => $profile,
        ]);
    }

    public function loginPasswordHash(Request $request): JsonResponse
    {
        $v = $request->validate([
            'login' => ['required', 'string', 'max:120'],
            'password' => ['nullable', 'string', 'max:255'],
            'level_id' => ['required', 'string', 'max:30'],
            'kode_id' => ['nullable', 'string', 'max:30'],
        ]);

        $kodeId = $this->resolveKodeId($v);
        if ($kodeId === null) {
            return response()->json(['message' => 'kode_id wajib (body) atau atur SIAKAD_KODE_ID di server.'], 422);
        }

        $levelId = trim($v['level_id']);
        $login = trim($v['login']);
        $pwd = is_string($v['password'] ?? null) ? $v['password'] : '';

        if (! $this->auth->passwordOptionalForLevel($levelId) && $pwd === '') {
            return response()->json(['message' => 'password wajib untuk level_id ini.'], 422);
        }

        $user = $this->auth->attemptPasswordHashLogin($login, $pwd, $levelId, $kodeId);
        if ($user === null) {
            return response()->json(['message' => 'Login gagal.'], 401);
        }

        return response()->json(['data' => $user]);
    }

    /**
     * @param  array{kode_id?: string|null, ...}  $v
     */
    protected function resolveKodeId(array $v, ?Request $request = null): ?string
    {
        if (isset($v['kode_id']) && is_string($v['kode_id'])) {
            $t = trim($v['kode_id']);
            if ($t !== '') {
                return $t;
            }
        }

        if ($request !== null) {
            $fromHeader = trim((string) $request->header('X-Siakad-Kode-Id', ''));
            if ($fromHeader !== '') {
                return $fromHeader;
            }
        }

        $fromConfig = trim((string) config('siakad_api.kode_id', ''));

        return $fromConfig !== '' ? $fromConfig : null;
    }
}
