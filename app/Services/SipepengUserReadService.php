<?php

namespace App\Services;

use App\Support\Simawa\SimawaCollectionHelper;
use App\Support\Simawa\SimawaListQuery;
use Illuminate\Support\Facades\DB;

/**
 * Daftar akun Siakad yang boleh disinkronkan ke SiPepeng (read-only).
 * Mencakup tabel users dan karyawan-only (mis. KodeLogin tanpa baris users).
 */
class SipepengUserReadService
{
    use SimawaCollectionHelper;

    /**
     * @return array{error: string|null, total: int, data: list<array<string, mixed>>}
     */
    public function loginUsers(SimawaListQuery $query): array
    {
        $mapped = [];
        $seenLogins = [];

        $userTableResult = $this->collectFromUsersTable();
        if ($userTableResult['error'] !== null) {
            return $userTableResult;
        }

        foreach ($userTableResult['data'] as $row) {
            $loginKey = strtolower(trim((string) ($row['siakad_login'] ?? '')));
            if ($loginKey === '') {
                continue;
            }
            $seenLogins[$loginKey] = true;
            $mapped[] = $row;
        }

        foreach ($this->collectFromKaryawanTable($seenLogins) as $row) {
            $mapped[] = $row;
        }

        $filter = null;
        if ($query->status !== null) {
            $wantActive = in_array(strtolower($query->status), ['aktif', 'active', '1'], true);
            $filter = fn (array $r): bool => (bool) ($r['is_active'] ?? false) === $wantActive;
        }

        $page = $this->slicePage($mapped, $query, $filter);

        return ['error' => null, 'total' => $page['total'], 'data' => $page['data']];
    }

    /**
     * @return array{error: string|null, total: int, data: list<array<string, mixed>>}
     */
    protected function collectFromUsersTable(): array
    {
        $table = $this->userTableName();
        if ($table === '') {
            return ['error' => 'Tabel user Siakad tidak ditemukan.', 'total' => 0, 'data' => []];
        }

        $columns = $this->tableColumns($table);
        if ($columns === []) {
            return ['error' => 'Tabel user Siakad tidak dapat dibaca.', 'total' => 0, 'data' => []];
        }

        try {
            $rows = DB::connection('siakad')->select($this->buildUsersSelectSql($table, $columns));
        } catch (\Throwable $e) {
            return ['error' => 'Gagal membaca akun user Siakad: '.$e->getMessage(), 'total' => 0, 'data' => []];
        }

        $mapped = [];
        foreach ($rows as $row) {
            $payload = $this->mapUsersRow((array) $row, $columns);
            if ($payload !== null) {
                $mapped[] = $payload;
            }
        }

        return ['error' => null, 'total' => count($mapped), 'data' => $mapped];
    }

    /**
     * @param  array<string, bool>  $seenLogins
     * @return list<array<string, mixed>>
     */
    protected function collectFromKaryawanTable(array $seenLogins): array
    {
        if (! (bool) config('siakad_api.sipepeng_user_sync.karyawan_enabled', true)) {
            return [];
        }

        if (! $this->tableExists('karyawan')) {
            return [];
        }

        $columns = $this->tableColumns('karyawan');
        if ($columns === []) {
            return [];
        }

        $kodeId = trim((string) config('siakad_api.kode_id', ''));

        try {
            $rows = DB::connection('siakad')->select($this->buildKaryawanSelectSql($columns, $kodeId));
        } catch (\Throwable) {
            return [];
        }

        $mapped = [];
        foreach ($rows as $row) {
            $payload = $this->mapKaryawanRow((array) $row, $columns);
            if ($payload === null) {
                continue;
            }

            $loginKey = strtolower(trim((string) ($payload['siakad_login'] ?? '')));
            if ($loginKey === '' || isset($seenLogins[$loginKey])) {
                continue;
            }

            $seenLogins[$loginKey] = true;
            $mapped[] = $payload;
        }

        return $mapped;
    }

    /**
     * @param  list<string>  $columns
     */
    protected function buildUsersSelectSql(string $table, array $columns): string
    {
        $kodeId = trim((string) config('siakad_api.kode_id', ''));
        $sql = 'SELECT * FROM `'.$table.'` WHERE 1=1';

        $kodeCol = $this->firstExistingColumn($columns, ['KodeID', 'kode_id']);
        if ($kodeCol !== null && $kodeId !== '') {
            $sql .= sprintf(' AND `%s` = %s', $kodeCol, DB::connection('siakad')->getPdo()->quote($kodeId));
        }

        if ($this->firstExistingColumn($columns, ['NA']) !== null) {
            $sql .= " AND (`NA` = 'N' OR `NA` IS NULL OR `NA` = '')";
        } elseif ($this->firstExistingColumn($columns, ['status_user']) !== null) {
            $sql .= ' AND `status_user` = 1';
        }

        return $sql;
    }

    /**
     * @param  list<string>  $columns
     */
    protected function buildKaryawanSelectSql(array $columns, string $kodeId): string
    {
        $sql = 'SELECT * FROM `karyawan` WHERE 1=1';

        $kodeCol = $this->firstExistingColumn($columns, ['KodeID', 'kode_id']);
        if ($kodeCol !== null && $kodeId !== '') {
            $sql .= sprintf(' AND `%s` = %s', $kodeCol, DB::connection('siakad')->getPdo()->quote($kodeId));
        }

        if ($this->firstExistingColumn($columns, ['NA']) !== null) {
            $sql .= " AND (`NA` = 'N' OR `NA` IS NULL OR `NA` = '')";
        }

        return $sql;
    }

    /**
     * @param  array<string, mixed>  $row
     * @param  list<string>  $columns
     * @return array<string, mixed>|null
     */
    protected function mapUsersRow(array $row, array $columns): ?array
    {
        $jenisUser = $this->normalizeJenisUser($row);
        if ($jenisUser === '' || ! $this->isJenisUserAllowed($jenisUser)) {
            return null;
        }

        $login = $this->pick($row, ['ref_id', 'Ref_ID', 'Login', 'login', 'email', 'Email']);
        if ($login === null || trim($login) === '') {
            return null;
        }

        $email = $this->resolveEmail($login, $this->pick($row, ['email', 'Email']));
        if ($email === null) {
            return null;
        }

        $map = $this->resolveCategoryAndRoles($row, $jenisUser);
        if ($map === null) {
            return null;
        }

        $siakadUserId = $this->pick($row, ['id_user', 'ID', 'id', 'Id'])
            ?? $this->pick($row, ['ref_id', 'Ref_ID', 'Login', 'login'])
            ?? $login;
        $isActive = $this->resolveIsActive($row, $columns);

        return $this->formatPayload(
            (string) $siakadUserId,
            trim($login),
            $email,
            (string) ($this->pick($row, ['Nama', 'nama', 'nama_user', 'NamaUser']) ?? $login),
            $map['category'],
            $jenisUser,
            (string) ($this->pick($row, ['ProdiID', 'prodiid', 'prodi_id']) ?? ''),
            (string) ($this->pick($row, ['LevelID', 'level_id', 'levelid']) ?? ''),
            $isActive,
            $map['roles'],
            'users',
        );
    }

    /**
     * @param  array<string, mixed>  $row
     * @param  list<string>  $columns
     * @return array<string, mixed>|null
     */
    protected function mapKaryawanRow(array $row, array $columns): ?array
    {
        $login = $this->pick($row, ['KodeLogin', 'kodelogin', 'Login', 'login', 'Email', 'email']);
        if ($login === null || trim($login) === '') {
            return null;
        }

        $levelId = trim((string) ($this->pick($row, ['LevelID', 'level_id', 'levelid']) ?? ''));
        $jenisUser = $this->mapKaryawanLevelToJenisUser($levelId);
        if ($jenisUser === '' || ! $this->isJenisUserAllowed($jenisUser)) {
            return null;
        }

        $email = $this->resolveEmail($login, $this->pick($row, ['Email', 'email']));
        if ($email === null) {
            return null;
        }

        $map = $this->resolveCategoryAndRoles($row, $jenisUser);
        if ($map === null) {
            return null;
        }

        $karyawanId = trim((string) ($this->pick($row, ['KaryawanID', 'karyawan_id', 'ID', 'id']) ?? ''));
        $siakadUserId = ($karyawanId !== '' && $karyawanId !== '0') ? $karyawanId : trim($login);
        $isActive = $this->resolveIsActive($row, $columns);

        return $this->formatPayload(
            $siakadUserId,
            trim($login),
            $email,
            (string) ($this->pick($row, ['Nama', 'nama', 'NamaKaryawan', 'namakaryawan']) ?? $login),
            $map['category'],
            $jenisUser,
            (string) ($this->pick($row, ['ProdiID', 'prodiid', 'prodi_id']) ?? ''),
            $levelId,
            $isActive,
            $map['roles'],
            'karyawan',
        );
    }

    /**
     * @param  list<string>  $roles
     * @return array<string, mixed>
     */
    protected function formatPayload(
        string $siakadUserId,
        string $login,
        string $email,
        string $name,
        string $category,
        string $jenisUser,
        string $prodiId,
        string $levelId,
        bool $isActive,
        array $roles,
        string $authSource,
    ): array {
        $labels = config('siakad_api.jenis_user_labels', []);

        return [
            'siakad_user_id' => $siakadUserId,
            'siakad_login' => $login,
            'email' => $email,
            'name' => $name,
            'user_category' => $category,
            'jenis_user' => $jenisUser,
            'jenis_user_label' => (string) ($labels[$jenisUser] ?? ''),
            'level_id' => $levelId,
            'prodi_id' => $prodiId,
            'is_active' => $isActive,
            'is_allowed_login' => $isActive,
            'sipepeng_roles' => $roles,
            'auth_source' => $authSource,
        ];
    }

    protected function mapKaryawanLevelToJenisUser(string $levelId): string
    {
        if ($levelId === '') {
            return '';
        }

        foreach (config('siakad_api.sifeeder_roles', []) as $role) {
            if ((string) ($role['karyawan_level_id'] ?? '') === $levelId) {
                return trim((string) ($role['user_jenis_user'] ?? ''));
            }
        }

        $levelMap = config('siakad_api.sso_level_id_to_obe', []);
        if (array_key_exists($levelId, $levelMap)) {
            $mapped = $levelMap[$levelId];

            return $mapped === null ? '' : (string) $mapped;
        }

        return '';
    }

    protected function resolveEmail(string $login, ?string $fromRow): ?string
    {
        $email = strtolower(trim((string) ($fromRow ?? '')));
        if ($email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return $email;
        }

        if (filter_var($login, FILTER_VALIDATE_EMAIL)) {
            return strtolower($login);
        }

        $domain = (string) config('siakad_api.sipepeng_user_sync.email_domain', 'stikesgunungsari.ac.id');
        $synthetic = strtolower(trim($login)).'@'.$domain;

        return filter_var($synthetic, FILTER_VALIDATE_EMAIL) ? $synthetic : null;
    }

    /**
     * @param  array<string, mixed>  $row
     * @return array{category: string, roles: list<string>}|null
     */
    protected function resolveCategoryAndRoles(array $row, string $jenisUser): ?array
    {
        $levelId = (string) ($this->pick($row, ['LevelID', 'level_id', 'levelid']) ?? '');
        $levelMap = config('siakad_api.sipepeng_user_sync.level_id_map', []);
        if ($levelId !== '' && isset($levelMap[$levelId])) {
            return [
                'category' => (string) ($levelMap[$levelId]['category'] ?? 'pegawai'),
                'roles' => array_values(array_filter((array) ($levelMap[$levelId]['roles'] ?? []))),
            ];
        }

        $jenisMap = config('siakad_api.sipepeng_user_sync.jenis_user_map', []);
        if (isset($jenisMap[$jenisUser]) && is_array($jenisMap[$jenisUser])) {
            return [
                'category' => (string) ($jenisMap[$jenisUser]['category'] ?? 'pegawai'),
                'roles' => array_values(array_filter((array) ($jenisMap[$jenisUser]['roles'] ?? []))),
            ];
        }

        $userType = strtolower((string) ($this->pick($row, ['user_type', 'UserType', 'tipe_user']) ?? ''));
        $typeMap = config('siakad_api.sipepeng_user_sync.user_type_map', []);
        if ($userType !== '' && isset($typeMap[$userType])) {
            return [
                'category' => (string) ($typeMap[$userType]['category'] ?? 'pegawai'),
                'roles' => array_values(array_filter((array) ($typeMap[$userType]['roles'] ?? []))),
            ];
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $row
     * @param  list<string>  $columns
     */
    protected function resolveIsActive(array $row, array $columns): bool
    {
        if ($this->firstExistingColumn($columns, ['status_user']) !== null) {
            return (int) ($row['status_user'] ?? 0) === 1;
        }

        if ($this->firstExistingColumn($columns, ['NA']) !== null) {
            $na = (string) ($row['NA'] ?? '');

            return $na === '' || $na === 'N';
        }

        return true;
    }

    protected function normalizeJenisUser(array $row): string
    {
        $raw = (string) ($this->pick($row, ['jenis_user', 'JenisUser', 'Jenis_User']) ?? '');
        if ($raw !== '') {
            $sso = config('siakad_api.sso_jenis_user_to_obe', []);
            if (array_key_exists($raw, $sso)) {
                $mapped = $sso[$raw];

                return $mapped === null ? '' : (string) $mapped;
            }

            return $raw;
        }

        $userType = strtolower((string) ($this->pick($row, ['user_type', 'UserType']) ?? ''));
        $typeMap = config('siakad_api.sso_user_type_to_obe', []);
        if ($userType !== '' && array_key_exists($userType, $typeMap)) {
            $mapped = $typeMap[$userType];

            return $mapped === null ? '' : (string) $mapped;
        }

        $levelId = (string) ($this->pick($row, ['LevelID', 'level_id']) ?? '');
        $levelMap = config('siakad_api.sso_level_id_to_obe', []);
        if ($levelId !== '' && array_key_exists($levelId, $levelMap)) {
            return (string) $levelMap[$levelId];
        }

        return '';
    }

    protected function isJenisUserAllowed(string $jenisUser): bool
    {
        $denied = config('siakad_api.sipepeng_user_sync.denied_jenis_user', config('siakad_api.app_login_denied_jenis_user', []));
        if (in_array($jenisUser, array_map('strval', $denied), true)) {
            return false;
        }

        $allowed = config('siakad_api.sipepeng_user_sync.allowed_jenis_user', config('siakad_api.app_login_jenis_user', []));

        return in_array($jenisUser, array_map('strval', $allowed), true);
    }

    /**
     * @param  array<string, mixed>  $row
     */
    protected function pick(array $row, array $keys): ?string
    {
        foreach ($keys as $k) {
            if (array_key_exists($k, $row) && $row[$k] !== null && (string) $row[$k] !== '') {
                return (string) $row[$k];
            }
        }

        return null;
    }

    protected function userTableName(): string
    {
        $preferred = trim((string) config('siakad_api.user_table', 'users'));
        if ($preferred !== '' && $this->tableExists($preferred)) {
            return $preferred;
        }

        foreach (['users', 'user'] as $candidate) {
            if ($this->tableExists($candidate)) {
                return $candidate;
            }
        }

        return '';
    }

    /**
     * @return list<string>
     */
    protected function tableColumns(string $table): array
    {
        try {
            $rows = DB::connection('siakad')->select('SHOW COLUMNS FROM `'.$table.'`');
        } catch (\Throwable) {
            return [];
        }

        $columns = [];
        foreach ($rows as $row) {
            $field = (array) $row;
            $name = $field['Field'] ?? null;
            if (is_string($name) && $name !== '') {
                $columns[] = $name;
            }
        }

        return $columns;
    }

    protected function firstExistingColumn(array $columns, array $candidates): ?string
    {
        foreach ($candidates as $candidate) {
            if (in_array($candidate, $columns, true)) {
                return $candidate;
            }
        }

        return null;
    }

    protected function tableExists(string $table): bool
    {
        if (! preg_match('/^[A-Za-z0-9_]+$/', $table)) {
            return false;
        }

        try {
            $r = DB::connection('siakad')->selectOne(
                'SELECT 1 FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = ? LIMIT 1',
                [$table]
            );
        } catch (\Throwable) {
            return false;
        }

        return $r !== null;
    }
}
