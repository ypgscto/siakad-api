<?php

namespace App\Services;

use App\Support\SisfoMysqlNativePassword;
use Illuminate\Support\Facades\DB;

class SiakadAuthService
{
    /**
     * Login seperti Siakad-GS: verifikasi password mysql_native_password (kolom Password).
     *
     * @return array<string, mixed>|null profil ringkas tanpa hash password
     */
    public function attemptMysqlLegacyLogin(
        string $login,
        ?string $password,
        string $levelId,
        string $kodeId,
    ): ?array {
        return $this->attemptLogin($login, $password, $levelId, $kodeId, 'mysql_legacy');
    }

    /**
     * Login dengan password modern (PHP password_hash): bcrypt, argon2i, argon2id, dll.
     *
     * @return array<string, mixed>|null
     */
    public function attemptPasswordHashLogin(
        string $login,
        string $password,
        string $levelId,
        string $kodeId,
    ): ?array {
        return $this->attemptLogin($login, $password, $levelId, $kodeId, 'password_hash');
    }

    /**
     * Login aplikasi pendukung (SI-Tercapai): cari di tabel user per jenis_user yang diizinkan.
     * Verifikasi: argon / argon_password / password_hash PHP, lalu cadangan mysql legacy.
     *
     * @return array<string, mixed>|null
     */
    public function attemptAppLogin(string $login, string $password, ?string $kodeId = null): ?array
    {
        $login = trim($login);
        if ($login === '' || $password === '') {
            return null;
        }

        $kodeId = trim($kodeId ?? (string) config('siakad_api.kode_id', ''));

        $fromIdentifier = $this->attemptAppLoginByUserRow($login, $password, $kodeId);
        if ($fromIdentifier !== null) {
            return $fromIdentifier;
        }

        $allowed = config('siakad_api.app_login_jenis_user', []);

        foreach ($allowed as $jenisUser) {
            $jenisUser = (string) $jenisUser;
            $row = $this->fetchRowFromSource($login, $kodeId, [
                'table' => $this->userTableName(),
                'role_column' => 'jenis_user',
                'role_value' => $jenisUser,
                'source_key' => 'user',
            ]);

            if ($row === null || ! $this->verifyAppPassword($row, $password)) {
                continue;
            }

            if ($this->isAppUserRowLoginDenied($row)) {
                continue;
            }

            return $this->formatAppUserPayload($row, $jenisUser, $kodeId);
        }

        return $this->attemptAppLoginViaKaryawanFallback($login, $password, $kodeId);
    }

    /**
     * Login SSO SiMutu — verifikasi password, kembalikan jenis_user mentah dari DB (tanpa pemetaan OBE).
     *
     * @return array<string, mixed>|null
     */
    public function attemptSimutuLogin(string $login, string $password, ?string $kodeId = null): ?array
    {
        $login = trim($login);
        if ($login === '' || $password === '') {
            return null;
        }

        $kodeId = trim($kodeId ?? (string) config('siakad_api.kode_id', ''));

        foreach ($this->fetchAllAppUserRowsByIdentifier($login, $kodeId) as $row) {
            if (! $this->verifyAppPassword($row, $password)) {
                continue;
            }

            if ($this->isRawAppJenisUserDenied($row)) {
                continue;
            }

            $rawJenis = trim((string) ($this->pick($row, ['jenis_user', 'JenisUser']) ?? ''));

            return $this->formatSimutuUserPayload($row, $rawJenis, $kodeId);
        }

        return $this->attemptSimutuLoginViaKaryawanFallback($login, $password, $kodeId);
    }

    /**
     * Login via email SSO, ref_id, atau Login — tanpa menebak jenis_user di query.
     *
     * @return array<string, mixed>|null
     */
    protected function attemptAppLoginByUserRow(string $identifier, string $password, string $kodeId): ?array
    {
        foreach ($this->fetchAllAppUserRowsByIdentifier($identifier, $kodeId) as $row) {
            if (! $this->verifyAppPassword($row, $password)) {
                continue;
            }

            if ($this->isAppUserRowLoginDenied($row)) {
                continue;
            }

            $jenisUser = $this->normalizeAppJenisUser($row);
            if ($jenisUser === '' || ! $this->isAppJenisUserAllowed($jenisUser)) {
                continue;
            }

            return $this->formatAppUserPayload($row, $jenisUser, $kodeId);
        }

        return null;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    protected function fetchAllAppUserRowsByIdentifier(string $identifier, string $kodeId, bool $activeOnly = true): array
    {
        $table = $this->userTableName();
        if ($table === '') {
            return [];
        }

        $columns = $this->tableColumns($table);
        if ($columns === []) {
            return [];
        }

        $identifier = trim($identifier);
        $clauses = [];
        $params = [];

        $loginCol = $this->firstExistingColumn($columns, ['Login', 'login']);
        if ($loginCol !== null) {
            $clauses[] = sprintf('`%s` = ?', $loginCol);
            $params[] = $identifier;
        }

        $refCol = $this->firstExistingColumn($columns, ['ref_id', 'Ref_ID']);
        if ($refCol !== null && $refCol !== $loginCol) {
            $clauses[] = sprintf('`%s` = ?', $refCol);
            $params[] = $identifier;
        }

        $emailCol = $this->firstExistingColumn($columns, ['email', 'Email']);
        if ($emailCol !== null) {
            $clauses[] = sprintf('LOWER(`%s`) = ?', $emailCol);
            $params[] = strtolower($identifier);
        }

        $idCol = $this->firstExistingColumn($columns, ['id_user', 'ID', 'id']);
        if ($idCol !== null && ctype_digit($identifier)) {
            $clauses[] = sprintf('`%s` = ?', $idCol);
            $params[] = (int) $identifier;
        }

        if ($clauses === []) {
            return [];
        }

        $sql = sprintf('SELECT * FROM `%s` WHERE (%s)', $table, implode(' OR ', $clauses));

        $kodeCol = $this->firstExistingColumn($columns, ['KodeID', 'kode_id']);
        if ($kodeCol !== null && $kodeId !== '') {
            $sql .= sprintf(' AND (`%s` = ? OR `%s` IS NULL OR `%s` = \'\')', $kodeCol, $kodeCol, $kodeCol);
            $params[] = $kodeId;
        }

        if ($activeOnly) {
            if ($this->firstExistingColumn($columns, ['status_user']) !== null) {
                $sql .= ' AND `status_user` = 1';
            } elseif ($this->firstExistingColumn($columns, ['NA']) !== null) {
                $sql .= " AND (`NA` = 'N' OR `NA` IS NULL OR `NA` = '')";
            }
        }

        $orderParts = [];
        if ($this->firstExistingColumn($columns, ['status_user']) !== null) {
            $orderParts[] = '`status_user` DESC';
        }
        if ($this->firstExistingColumn($columns, ['jenis_user']) !== null) {
            $orderParts[] = '`jenis_user` DESC';
        }
        if ($orderParts !== []) {
            $sql .= ' ORDER BY '.implode(', ', $orderParts);
        }

        try {
            $rows = DB::connection('siakad')->select($sql, $params);
        } catch (\Throwable) {
            return [];
        }

        return array_map(static fn ($row) => (array) $row, $rows);
    }

    /**
     * @return array<string, mixed>|null
     */
    protected function fetchAppUserRowByIdentifier(string $identifier, string $kodeId, bool $activeOnly = true): ?array
    {
        $rows = $this->fetchAllAppUserRowsByIdentifier($identifier, $kodeId, $activeOnly);

        return $rows[0] ?? null;
    }

    /**
     * Normalisasi jenis_user tabel users (OBE 9–4 atau SSO 1–4 / user_type).
     */
    protected function normalizeAppJenisUser(array $row): string
    {
        $raw = trim((string) ($this->pick($row, ['jenis_user', 'JenisUser']) ?? ''));

        $ssoMap = config('siakad_api.sso_jenis_user_to_obe', []);
        if ($raw !== '' && array_key_exists($raw, $ssoMap)) {
            $mapped = $ssoMap[$raw];

            return $mapped === null ? '' : (string) $mapped;
        }

        if (in_array($raw, ['9', '8', '7', '6', '5', '4'], true)) {
            return $raw;
        }

        $userType = strtolower(trim((string) ($this->pick($row, ['user_type', 'User_type']) ?? '')));
        if ($userType !== '') {
            $typeMap = config('siakad_api.sso_user_type_to_obe', []);
            if (array_key_exists($userType, $typeMap)) {
                $mapped = $typeMap[$userType];

                return $mapped === null ? '' : (string) $mapped;
            }
        }

        $level = trim((string) ($this->pick($row, ['LevelID', 'level_id']) ?? ''));
        if ($level !== '') {
            $levelMap = config('siakad_api.sso_level_id_to_obe', []);
            if (isset($levelMap[$level])) {
                return (string) $levelMap[$level];
            }
        }

        return $raw;
    }

    protected function isAppJenisUserAllowed(string $jenisUser): bool
    {
        if ($jenisUser === '') {
            return false;
        }

        $denied = config('siakad_api.app_login_denied_jenis_user', []);
        if (in_array($jenisUser, $denied, true)) {
            return false;
        }

        $allowed = config('siakad_api.app_login_jenis_user', []);

        return in_array($jenisUser, $allowed, true);
    }

    /**
     * Cek jenis_user mentah di DB — jenis 0/1 (tamu/PMB) tidak boleh login aplikasi pendukung.
     *
     * @param  array<string, mixed>  $row
     */
    protected function isRawAppJenisUserDenied(array $row): bool
    {
        $raw = trim((string) ($this->pick($row, ['jenis_user', 'JenisUser']) ?? ''));
        if ($raw === '') {
            return true;
        }

        $denied = config('siakad_api.app_login_denied_jenis_user', []);

        return in_array($raw, $denied, true);
    }

    /**
     * Apakah baris users tidak boleh login aplikasi OBE (setelah normalisasi SSO → OBE).
     *
     * @param  array<string, mixed>  $row
     */
    protected function isAppUserRowLoginDenied(array $row): bool
    {
        $normalized = $this->normalizeAppJenisUser($row);
        if ($normalized === '') {
            return true;
        }

        return ! $this->isAppJenisUserAllowed($normalized);
    }

    /**
     * Cari akun SSO aktif di tabel users (tanpa verifikasi password).
     * Dipakai SI-Tercapai saat menambah pengguna — wajib punya email SSO valid.
     *
     * @return array<string, mixed>|null
     */
    public function lookupSsoAccount(string $identifier, ?string $kodeId = null): ?array
    {
        $identifier = trim($identifier);
        if ($identifier === '') {
            return null;
        }

        $kodeId = trim($kodeId ?? (string) config('siakad_api.kode_id', ''));
        $row = $this->fetchAppUserRowByIdentifier($identifier, $kodeId);
        if ($row === null || $this->isRawAppJenisUserDenied($row)) {
            return null;
        }

        $email = strtolower(trim((string) ($this->pick($row, ['email', 'Email']) ?? '')));
        if ($email === '' || ! filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return null;
        }

        $jenisUser = $this->normalizeAppJenisUser($row);
        if (! $this->isAppJenisUserAllowed($jenisUser)) {
            return null;
        }

        $payload = $this->formatAppUserPayload($row, $jenisUser, $kodeId);
        $payload['email'] = $email;
        $payload['sso_active'] = true;

        return $payload;
    }

    /**
     * @param  'mysql_legacy'|'password_hash'  $mode
     * @return array<string, mixed>|null
     */
    protected function attemptLogin(
        string $login,
        ?string $password,
        string $levelId,
        string $kodeId,
        string $mode,
    ): ?array {
        if (! $this->passwordOptionalForLevel($levelId) && ($password === null || $password === '')) {
            return null;
        }

        foreach ($this->authSourcesForLevel($levelId) as $source) {
            $row = $this->fetchRowFromSource($login, $kodeId, $source);
            if ($row === null) {
                continue;
            }

            if ($this->passwordOptionalForLevel($levelId)) {
                return $this->formatUserPayload($row, $levelId, $kodeId, $source);
            }

            $ok = $mode === 'password_hash'
                ? $this->verifyModernPassword($row, (string) $password)
                : $this->verifyMysqlLegacyPassword($row, (string) $password);

            if ($ok) {
                return $this->formatUserPayload($row, $levelId, $kodeId, $source);
            }
        }

        return null;
    }

    /**
     * Sumber autentikasi: karyawan (LevelID) lalu user (jenis_user).
     *
     * @return list<array{table: string, role_column: string, role_value: string, source_key: string}>
     */
    protected function authSourcesForLevel(string $levelId): array
    {
        $roles = config('siakad_api.sifeeder_roles', []);
        $map = $roles[(string) $levelId] ?? null;
        if (! is_array($map)) {
            return [];
        }

        $sources = [];

        $kLevel = (string) ($map['karyawan_level_id'] ?? '');
        if ($kLevel !== '') {
            $sources[] = [
                'table' => 'karyawan',
                'role_column' => 'LevelID',
                'role_value' => $kLevel,
                'source_key' => 'karyawan',
            ];
        }

        $jUser = (string) ($map['user_jenis_user'] ?? '');
        if ($jUser !== '' && $this->tableExists('user')) {
            $sources[] = [
                'table' => 'user',
                'role_column' => 'jenis_user',
                'role_value' => $jUser,
                'source_key' => 'user',
            ];
        }

        return $sources;
    }

    /**
     * @param  array{table: string, role_column: string, role_value: string, source_key: string}  $source
     * @return array<string, mixed>|null
     */
    protected function fetchRowFromSource(string $login, string $kodeId, array $source): ?array
    {
        $table = $source['table'];
        if (! preg_match('/^[A-Za-z0-9_]+$/', $table)) {
            return null;
        }

        $columns = $this->tableColumns($table);
        if ($columns === []) {
            return null;
        }

        $loginCol = $this->firstExistingColumn($columns, ['Login', 'login', 'email', 'Email']);
        if ($loginCol === null) {
            return null;
        }

        $roleCandidates = [$source['role_column'], ucfirst($source['role_column']), 'JenisUser', 'Jenis_User'];
        $roleCol = $this->firstExistingColumn($columns, array_unique($roleCandidates));
        if ($roleCol === null) {
            return null;
        }

        $params = [trim($login), $source['role_value']];
        $sql = sprintf(
            'SELECT * FROM `%s` WHERE `%s` = ? AND `%s` = ?',
            $table,
            $loginCol,
            $roleCol
        );

        $kodeCol = $this->firstExistingColumn($columns, ['KodeID', 'kode_id']);
        if ($kodeCol !== null && $kodeId !== '') {
            $sql .= sprintf(' AND `%s` = ?', $kodeCol);
            $params[] = $kodeId;
        }

        if ($this->firstExistingColumn($columns, ['NA']) !== null) {
            $sql .= " AND (`NA` = 'N' OR `NA` IS NULL OR `NA` = '')";
        } elseif ($this->firstExistingColumn($columns, ['status_user']) !== null) {
            $sql .= ' AND `status_user` = 1';
        }

        $sql .= ' LIMIT 1';

        try {
            $r = DB::connection('siakad')->selectOne($sql, $params);
        } catch (\Throwable) {
            return null;
        }

        if ($r === null) {
            return null;
        }

        return (array) $r;
    }

    /**
     * Verifikasi legacy: SELECT PASSWORD(?) di MySQL, lalu cadangan hash PHP (MySQL 8+).
     *
     * @param  array<string, mixed>  $row
     */
    protected function verifyMysqlLegacyPassword(array $row, string $plain): bool
    {
        foreach (['Password', 'password'] as $key) {
            if (! array_key_exists($key, $row) || $row[$key] === null || (string) $row[$key] === '') {
                continue;
            }
            $stored = (string) $row[$key];
            if ($this->isModernPasswordHash($stored)) {
                continue;
            }
            if (SisfoMysqlNativePassword::matches($plain, $stored)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  array<string, mixed>  $row
     */
    /**
     * @param  array<string, mixed>  $row
     */
    protected function verifyAppPassword(array $row, string $plain): bool
    {
        return $this->verifyModernPassword($row, $plain)
            || $this->verifyMysqlLegacyPassword($row, $plain);
    }

    /**
     * Cadangan login Siakad-GS: verifikasi password tabel karyawan, petakan ke baris users (jenis OBE).
     *
     * @return array<string, mixed>|null
     */
    protected function attemptAppLoginViaKaryawanFallback(string $login, string $password, string $kodeId): ?array
    {
        foreach ($this->fetchKaryawanRowsByIdentifier($login, $kodeId) as $karyawanRow) {
            if (! $this->verifyKaryawanPassword($karyawanRow, $password)) {
                continue;
            }

            $levelId = trim((string) ($this->pick($karyawanRow, ['LevelID', 'level_id']) ?? ''));
            $mappedJenis = $this->mapKaryawanLevelToAppJenisUser($levelId);

            $userRow = $this->resolveUsersRowForKaryawanAuth($karyawanRow, $login, $kodeId);

            if ($userRow !== null) {
                $jenisUser = $this->normalizeAppJenisUser($userRow);
                if ($jenisUser === '' || ! $this->isAppJenisUserAllowed($jenisUser)) {
                    continue;
                }

                $payload = $this->formatAppUserPayload($userRow, $jenisUser, $kodeId);
                if ($levelId !== '' && trim((string) ($payload['level_id'] ?? '')) === '') {
                    $payload['level_id'] = $levelId;
                }

                return $payload;
            }

            // Akun hanya di tabel karyawan (tanpa baris users) — umum di Siakad-GS.
            if ($mappedJenis === null || ! $this->isAppJenisUserAllowed($mappedJenis)) {
                continue;
            }

            return $this->formatAppUserPayloadFromKaryawan($karyawanRow, $mappedJenis, $kodeId);
        }

        return null;
    }

    /**
     * @return array<string, mixed>|null
     */
    protected function attemptSimutuLoginViaKaryawanFallback(string $login, string $password, string $kodeId): ?array
    {
        foreach ($this->fetchKaryawanRowsByIdentifier($login, $kodeId) as $karyawanRow) {
            if (! $this->verifyKaryawanPassword($karyawanRow, $password)) {
                continue;
            }

            $levelId = trim((string) ($this->pick($karyawanRow, ['LevelID', 'level_id']) ?? ''));
            $mappedJenis = $this->mapKaryawanLevelToAppJenisUser($levelId);
            if ($mappedJenis === null || $this->isRawAppJenisUserDenied(['jenis_user' => $mappedJenis])) {
                continue;
            }

            $userRow = $this->resolveUsersRowForKaryawanAuth($karyawanRow, $login, $kodeId)
                ?? $this->resolveUsersRowForKaryawanProfile($karyawanRow, $login, $kodeId);

            if ($userRow === null) {
                continue;
            }

            // Profil users bisa jenis 1 (PMB/SSO) meski karyawan LevelID = superadmin Siakad-GS.
            $userRow['jenis_user'] = $mappedJenis;

            return $this->formatSimutuUserPayload($userRow, $mappedJenis, $kodeId);
        }

        return null;
    }

    /**
     * Pemetaan LevelID karyawan (login bawaan Siakad-GS) ke jenis_user aplikasi (9–4).
     */
    protected function mapKaryawanLevelToAppJenisUser(string $levelId): ?string
    {
        if ($levelId === '') {
            return null;
        }

        foreach (config('siakad_api.sifeeder_roles', []) as $role) {
            if ((string) ($role['karyawan_level_id'] ?? '') === $levelId) {
                $jenis = trim((string) ($role['user_jenis_user'] ?? ''));

                return $jenis !== '' ? $jenis : null;
            }
        }

        $levelMap = config('siakad_api.sso_level_id_to_obe', []);
        if (array_key_exists($levelId, $levelMap)) {
            $mapped = $levelMap[$levelId];

            return $mapped === null ? null : (string) $mapped;
        }

        return null;
    }

    /**
     * Ambil baris users untuk profil (termasuk jenis 0/1) setelah auth karyawan berhasil.
     *
     * @param  array<string, mixed>  $karyawanRow
     * @return array<string, mixed>|null
     */
    protected function resolveUsersRowForKaryawanProfile(array $karyawanRow, string $login, string $kodeId): ?array
    {
        $identifiers = array_values(array_unique(array_filter([
            strtolower(trim((string) ($this->pick($karyawanRow, ['Email', 'email']) ?? ''))),
            trim((string) ($this->pick($karyawanRow, ['KodeLogin', 'kodelogin', 'Login', 'login']) ?? '')),
            trim($login),
        ])));

        $seen = [];

        foreach ($identifiers as $identifier) {
            foreach ($this->fetchAllAppUserRowsByIdentifier($identifier, $kodeId, false) as $row) {
                $id = (int) ($this->pick($row, ['id_user', 'ID', 'id']) ?? 0);
                if ($id > 0 && isset($seen[$id])) {
                    continue;
                }
                if ($id > 0) {
                    $seen[$id] = true;
                }

                return $row;
            }
        }

        return null;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    protected function fetchKaryawanRowsByIdentifier(string $identifier, string $kodeId): array
    {
        if (! $this->tableExists('karyawan')) {
            return [];
        }

        $identifier = trim($identifier);
        if ($identifier === '') {
            return [];
        }

        $loginValues = [$identifier];
        $emailValues = [strtolower($identifier)];

        // Email SSO sering punya ref_id (mis. bashar) — cari juga baris karyawan.Login terkait.
        foreach ($this->fetchAllAppUserRowsByIdentifier($identifier, $kodeId, false) as $userRow) {
            $ref = trim((string) ($this->pick($userRow, ['ref_id', 'Ref_ID']) ?? ''));
            if ($ref !== '') {
                $loginValues[] = $ref;
            }
        }

        $loginValues = array_values(array_unique($loginValues));
        $emailValues = array_values(array_unique($emailValues));

        $columns = $this->tableColumns('karyawan');
        $loginColumns = array_values(array_unique(array_filter([
            $this->firstExistingColumn($columns, ['KodeLogin', 'kodelogin']),
            $this->firstExistingColumn($columns, ['Login', 'login']),
        ])));
        $emailColumn = $this->firstExistingColumn($columns, ['Email', 'email']);

        if ($loginColumns === [] && $emailColumn === null) {
            return [];
        }

        $conditions = [];
        $params = [];

        if ($loginColumns !== []) {
            $loginPlaceholders = implode(',', array_fill(0, count($loginValues), '?'));
            foreach ($loginColumns as $loginColumn) {
                $conditions[] = sprintf('`%s` IN (%s)', $loginColumn, $loginPlaceholders);
                $params = array_merge($params, $loginValues);
            }
        }

        if ($emailColumn !== null) {
            $emailPlaceholders = implode(',', array_fill(0, count($emailValues), '?'));
            $conditions[] = sprintf('LOWER(`%s`) IN (%s)', $emailColumn, $emailPlaceholders);
            $params = array_merge($params, $emailValues);
        }

        $sql = 'SELECT * FROM `karyawan` WHERE ('.implode(' OR ', $conditions).')';

        if ($kodeId !== '') {
            $sql .= " AND (`KodeID` = ? OR `KodeID` IS NULL OR `KodeID` = '')";
            $params[] = $kodeId;
        }

        $sql .= " AND (`NA` = 'N' OR `NA` IS NULL OR `NA` = '')";
        $sql .= ' ORDER BY `LevelID` ASC';

        try {
            $rows = DB::connection('siakad')->select($sql, $params);
        } catch (\Throwable) {
            return [];
        }

        return array_map(static fn ($row) => (array) $row, $rows);
    }

    /**
     * @param  array<string, mixed>  $karyawanRow
     * @return array<string, mixed>|null
     */
    protected function resolveUsersRowForKaryawanAuth(array $karyawanRow, string $login, string $kodeId): ?array
    {
        $identifiers = array_values(array_unique(array_filter([
            strtolower(trim((string) ($this->pick($karyawanRow, ['Email', 'email']) ?? ''))),
            trim((string) ($this->pick($karyawanRow, ['KodeLogin', 'kodelogin', 'Login', 'login']) ?? '')),
            trim($login),
        ])));

        $seen = [];

        foreach ($identifiers as $identifier) {
            foreach ($this->fetchAllAppUserRowsByIdentifier($identifier, $kodeId) as $row) {
                $id = (int) ($this->pick($row, ['id_user', 'ID', 'id']) ?? 0);
                if ($id > 0 && isset($seen[$id])) {
                    continue;
                }
                if ($id > 0) {
                    $seen[$id] = true;
                }

                if ($this->isRawAppJenisUserDenied($row)) {
                    continue;
                }

                return $row;
            }
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $row
     */
    protected function verifyKaryawanPassword(array $row, string $plain): bool
    {
        return $this->verifyMysqlLegacyPassword($row, $plain)
            || $this->verifyModernPassword($row, $plain);
    }

    protected function verifyModernPassword(array $row, string $plain): bool
    {
        foreach (['argon', 'argon_password', 'Password', 'password'] as $key) {
            if (! array_key_exists($key, $row) || $row[$key] === null || (string) $row[$key] === '') {
                continue;
            }
            $stored = (string) $row[$key];
            if ($this->isModernPasswordHash($stored) && password_verify($plain, $stored)) {
                return true;
            }
        }

        return false;
    }

    protected function isModernPasswordHash(string $stored): bool
    {
        $info = password_get_info($stored);
        $algo = $info['algo'] ?? null;

        // PHP 8+: bcrypt memakai algo string "2y", bukan int.
        if (is_int($algo) && $algo !== 0) {
            return true;
        }

        if (is_string($algo) && $algo !== '' && $algo !== '0') {
            return true;
        }

        return ($info['algoName'] ?? 'unknown') !== 'unknown';
    }

    public function passwordOptionalForLevel(string $levelId): bool
    {
        $ids = config('siakad_api.auth_password_optional_level_ids', []);

        return in_array((string) $levelId, array_map('strval', $ids), true);
    }

    /**
     * @param  array<string, mixed>  $row
     * @param  array{table: string, role_column: string, role_value: string, source_key: string}  $source
     * @return array<string, mixed>
     */
    protected function formatUserPayload(array $row, string $levelId, string $kodeId, array $source): array
    {
        $jenisUser = $this->pick($row, ['jenis_user', 'JenisUser'])
            ?? ($source['source_key'] === 'user' ? $source['role_value'] : null);

        return array_merge(
            $this->formatAppUserPayload($row, (string) $jenisUser, $kodeId),
            [
                'level_id' => $levelId,
                'level_label' => (string) (config('siakad_api.sifeeder_roles')[(string) $levelId]['label'] ?? ''),
                'tabel_user' => $source['table'],
                'auth_source' => $source['source_key'],
            ]
        );
    }

    /**
     * @param  array<string, mixed>  $row
     * @return array<string, mixed>
     */
    protected function formatAppUserPayload(array $row, string $jenisUser, string $kodeId = ''): array
    {
        $login = $this->pick($row, ['ref_id', 'Ref_ID', 'Login', 'login', 'email', 'Email']);
        $nama = $this->pick($row, ['Nama', 'nama', 'nama_user', 'NamaUser']);
        $prodi = $this->pick($row, ['ProdiID', 'prodiid', 'prodi_id', 'ProdiID']);
        $su = $this->pick($row, ['Superuser', 'superuser']);
        $labels = config('siakad_api.jenis_user_labels', []);

        return [
            'login' => $login ?? '',
            'nama' => $nama ?? '',
            'jenis_user' => $jenisUser,
            'jenis_user_label' => (string) ($labels[$jenisUser] ?? ''),
            'level_id' => trim((string) ($this->pick($row, ['LevelID', 'level_id', 'levelid']) ?? '')),
            'kode_id' => $kodeId,
            'prodi_id' => $prodi ?? '',
            'email' => $this->pick($row, ['email', 'Email']),
            'superuser' => ($su !== null && $su !== '') ? $su : (in_array($jenisUser, ['9', '8'], true) ? 'Y' : 'N'),
            'auth_source' => 'user',
            'siakad_user_id' => (int) ($this->pick($row, ['id_user', 'ID', 'id', 'Id']) ?? 0),
            'status' => $this->resolveUserStatus($row),
        ];
    }

    /**
     * Profil login dari tabel karyawan saja (tanpa baris users).
     *
     * @param  array<string, mixed>  $karyawanRow
     * @return array<string, mixed>
     */
    protected function formatAppUserPayloadFromKaryawan(array $karyawanRow, string $jenisUser, string $kodeId = ''): array
    {
        $login = $this->pick($karyawanRow, ['KodeLogin', 'kodelogin', 'Login', 'login', 'Email', 'email']);
        $nama = $this->pick($karyawanRow, ['Nama', 'nama', 'NamaKaryawan', 'namakaryawan']);
        $levelId = trim((string) ($this->pick($karyawanRow, ['LevelID', 'level_id']) ?? ''));
        $labels = config('siakad_api.jenis_user_labels', []);

        return [
            'login' => $login ?? '',
            'nama' => $nama ?? '',
            'jenis_user' => $jenisUser,
            'jenis_user_label' => (string) ($labels[$jenisUser] ?? ''),
            'level_id' => $levelId,
            'kode_id' => $kodeId,
            'prodi_id' => $this->pick($karyawanRow, ['ProdiID', 'prodiid', 'prodi_id']) ?? '',
            'email' => $this->pick($karyawanRow, ['Email', 'email']),
            'superuser' => in_array($jenisUser, ['9', '8'], true) ? 'Y' : 'N',
            'auth_source' => 'karyawan',
            'siakad_user_id' => $this->resolveKaryawanSiakadUserId($karyawanRow, $login ?? ''),
            'status' => 'aktif',
        ];
    }

    /**
     * ID unik untuk akun karyawan-only (tanpa baris users).
     *
     * @param  array<string, mixed>  $karyawanRow
     */
    protected function resolveKaryawanSiakadUserId(array $karyawanRow, string $login): string
    {
        $karyawanId = trim((string) ($this->pick($karyawanRow, ['KaryawanID', 'karyawan_id', 'ID', 'id']) ?? ''));
        if ($karyawanId !== '' && $karyawanId !== '0') {
            return $karyawanId;
        }

        $login = trim($login);

        return $login !== '' ? $login : 'karyawan';
    }

    /**
     * Profil user untuk integrasi SiMutu (lookup tanpa password).
     *
     * @return array<string, mixed>|null
     */
    public function lookupUserProfileByLogin(string $identifier, ?string $kodeId = null, bool $activeOnly = false): ?array
    {
        $identifier = trim($identifier);
        if ($identifier === '') {
            return null;
        }

        $kodeId = trim($kodeId ?? (string) config('siakad_api.kode_id', ''));
        $row = $this->fetchAppUserRowByIdentifier($identifier, $kodeId, $activeOnly);
        if ($row === null) {
            return null;
        }

        $rawJenis = trim((string) ($this->pick($row, ['jenis_user', 'JenisUser']) ?? ''));
        if ($rawJenis === '') {
            return null;
        }

        return $this->formatSimutuUserPayload($row, $rawJenis, $kodeId);
    }

    /**
     * @param  array<string, mixed>  $row
     * @return array<string, mixed>
     */
    public function formatSimutuUserPayload(array $row, string $jenisUser, string $kodeId = ''): array
    {
        $rawJenis = (int) trim((string) ($this->pick($row, ['jenis_user', 'JenisUser']) ?? $jenisUser));
        $login = (string) ($this->pick($row, ['ref_id', 'Ref_ID', 'Login', 'login', 'email', 'Email']) ?? '');
        $nama = (string) ($this->pick($row, ['Nama', 'nama', 'nama_user', 'NamaUser']) ?? '');
        $prodiId = (string) ($this->pick($row, ['ProdiID', 'prodiid', 'prodi_id']) ?? '');
        $labels = config('siakad_api.jenis_user_labels', []);

        return [
            'siakad_user_id' => (int) ($this->pick($row, ['id_user', 'ID', 'id', 'Id']) ?? 0),
            'username' => $login,
            'login' => $login,
            'nama' => $nama,
            'email' => $this->pick($row, ['email', 'Email']),
            'jenis_user' => $rawJenis,
            'jenis_user_label' => (string) ($labels[(string) $rawJenis] ?? ''),
            'status' => $this->resolveUserStatus($row),
            'prodi_id' => $prodiId,
            'unit' => [
                'id' => $prodiId !== '' ? $prodiId : null,
                'nama_unit' => $prodiId !== '' ? ('Prodi '.$prodiId) : null,
            ],
            'kode_id' => $kodeId,
        ];
    }

    /**
     * @param  array<string, mixed>  $row
     */
    protected function resolveUserStatus(array $row): string
    {
        $statusUser = $this->pick($row, ['status_user', 'StatusUser']);
        if ($statusUser === '1' || strtolower((string) $statusUser) === 'aktif') {
            return 'aktif';
        }

        $na = $this->pick($row, ['NA', 'na']);
        if ($na === null || $na === '' || strtoupper($na) === 'N') {
            return 'aktif';
        }

        return 'nonaktif';
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
