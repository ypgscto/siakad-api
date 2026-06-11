<?php

namespace App\Services;

use App\Support\Simawa\SimawaCollectionHelper;
use App\Support\Simawa\SimawaListQuery;
use Illuminate\Support\Facades\DB;

/**
 * Daftar akun Siakad yang boleh disinkronkan ke SIMAWA-GS (read-only).
 */
class SimawaUserReadService
{
    use SimawaCollectionHelper;

    /**
     * @return array{error: string|null, total: int, data: list<array<string, mixed>>}
     */
    public function loginUsers(SimawaListQuery $query): array
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
            $rows = DB::connection('siakad')->select($this->buildSelectSql($table, $columns));
        } catch (\Throwable $e) {
            return ['error' => 'Gagal membaca akun user Siakad: '.$e->getMessage(), 'total' => 0, 'data' => []];
        }

        $mapped = [];
        foreach ($rows as $row) {
            $payload = $this->mapRow((array) $row, $columns);
            if ($payload !== null) {
                $mapped[] = $payload;
            }
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
     * @param  list<string>  $columns
     */
    protected function buildSelectSql(string $table, array $columns): string
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
     * @param  array<string, mixed>  $row
     * @param  list<string>  $columns
     * @return array<string, mixed>|null
     */
    protected function mapRow(array $row, array $columns): ?array
    {
        $jenisUser = $this->normalizeJenisUser($row);
        if ($jenisUser === '' || ! $this->isJenisUserAllowed($jenisUser)) {
            return null;
        }

        $login = $this->pick($row, ['ref_id', 'Ref_ID', 'Login', 'login', 'email', 'Email']);
        if ($login === null || trim($login) === '') {
            return null;
        }

        $email = strtolower(trim((string) ($this->pick($row, ['email', 'Email']) ?? '')));
        if ($email === '' || ! filter_var($email, FILTER_VALIDATE_EMAIL)) {
            if (filter_var($login, FILTER_VALIDATE_EMAIL)) {
                $email = strtolower($login);
            } else {
                return null;
            }
        }

        $map = $this->resolveCategoryAndRoles($row, $jenisUser);
        if ($map === null) {
            return null;
        }

        $siakadUserId = $this->pick($row, ['id', 'ID', 'ref_id', 'Ref_ID', 'Login', 'login']) ?? $login;
        $isActive = $this->resolveIsActive($row, $columns);
        $allowedCol = $this->firstExistingColumn($columns, ['is_allowed_login', 'allowed_login', 'boleh_login']);
        $isAllowed = $allowedCol !== null
            ? (bool) (int) ($row[$allowedCol] ?? 0)
            : $isActive;

        return [
            'siakad_user_id' => (string) $siakadUserId,
            'siakad_login' => trim($login),
            'email' => $email,
            'name' => (string) ($this->pick($row, ['Nama', 'nama', 'nama_user', 'NamaUser']) ?? $login),
            'user_category' => $map['category'],
            'jenis_user' => $jenisUser,
            'jenis_user_label' => (string) (config('siakad_api.jenis_user_labels')[$jenisUser] ?? ''),
            'prodi_id' => (string) ($this->pick($row, ['ProdiID', 'prodiid', 'prodi_id']) ?? ''),
            'is_active' => $isActive,
            'is_allowed_login' => $isAllowed && $isActive,
            'simawa_roles' => $map['roles'],
        ];
    }

    /**
     * @param  array<string, mixed>  $row
     * @return array{category: string, roles: list<string>}|null
     */
    protected function resolveCategoryAndRoles(array $row, string $jenisUser): ?array
    {
        $jenisMap = config('siakad_api.simawa_user_sync.jenis_user_map', []);
        if (isset($jenisMap[$jenisUser]) && is_array($jenisMap[$jenisUser])) {
            return [
                'category' => (string) ($jenisMap[$jenisUser]['category'] ?? 'pegawai'),
                'roles' => array_values(array_filter((array) ($jenisMap[$jenisUser]['roles'] ?? []))),
            ];
        }

        $levelId = (string) ($this->pick($row, ['LevelID', 'level_id', 'levelid']) ?? '');
        $levelMap = config('siakad_api.simawa_user_sync.level_id_map', []);
        if ($levelId !== '' && isset($levelMap[$levelId])) {
            return [
                'category' => (string) ($levelMap[$levelId]['category'] ?? 'mahasiswa'),
                'roles' => array_values(array_filter((array) ($levelMap[$levelId]['roles'] ?? []))),
            ];
        }

        $userType = strtolower((string) ($this->pick($row, ['user_type', 'UserType', 'tipe_user']) ?? ''));
        $typeMap = config('siakad_api.simawa_user_sync.user_type_map', []);
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
        $denied = config('siakad_api.simawa_user_sync.denied_jenis_user', config('siakad_api.app_login_denied_jenis_user', []));
        if (in_array($jenisUser, array_map('strval', $denied), true)) {
            return false;
        }

        $allowed = config('siakad_api.simawa_user_sync.allowed_jenis_user', config('siakad_api.app_login_jenis_user', []));

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
