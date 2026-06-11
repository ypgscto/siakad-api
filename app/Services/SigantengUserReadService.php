<?php

namespace App\Services;

/**
 * Daftar akun Siakad yang boleh disinkronkan ke SiGanteng (read-only).
 * Mencakup users + karyawan-only; hanya jenis_user admin (9), dosen (7), karyawan (8).
 */
class SigantengUserReadService extends SipepengUserReadService
{
    /**
     * @param  array<string, bool>  $seenLogins
     * @return list<array<string, mixed>>
     */
    protected function collectFromKaryawanTable(array $seenLogins): array
    {
        if (! (bool) config('siakad_api.siganteng_user_sync.karyawan_enabled', true)) {
            return [];
        }

        return parent::collectFromKaryawanTable($seenLogins);
    }

    protected function isJenisUserAllowed(string $jenisUser): bool
    {
        $denied = config('siakad_api.siganteng_user_sync.denied_jenis_user', config('siakad_api.app_login_denied_jenis_user', []));
        if (in_array($jenisUser, array_map('strval', $denied), true)) {
            return false;
        }

        $allowed = config('siakad_api.siganteng_user_sync.allowed_jenis_user', ['9', '7', '8']);

        return in_array($jenisUser, array_map('strval', $allowed), true);
    }

    /**
     * @param  array<string, mixed>  $row
     * @return array{category: string, roles: list<string>}|null
     */
    protected function resolveCategoryAndRoles(array $row, string $jenisUser): ?array
    {
        $levelId = (string) ($this->pick($row, ['LevelID', 'level_id', 'levelid']) ?? '');
        $levelMap = config('siakad_api.siganteng_user_sync.level_id_map', []);
        if ($levelId !== '' && isset($levelMap[$levelId])) {
            $slug = (string) ($levelMap[$levelId]['role_slug'] ?? '');
            if ($slug === '') {
                return null;
            }

            return [
                'category' => (string) ($levelMap[$levelId]['category'] ?? 'pegawai'),
                'roles' => [$slug],
            ];
        }

        $jenisMap = config('siakad_api.siganteng_user_sync.jenis_user_map', []);
        if (isset($jenisMap[$jenisUser]) && is_array($jenisMap[$jenisUser])) {
            $slug = (string) ($jenisMap[$jenisUser]['role_slug'] ?? '');
            if ($slug === '') {
                return null;
            }

            return [
                'category' => (string) ($jenisMap[$jenisUser]['category'] ?? 'pegawai'),
                'roles' => [$slug],
            ];
        }

        $userType = strtolower((string) ($this->pick($row, ['user_type', 'UserType', 'tipe_user']) ?? ''));
        $typeMap = config('siakad_api.siganteng_user_sync.user_type_map', []);
        if ($userType !== '' && isset($typeMap[$userType])) {
            $slug = (string) ($typeMap[$userType]['role_slug'] ?? '');
            if ($slug === '') {
                return null;
            }

            return [
                'category' => (string) ($typeMap[$userType]['category'] ?? 'pegawai'),
                'roles' => [$slug],
            ];
        }

        return null;
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
        $payload = parent::formatPayload(
            $siakadUserId,
            $login,
            $email,
            $name,
            $category,
            $jenisUser,
            $prodiId,
            $levelId,
            $isActive,
            $roles,
            $authSource,
        );

        unset($payload['sipepeng_roles'], $payload['is_allowed_login']);
        $payload['siganteng_role_slug'] = (string) ($roles[0] ?? '');

        return $payload;
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

        $domain = (string) config('siakad_api.siganteng_user_sync.email_domain', 'stikesgunungsari.ac.id');
        $synthetic = strtolower(trim($login)).'@'.$domain;

        return filter_var($synthetic, FILTER_VALIDATE_EMAIL) ? $synthetic : null;
    }

    /**
     * Cari satu akun Siakad by email atau login (users + karyawan).
     *
     * @return array<string, mixed>|null
     */
    public function lookupByIdentifier(string $identifier): ?array
    {
        $identifier = trim($identifier);
        if ($identifier === '') {
            return null;
        }

        $needle = strtolower($identifier);

        $table = $this->userTableName();
        if ($table !== '') {
            $columns = $this->tableColumns($table);
            if ($columns !== []) {
                $row = $this->findRowByIdentifier($table, $columns, $needle);
                if ($row !== null) {
                    $payload = $this->mapUsersRow($row, $columns);
                    if ($payload !== null) {
                        return $payload;
                    }
                }
            }
        }

        if ($this->tableExists('karyawan')) {
            $columns = $this->tableColumns('karyawan');
            if ($columns !== []) {
                $row = $this->findRowByIdentifier('karyawan', $columns, $needle);
                if ($row !== null) {
                    return $this->mapKaryawanRow($row, $columns);
                }
            }
        }

        return null;
    }

    /**
     * @param  list<string>  $columns
     * @return array<string, mixed>|null
     */
    protected function findRowByIdentifier(string $table, array $columns, string $needle): ?array
    {
        $conditions = [];
        $params = [];

        foreach ([
            ['email', 'Email'],
            ['ref_id', 'Ref_ID', 'Login', 'login'],
        ] as $group) {
            $col = $this->firstExistingColumn($columns, $group);
            if ($col === null) {
                continue;
            }

            $conditions[] = sprintf('LOWER(`%s`) = ?', $col);
            $params[] = $needle;
        }

        if ($conditions === []) {
            return null;
        }

        $sql = 'SELECT * FROM `'.$table.'` WHERE ('.implode(' OR ', $conditions).')';

        $kodeId = trim((string) config('siakad_api.kode_id', ''));
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

        $sql .= ' ORDER BY ';
        $orderParts = [];
        if ($this->firstExistingColumn($columns, ['status_user']) !== null) {
            $orderParts[] = '`status_user` DESC';
        }
        if ($this->firstExistingColumn($columns, ['jenis_user']) !== null) {
            $orderParts[] = '`jenis_user` DESC';
        }
        $sql .= $orderParts !== [] ? implode(', ', $orderParts) : '1';

        try {
            $rows = \Illuminate\Support\Facades\DB::connection('siakad')->select($sql, $params);
        } catch (\Throwable) {
            return null;
        }

        foreach ($rows as $row) {
            $payload = $this->mapUsersRow((array) $row, $columns);
            if ($payload !== null) {
                return (array) $row;
            }
        }

        return null;
    }
}
