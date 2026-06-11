<?php

namespace App\Console\Commands;

use App\Services\SiakadAuthService;
use App\Support\SisfoMysqlNativePassword;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class DiagnoseLoginCommand extends Command
{
    protected $signature = 'siakad:diagnose-login
                            {identifier : Email, username/ref_id, atau Login karyawan}
                            {--password= : Opsional — uji password tanpa menampilkan hash}
                            {--kode-id= : Override SIAKAD_KODE_ID}';

    protected $description = 'Diagnostik login SSO: baris users/karyawan yang cocok (tanpa menampilkan password/hash).';

    public function handle(SiakadAuthService $auth): int
    {
        $identifier = trim((string) $this->argument('identifier'));
        $kodeId = trim((string) ($this->option('kode-id') ?: config('siakad_api.kode_id', '')));
        $password = $this->option('password');

        $this->info('Siakad DB: '.config('database.connections.siakad.database')
            .' @ '.config('database.connections.siakad.host')
            .':'.config('database.connections.siakad.port'));
        $this->info('KodeID: '.($kodeId !== '' ? $kodeId : '(kosong)'));
        $this->newLine();

        try {
            DB::connection('siakad')->select('SELECT 1');
        } catch (\Throwable $e) {
            $this->error('Koneksi siakad gagal: '.$e->getMessage());

            return self::FAILURE;
        }

        $userTable = (string) config('siakad_api.user_table', 'users');
        $this->line('=== Tabel users ('.$userTable.') ===');

        $userRows = DB::connection('siakad')->select(
            "SELECT id_user, jenis_user, email, ref_id, KodeID, status_user,
                    CASE WHEN argon_password IS NOT NULL AND argon_password != '' THEN 'ya' ELSE 'tidak' END AS punya_argon
             FROM `{$userTable}`
             WHERE LOWER(email) = LOWER(?) OR ref_id = ? OR CAST(id_user AS CHAR) = ?
             ORDER BY jenis_user DESC",
            [$identifier, $identifier, $identifier]
        );

        if ($userRows === []) {
            $this->warn('Tidak ada baris users untuk identifier ini.');
        } else {
            $this->table(
                ['id_user', 'jenis_user', 'email', 'ref_id', 'KodeID', 'status_user', 'argon'],
                array_map(static fn ($r) => (array) $r, $userRows)
            );
        }

        $this->newLine();
        $this->line('=== Tabel karyawan ===');

        $loginValues = [$identifier];
        $emailValues = [strtolower($identifier)];
        foreach ($userRows as $userRow) {
            $userRow = (array) $userRow;
            $ref = trim((string) ($userRow['ref_id'] ?? ''));
            if ($ref !== '') {
                $loginValues[] = $ref;
            }
        }
        $loginValues = array_values(array_unique($loginValues));
        $emailValues = array_values(array_unique($emailValues));

        $loginPh = implode(',', array_fill(0, count($loginValues), '?'));
        $emailPh = implode(',', array_fill(0, count($emailValues), '?'));
        $karyawanParams = array_merge($loginValues, $emailValues);

        $karyawanSql = "SELECT Login, Email, LevelID, KodeID, NA,
                CASE WHEN Password IS NOT NULL AND Password != '' THEN CONCAT(LEFT(Password, 8), '...') ELSE '' END AS pwd_hint
             FROM karyawan
             WHERE (Login IN ({$loginPh}) OR LOWER(Email) IN ({$emailPh}))";

        if ($kodeId !== '') {
            $karyawanSql .= " AND (`KodeID` = ? OR `KodeID` IS NULL OR `KodeID` = '')";
            $karyawanParams[] = $kodeId;
        }

        $karyawanSql .= ' ORDER BY LevelID ASC';

        $karyawanRows = DB::connection('siakad')->select($karyawanSql, $karyawanParams);

        if ($karyawanRows === []) {
            $this->warn('Tidak ada baris karyawan (cek KodeID / identifier).');
        } else {
            $this->table(
                ['Login', 'Email', 'LevelID', 'KodeID', 'NA', 'pwd_hint'],
                array_map(static fn ($r) => (array) $r, $karyawanRows)
            );
        }

        if (! is_string($password) || $password === '') {
            $this->newLine();
            $this->comment('Tambahkan --password=... untuk uji verifikasi (output hanya ya/tidak).');

            return self::SUCCESS;
        }

        $this->newLine();
        $this->line('=== Uji password ===');

        foreach ($userRows as $row) {
            $row = (array) $row;
            $id = $row['id_user'] ?? '?';
            $jenis = $row['jenis_user'] ?? '?';
            $denied = in_array((string) $jenis, ['0', '1'], true) ? ' [DITOLAK jenis 0/1]' : '';
            $fullRow = (array) DB::connection('siakad')->selectOne(
                "SELECT * FROM `{$userTable}` WHERE id_user = ? LIMIT 1",
                [$id]
            );
            $argonOk = $this->verifyModern($fullRow, $password);
            $this->line("users id={$id} jenis={$jenis}{$denied} → argon/modern: ".($argonOk ? 'COCOK' : 'salah'));
        }

        foreach ($karyawanRows as $row) {
            $row = (array) $row;
            $level = $row['LevelID'] ?? '?';
            $mappedJenis = $this->mapKaryawanLevelForDiagnose((string) $level);
            $fullRow = (array) DB::connection('siakad')->selectOne(
                'SELECT * FROM karyawan WHERE Login = ? AND LevelID = ? LIMIT 1',
                [$row['Login'], $level]
            );
            $legacyOk = SisfoMysqlNativePassword::matches($password, $fullRow['Password'] ?? '');
            $modernOk = $this->verifyModern($fullRow, $password);
            $mapLabel = $mappedJenis !== null ? " → map jenis {$mappedJenis}" : ' → level tidak dipetakan';
            $this->line("karyawan Login={$row['Login']} LevelID={$level}{$mapLabel} → legacy: ".($legacyOk ? 'COCOK' : 'salah')
                .', modern: '.($modernOk ? 'COCOK' : 'salah'));
        }

        $profile = $auth->attemptSimutuLogin($identifier, $password, $kodeId);
        $this->newLine();
        $this->line('attemptSimutuLogin: '.($profile !== null
            ? 'BERHASIL (id '.($profile['siakad_user_id'] ?? '?').', jenis '.($profile['jenis_user'] ?? '?').')'
            : 'GAGAL'));

        return self::SUCCESS;
    }

    protected function mapKaryawanLevelForDiagnose(string $levelId): ?string
    {
        foreach (config('siakad_api.sifeeder_roles', []) as $role) {
            if ((string) ($role['karyawan_level_id'] ?? '') === $levelId) {
                $jenis = trim((string) ($role['user_jenis_user'] ?? ''));

                return $jenis !== '' ? $jenis : null;
            }
        }

        $levelMap = config('siakad_api.sso_level_id_to_obe', []);

        return array_key_exists($levelId, $levelMap) && $levelMap[$levelId] !== null
            ? (string) $levelMap[$levelId]
            : null;
    }

    /**
     * @param  array<string, mixed>|object|null  $row
     */
    protected function verifyModern(mixed $row, string $plain): bool
    {
        if (! is_array($row)) {
            $row = (array) $row;
        }

        foreach (['argon', 'argon_password', 'Password', 'password'] as $key) {
            if (! array_key_exists($key, $row) || $row[$key] === null || (string) $row[$key] === '') {
                continue;
            }
            $stored = (string) $row[$key];
            $info = password_get_info($stored);
            $algoName = $info['algoName'] ?? 'unknown';
            if ($algoName !== 'unknown' && password_verify($plain, $stored)) {
                return true;
            }
        }

        return false;
    }
}
