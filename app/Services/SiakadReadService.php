<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;

class SiakadReadService
{
    /**
     * Daftar semester/tahun akademik unik per TahunID (bukan per prodi).
     * Sumber: tabel tahun — semua baris (tanpa filter NA).
     *
     * @return list<array<string, mixed>>
     */
    public function semesterAktif(): array
    {
        $kodeId = $this->kodeId();
        $params = [];
        $sql = 'SELECT DISTINCT TahunID AS TahunID, TahunID AS nama
                FROM tahun
                WHERE TahunID IS NOT NULL AND TRIM(TahunID) <> \'\'';
        if ($kodeId !== '') {
            $sql .= ' AND (KodeID = ? OR KodeID IS NULL OR KodeID = \'\')';
            $params[] = $kodeId;
        }
        $sql .= ' ORDER BY TahunID DESC';

        try {
            $rows = DB::connection('siakad')->select($sql, $params);
        } catch (\Throwable) {
            return [];
        }

        $out = [];
        $seen = [];
        foreach ($rows as $r) {
            $a = (array) $r;
            $tahunId = trim((string) ($a['TahunID'] ?? ''));
            if ($tahunId === '' || isset($seen[$tahunId])) {
                continue;
            }
            $seen[$tahunId] = true;
            $out[] = [
                'id' => $tahunId,
                'siakad_id' => $tahunId,
                'kode' => $tahunId,
                'tahun_ajaran' => trim((string) ($a['nama'] ?? '')) ?: $tahunId,
                'jenis' => $this->guessJenisSemester($tahunId),
                'is_active' => true,
            ];
        }

        return $out;
    }

    /**
     * Daftar angkatan (4 digit pertama TahunID masuk) dari mahasiswa aktif.
     *
     * @return list<array{id: string, nama: string, siakad_id: string}>
     */
    public function angkatanMahasiswa(): array
    {
        $kodeId = $this->kodeId();
        $params = [];
        $sql = 'SELECT DISTINCT SUBSTRING(TRIM(TahunID), 1, 4) AS angkatan
                FROM mhsw
                WHERE (NA = \'N\' OR NA IS NULL OR NA = \'\')
                  AND TahunID IS NOT NULL AND TRIM(TahunID) <> \'\'
                  AND CHAR_LENGTH(TRIM(TahunID)) >= 4';
        if ($kodeId !== '') {
            $sql .= ' AND KodeID = ?';
            $params[] = $kodeId;
        }
        $sql .= ' ORDER BY angkatan DESC';

        try {
            $rows = DB::connection('siakad')->select($sql, $params);
        } catch (\Throwable) {
            return [];
        }

        $out = [];
        foreach ($rows as $r) {
            $a = (array) $r;
            $y = trim((string) ($a['angkatan'] ?? ''));
            if ($y === '' || ! preg_match('/^\d{4}$/', $y)) {
                continue;
            }
            $out[] = [
                'id' => $y,
                'siakad_id' => $y,
                'nama' => 'Angkatan '.$y,
            ];
        }

        return $out;
    }

    public function prodi(): array
    {
        $columns = $this->tableColumns('prodi');
        if ($columns === []) {
            return [];
        }

        $idColumn = $this->firstExistingColumn($columns, ['ProdiID', 'prodi_id', 'ID']);
        $nameColumn = $this->firstExistingColumn($columns, ['Nama', 'NamaProdi', 'name']);
        $levelColumn = $this->firstExistingColumn($columns, ['JenjangID', 'Jenjang', 'ProgramStudi']);
        $kodeIdColumn = $this->firstExistingColumn($columns, ['KodeID']);
        $activeColumn = $this->firstExistingColumn($columns, ['NA']);

        if ($idColumn === null || $nameColumn === null) {
            return [];
        }

        $sql = sprintf(
            'SELECT %s AS prodi_id, %s AS prodi_name, %s AS jenjang FROM prodi WHERE 1 = 1',
            $idColumn,
            $nameColumn,
            $levelColumn !== null ? $levelColumn : 'NULL'
        );

        $params = [];
        $kodeId = $this->kodeId();
        if ($kodeId !== '' && $kodeIdColumn !== null) {
            $sql .= sprintf(' AND (%s = ? OR %s IS NULL OR %s = \'\')', $kodeIdColumn, $kodeIdColumn, $kodeIdColumn);
            $params[] = $kodeId;
        }

        // Hanya prodi aktif: wajib NA = 'N' (kolom dinamis jika ada, selain itu konvensi Siakad: NA).
        $naCol = $activeColumn ?? 'NA';
        $sql .= sprintf(" AND %s = 'N'", $naCol);

        $sql .= ' ORDER BY prodi_id';

        $rows = DB::connection('siakad')->select($sql, $params);
        $out = [];
        foreach ($rows as $row) {
            $data = (array) $row;
            $id = trim((string) ($data['prodi_id'] ?? ''));
            if ($id === '') {
                continue;
            }

            $out[] = [
                'id' => $id,
                'siakad_id' => $id,
                'kode' => $id,
                'nama' => trim((string) ($data['prodi_name'] ?? '')),
                'jenjang' => $this->nullableString($data['jenjang'] ?? null),
                'is_active' => true,
            ];
        }

        return $out;
    }

    public function kurikulum(?string $prodiId = null): array
    {
        $kodeId = $this->kodeId();
        $params = [];
        $sql = 'SELECT KurikulumID, KurikulumKode, Nama, ProdiID, Sesi
                FROM kurikulum
                WHERE NA = \'N\'';
        if ($kodeId !== '') {
            $sql .= ' AND KodeID = ?';
            $params[] = $kodeId;
        }
        if ($prodiId !== null && trim($prodiId) !== '') {
            $sql .= ' AND ProdiID = ?';
            $params[] = trim($prodiId);
        }
        $sql .= ' ORDER BY ProdiID, KurikulumKode, KurikulumID';

        $rows = DB::connection('siakad')->select($sql, $params);
        $out = [];
        foreach ($rows as $row) {
            $data = (array) $row;
            $id = trim((string) ($data['KurikulumID'] ?? ''));
            if ($id === '') {
                continue;
            }

            $code = trim((string) ($data['KurikulumKode'] ?? ''));
            $name = trim((string) ($data['Nama'] ?? ''));
            $studyProgramId = trim((string) ($data['ProdiID'] ?? ''));
            $startYear = $this->extractYear($code, $name, $data['Sesi'] ?? null);

            $out[] = [
                'id' => $id,
                'siakad_id' => $id,
                'kode' => $code !== '' ? $code : $id,
                'nama' => $name !== '' ? $name : ('Kurikulum '.$code),
                'prodi_id' => $studyProgramId !== '' ? $studyProgramId : null,
                'study_program_external_id' => $studyProgramId !== '' ? $studyProgramId : null,
                'start_year' => $startYear,
                'is_active' => true,
            ];
        }

        return $out;
    }

    public function dosen(): array
    {
        $kodeId = $this->kodeId();
        $params = [];
        $sql = 'SELECT Login, NIDN, NIPPNS, Nama, Homebase, Email, Handphone, NA
                FROM dosen
                WHERE (NA = \'N\' OR NA IS NULL OR NA = \'\')';
        if ($kodeId !== '') {
            $sql .= ' AND KodeID = ?';
            $params[] = $kodeId;
        }
        $sql .= ' ORDER BY Login';

        try {
            $rows = DB::connection('siakad')->select($sql, $params);
        } catch (\Throwable) {
            $rows = $this->dosenFallback($kodeId);
        }

        $out = [];
        foreach ($rows as $r) {
            $a = (array) $r;
            $login = (string) ($a['Login'] ?? '');
            if ($login === '') {
                continue;
            }
            $email = $a['Email'] ?? null;
            if (($email === null || $email === '') && isset($a['Handphone'])) {
                $email = $a['Handphone'] !== '' ? (string) $a['Handphone'] : null;
            }
            $out[] = [
                'id' => $login,
                'siakad_id' => $login,
                'nidn' => isset($a['NIDN']) ? (string) $a['NIDN'] : null,
                'nip' => isset($a['NIPPNS']) ? (string) $a['NIPPNS'] : null,
                'nama' => (string) ($a['Nama'] ?? ''),
                'email' => $email ? (string) $email : null,
                'prodi_kode' => isset($a['Homebase']) ? (string) $a['Homebase'] : null,
                'is_active' => true,
            ];
        }

        return $out;
    }

    /**
     * @return list<object>
     */
    protected function dosenFallback(string $kodeId): array
    {
        $params = [];
        $sql = 'SELECT Login, NIDN, Nama, Homebase, NA FROM dosen WHERE (NA = \'N\' OR NA IS NULL OR NA = \'\')';
        if ($kodeId !== '') {
            $sql .= ' AND KodeID = ?';
            $params[] = $kodeId;
        }
        $sql .= ' ORDER BY Login';

        return DB::connection('siakad')->select($sql, $params);
    }

    public function mahasiswa(): array
    {
        $kodeId = $this->kodeId();
        $params = [];
        $sql = 'SELECT MhswID, Login, Nama, ProdiID, ProgramID, StatusAwalID, TahunID, Handphone, NA
                FROM mhsw
                WHERE (NA = \'N\' OR NA IS NULL OR NA = \'\')';
        if ($kodeId !== '') {
            $sql .= ' AND KodeID = ?';
            $params[] = $kodeId;
        }
        $sql .= ' ORDER BY MhswID';

        try {
            $rows = DB::connection('siakad')->select($sql, $params);
        } catch (\Throwable) {
            $rows = $this->mahasiswaFallback($kodeId);
        }

        $out = [];
        foreach ($rows as $r) {
            $a = (array) $r;
            $id = (string) ($a['MhswID'] ?? '');
            if ($id === '') {
                continue;
            }
            $nim = (string) ($a['Login'] ?? $id);
            $angkatan = null;
            if (! empty($a['TahunID']) && is_numeric($a['TahunID'])) {
                $angkatan = (int) substr((string) $a['TahunID'], 0, 4);
            }
            $out[] = [
                'id' => $id,
                'siakad_id' => $id,
                'nim' => $nim,
                'nama' => (string) ($a['Nama'] ?? ''),
                'email' => isset($a['Handphone']) && $a['Handphone'] !== '' ? (string) $a['Handphone'] : null,
                'prodi_kode' => isset($a['ProdiID']) ? (string) $a['ProdiID'] : null,
                'angkatan' => $angkatan,
                'status' => isset($a['StatusAwalID']) ? (string) $a['StatusAwalID'] : null,
            ];
        }

        return $out;
    }

    /**
     * @return list<object>
     */
    protected function mahasiswaFallback(string $kodeId): array
    {
        $params = [];
        $sql = 'SELECT MhswID, Login, Nama, ProdiID, ProgramID, StatusAwalID, NA FROM mhsw WHERE (NA = \'N\' OR NA IS NULL OR NA = \'\')';
        if ($kodeId !== '') {
            $sql .= ' AND KodeID = ?';
            $params[] = $kodeId;
        }
        $sql .= ' ORDER BY MhswID';

        return DB::connection('siakad')->select($sql, $params);
    }

    public function mataKuliah(): array
    {
        $kodeId = $this->kodeId();
        $params = [];
        $sql = 'SELECT mk.MKID, mk.MKKode, mk.Nama, mk.SKS, mk.Sesi, mk.KurikulumID, k.ProdiID, k.KurikulumKode
                FROM mk
                LEFT JOIN kurikulum k ON k.KurikulumID = mk.KurikulumID
                WHERE (mk.NA = \'N\' OR mk.NA IS NULL OR mk.NA = \'\')';
        if ($kodeId !== '') {
            $sql .= ' AND (mk.KodeID = ? OR mk.KodeID IS NULL OR mk.KodeID = \'\')';
            $params[] = $kodeId;
        }
        $sql .= ' ORDER BY mk.MKKode';

        try {
            $rows = DB::connection('siakad')->select($sql, $params);
        } catch (\Throwable) {
            $rows = $this->mataKuliahFallback($kodeId);
        }

        $out = [];
        foreach ($rows as $r) {
            $a = (array) $r;
            $mkid = (string) ($a['MKID'] ?? '');
            if ($mkid === '') {
                continue;
            }
            $sks = isset($a['SKS']) ? (int) $a['SKS'] : 0;
            $sesi = isset($a['Sesi']) && $a['Sesi'] !== '' && $a['Sesi'] !== null ? (int) $a['Sesi'] : null;
            $kurikulumId = isset($a['KurikulumID']) ? trim((string) $a['KurikulumID']) : '';
            $kurikulumKode = trim((string) ($a['KurikulumKode'] ?? ''));
            $out[] = [
                'id' => $mkid,
                'siakad_id' => $mkid,
                'kode_mk' => (string) ($a['MKKode'] ?? ''),
                'nama_mk' => (string) ($a['Nama'] ?? ''),
                'sks' => $sks,
                'semester_kurikulum' => $sesi,
                'prodi_kode' => isset($a['ProdiID']) ? (string) $a['ProdiID'] : null,
                'kurikulum_id' => $kurikulumId !== '' ? $kurikulumId : null,
                'kurikulum_kode' => $kurikulumKode !== '' ? $kurikulumKode : null,
                'kurikulum' => $kurikulumKode !== '' ? $kurikulumKode : ($kurikulumId !== '' ? $kurikulumId : null),
            ];
        }

        return $out;
    }

    /**
     * @return list<object>
     */
    protected function mataKuliahFallback(string $kodeId): array
    {
        $params = [];
        $sql = 'SELECT MKID, MKKode, Nama, SKS, Sesi, KurikulumID FROM mk WHERE (NA = \'N\' OR NA IS NULL OR NA = \'\')';
        if ($kodeId !== '') {
            $sql .= ' AND (KodeID = ? OR KodeID IS NULL OR KodeID = \'\')';
            $params[] = $kodeId;
        }
        $sql .= ' ORDER BY MKKode';

        return DB::connection('siakad')->select($sql, $params);
    }

    public function kelas(?string $tahunId = null): array
    {
        $kodeId = $this->kodeId();
        $params = [];
        $sql = 'SELECT j.JadwalID, j.TahunID, j.MKID, j.DosenID, j.NamaKelas, j.Kapasitas, j.JumlahMhsw,
                       j.ProdiID, j.ProgramID, mk.MKKode, mk.Nama AS nama_mk,
                       d.Nama AS nama_dosen, k.Nama AS kelas_nama
                FROM jadwal j
                LEFT JOIN mk ON mk.MKID = j.MKID
                LEFT JOIN dosen d ON d.Login = j.DosenID
                LEFT JOIN kelas k ON k.KelasID = j.NamaKelas
                WHERE (j.NA = \'N\' OR j.NA IS NULL OR j.NA = \'\')';
        if ($tahunId) {
            $sql .= ' AND j.TahunID = ?';
            $params[] = $tahunId;
        }
        if ($kodeId !== '') {
            $sql .= ' AND (j.KodeID = ? OR j.KodeID IS NULL OR j.KodeID = \'\')';
            $params[] = $kodeId;
        }
        $sql .= ' ORDER BY j.TahunID, j.JadwalID';

        $rows = DB::connection('siakad')->select($sql, $params);
        $out = [];
        foreach ($rows as $r) {
            $a = (array) $r;
            $jid = (string) ($a['JadwalID'] ?? '');
            if ($jid === '') {
                continue;
            }
            $namaKelas = trim((string) ($a['NamaKelas'] ?? ''));

            $out[] = [
                'id' => $jid,
                'siakad_id' => $jid,
                'mata_kuliah_siakad_id' => (string) ($a['MKID'] ?? ''),
                'semester_kode' => (string) ($a['TahunID'] ?? ''),
                'nama_kelas' => $namaKelas,
                'kode_kelas' => $namaKelas,
                'kelas_nama' => $this->nullableString($a['kelas_nama'] ?? null),
                'dosen_login' => isset($a['DosenID']) ? (string) $a['DosenID'] : null,
                'nama_dosen' => $this->nullableString($a['nama_dosen'] ?? null),
                'kapasitas' => isset($a['Kapasitas']) ? (int) $a['Kapasitas'] : null,
                'jumlah_mhsw' => isset($a['JumlahMhsw']) ? (int) $a['JumlahMhsw'] : null,
                'prodi_kode' => isset($a['ProdiID']) ? (string) $a['ProdiID'] : null,
                'program_kode' => isset($a['ProgramID']) ? (string) $a['ProgramID'] : null,
                'mk_kode' => isset($a['MKKode']) ? (string) $a['MKKode'] : null,
                'nama_mk' => $this->nullableString($a['nama_mk'] ?? null),
            ];
        }

        return $out;
    }

    /**
     * KRS (Kontrak Rencana Studi) — satu baris per KRSID.
     *
     * @return list<array<string, mixed>>
     */
    public function krs(?string $tahunId = null): array
    {
        $kodeId = $this->kodeId();
        $params = [];
        $sql = 'SELECT k.KRSID, k.MhswID, k.TahunID, k.JadwalID, k.MKID, k.StatusKRSID, k.Final,
                       k.NilaiAkhir, k.GradeNilai, k.BobotNilai
                FROM krs k
                WHERE (k.NA = \'N\' OR k.NA IS NULL OR k.NA = \'\')';
        if ($tahunId) {
            $sql .= ' AND k.TahunID = ?';
            $params[] = $tahunId;
        }
        if ($kodeId !== '') {
            $sql .= ' AND k.KodeID = ?';
            $params[] = $kodeId;
        }
        $sql .= ' ORDER BY k.KRSID';

        $rows = DB::connection('siakad')->select($sql, $params);
        $out = [];
        foreach ($rows as $r) {
            $a = (array) $r;
            $id = (string) ($a['KRSID'] ?? '');
            if ($id === '') {
                continue;
            }
            $out[] = [
                'id' => $id,
                'siakad_id' => $id,
                'mhsw_id' => (string) ($a['MhswID'] ?? ''),
                'tahun_id' => (string) ($a['TahunID'] ?? ''),
                'jadwal_id' => isset($a['JadwalID']) && $a['JadwalID'] !== '' && $a['JadwalID'] !== null
                    ? (string) $a['JadwalID']
                    : null,
                'mk_id' => isset($a['MKID']) ? (string) $a['MKID'] : null,
                'status_krs' => isset($a['StatusKRSID']) ? (string) $a['StatusKRSID'] : null,
                'final' => isset($a['Final']) ? (string) $a['Final'] : null,
                'nilai_angka' => $this->nullableFloat($a['NilaiAkhir'] ?? null),
                'nilai_huruf' => $this->nullableString($a['GradeNilai'] ?? null),
                'bobot' => $this->nullableFloat($a['BobotNilai'] ?? null),
            ];
        }

        return $out;
    }

    /**
     * Nilai akhir per KRS (baris yang sudah punya grade / nilai).
     *
     * @return list<array<string, mixed>>
     */
    public function nilai(?string $tahunId = null): array
    {
        $kodeId = $this->kodeId();
        $params = [];
        $sql = 'SELECT k.KRSID, k.NilaiAkhir, k.GradeNilai, k.BobotNilai, k.TahunID
                FROM krs k
                WHERE (k.NA = \'N\' OR k.NA IS NULL OR k.NA = \'\')
                  AND k.Final = \'Y\'
                  AND (
                    (k.GradeNilai IS NOT NULL AND k.GradeNilai NOT IN (\'\', \'-\'))
                    OR (k.NilaiAkhir IS NOT NULL AND TRIM(CAST(k.NilaiAkhir AS CHAR)) NOT IN (\'\', \'0\'))
                  )';
        if ($tahunId) {
            $sql .= ' AND k.TahunID = ?';
            $params[] = $tahunId;
        }
        if ($kodeId !== '') {
            $sql .= ' AND k.KodeID = ?';
            $params[] = $kodeId;
        }
        $sql .= ' ORDER BY k.KRSID';

        $rows = DB::connection('siakad')->select($sql, $params);
        $out = [];
        foreach ($rows as $r) {
            $a = (array) $r;
            $id = (string) ($a['KRSID'] ?? '');
            if ($id === '') {
                continue;
            }
            $out[] = [
                'id' => $id,
                'siakad_id' => $id,
                'krs_siakad_id' => $id,
                'nilai_angka' => $this->nullableFloat($a['NilaiAkhir'] ?? null),
                'nilai_huruf' => $this->nullableString($a['GradeNilai'] ?? null),
                'bobot' => $this->nullableFloat($a['BobotNilai'] ?? null),
                'tahun_id' => (string) ($a['TahunID'] ?? ''),
            ];
        }

        return $out;
    }

    /**
     * Mahasiswa lengkap untuk sinkron Feeder (biodata + filter layar SiFeeder).
     *
     * @return list<array<string, mixed>>
     */
    public function mahasiswaSync(
        ?string $programId = null,
        ?string $prodiId = null,
        ?string $tahunId = null,
        ?string $angkatan = null,
        ?string $statusAwalId = null,
        array $nims = [],
    ): array {
        $kodeId = $this->kodeId();
        $params = [];
        $sql = 'SELECT m.MhswID AS mhsw_id, m.Login AS nim, m.Nama AS nama, m.ProdiID AS prodi_id, m.ProgramID AS program_id,
                       m.TahunID AS tahun_id, m.StatusAwalID AS status_awal_id, sa.Nama AS status_awal_nama,
                       m.TempatLahir AS tempat_lahir, m.TanggalLahir AS tanggal_lahir, m.NamaIbu AS nama_ibu_kandung,
                       m.Kelamin AS kelamin_kode_siakad, kl.Nama AS kelamin_nama,
                       ag.Nama AS agama_nama, m.Agama AS agama_id_siakad,
                       m.Negara AS nik, m.Propinsi AS nisn_placeholder, m.Email AS email, m.Alamat AS alamat,
                       m.Handphone AS handphone, m.TotalSKSPindah AS total_sks_pindah, m.TotalSKS AS total_sks,
                       th.TglKuliahMulai AS tgl_kuliah_mulai, wn.Nama AS warganegara_nama
                FROM mhsw m
                LEFT JOIN agama ag ON m.Agama = ag.Agama
                LEFT JOIN kelamin kl ON m.Kelamin = kl.Kelamin
                LEFT JOIN warganegara wn ON m.WargaNegara = wn.WargaNegara
                LEFT JOIN statusawal sa ON m.StatusAwalID = sa.StatusAwalID
                LEFT JOIN tahun th ON th.TahunID = m.TahunID
                    AND th.ProdiID = m.ProdiID
                    AND th.ProgramID = m.ProgramID
                    AND (th.KodeID = m.KodeID OR th.KodeID IS NULL OR th.KodeID = \'\')
                WHERE (m.NA = \'N\' OR m.NA IS NULL OR m.NA = \'\')';
        if ($kodeId !== '') {
            $sql .= ' AND m.KodeID = ?';
            $params[] = $kodeId;
        }
        if ($programId !== null && trim($programId) !== '') {
            $sql .= ' AND m.ProgramID = ?';
            $params[] = trim($programId);
        }
        if ($prodiId !== null && trim($prodiId) !== '') {
            $sql .= ' AND m.ProdiID = ?';
            $params[] = trim($prodiId);
        }
        if ($tahunId !== null && trim($tahunId) !== '') {
            $sql .= ' AND m.TahunID = ?';
            $params[] = trim($tahunId);
        }
        if ($angkatan !== null && preg_match('/^\d{4}$/', trim($angkatan))) {
            $sql .= ' AND SUBSTRING(TRIM(m.TahunID), 1, 4) = ?';
            $params[] = trim($angkatan);
        }
        if ($statusAwalId !== null && trim($statusAwalId) !== '') {
            $sql .= ' AND m.StatusAwalID = ?';
            $params[] = trim($statusAwalId);
        }
        $params = $this->appendNimFilter($sql, $params, $nims, 'm.Login');
        $sql .= ' ORDER BY m.MhswID';

        try {
            $rows = DB::connection('siakad')->select($sql, $params);
        } catch (\Throwable) {
            return $this->mahasiswaSyncFallback($programId, $prodiId, $tahunId, $angkatan, $statusAwalId, $kodeId, $nims);
        }

        $out = [];
        foreach ($rows as $r) {
            $out[] = $this->mapMahasiswaSyncRow((array) $r);
        }

        return $out;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function programStudi(): array
    {
        try {
            $rows = DB::connection('siakad')->select('SELECT ProgramID AS id, Nama AS nama FROM program WHERE NA = \'N\' ORDER BY ProgramID');
        } catch (\Throwable) {
            return [];
        }

        $out = [];
        foreach ($rows as $r) {
            $a = (array) $r;
            $out[] = [
                'id' => (string) ($a['id'] ?? ''),
                'nama' => (string) ($a['nama'] ?? ''),
            ];
        }

        return $out;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function statusAwal(): array
    {
        try {
            $rows = DB::connection('siakad')->select('SELECT StatusAwalID AS id, Nama AS nama FROM statusawal ORDER BY StatusAwalID');
        } catch (\Throwable) {
            return [];
        }

        $out = [];
        foreach ($rows as $r) {
            $a = (array) $r;
            $out[] = [
                'id' => (string) ($a['id'] ?? ''),
                'nama' => (string) ($a['nama'] ?? ''),
            ];
        }

        return $out;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function statusLulus(): array
    {
        try {
            $rows = DB::connection('siakad')->select('SELECT StatusLulusID AS id, Nama AS nama FROM statuslulus ORDER BY StatusLulusID');
        } catch (\Throwable) {
            return [];
        }

        $out = [];
        foreach ($rows as $r) {
            $a = (array) $r;
            $out[] = [
                'id' => (string) ($a['id'] ?? ''),
                'nama' => (string) ($a['nama'] ?? ''),
            ];
        }

        return $out;
    }

    /**
     * KHS per semester: ip_semester = khs.IPS, ipk = khs.IP (selaras aplikasi lama SiFeeder).
     *
     * @return list<array<string, mixed>>
     */
    public function khsPerSemester(string $tahunId, ?string $programId = null, ?string $prodiId = null): array
    {
        $kodeId = $this->kodeId();
        $params = [trim($tahunId)];
        $sql = 'SELECT m.MhswID AS mhsw_id, m.Login AS nim, m.Nama AS nama, m.ProdiID AS prodi_id, m.ProgramID AS program_id,
                       k.TahunID AS tahun_id, k.IPS AS ip_semester, k.IP AS ipk, k.SKS AS sks_semester, k.TotalSKS AS total_sks, k.Biaya AS biaya
                FROM khs k
                INNER JOIN mhsw m ON m.MhswID = k.MhswID
                WHERE (k.NA = \'N\' OR k.NA IS NULL OR k.NA = \'\')
                  AND (m.NA = \'N\' OR m.NA IS NULL OR m.NA = \'\')
                  AND k.TahunID = ?';
        if ($kodeId !== '') {
            $sql .= ' AND k.KodeID = ? AND m.KodeID = ?';
            $params[] = $kodeId;
            $params[] = $kodeId;
        }
        if ($programId !== null && trim($programId) !== '') {
            $sql .= ' AND m.ProgramID = ?';
            $params[] = trim($programId);
        }
        if ($prodiId !== null && trim($prodiId) !== '') {
            $sql .= ' AND m.ProdiID = ?';
            $params[] = trim($prodiId);
        }
        $sql .= ' ORDER BY m.MhswID';

        try {
            $rows = DB::connection('siakad')->select($sql, $params);
        } catch (\Throwable) {
            return $this->khsPerSemesterFallback(trim($tahunId), $programId, $prodiId, $kodeId);
        }

        return $this->formatKhsRows($rows);
    }

    /**
     * Peserta satu kelas (jadwal) + nilai dari KRS.
     *
     * @return list<array<string, mixed>>
     */
    public function kelasPeserta(
        string $jadwalId,
        ?string $tahunId = null,
        ?string $prodiId = null,
        ?string $mkKode = null,
        ?string $namaKelas = null,
    ): array {
        $kodeId = $this->kodeId();
        $params = [trim($jadwalId)];
        $sql = 'SELECT k.KRSID AS krs_id, k.MhswID AS mhsw_id, m.Login AS nim, m.Nama AS nama_mahasiswa, k.JadwalID AS jadwal_id, j.TahunID AS tahun_id,
                       mk.MKKode AS mk_kode, j.NamaKelas AS nama_kelas,
                       k.NilaiAkhir AS nilai_angka, k.GradeNilai AS nilai_huruf, k.BobotNilai AS bobot
                FROM krs k
                INNER JOIN mhsw m ON m.MhswID = k.MhswID
                INNER JOIN jadwal j ON j.JadwalID = k.JadwalID
                LEFT JOIN mk ON mk.MKID = j.MKID
                WHERE k.JadwalID = ?
                  AND (k.NA = \'N\' OR k.NA IS NULL OR k.NA = \'\')';
        if ($kodeId !== '') {
            $sql .= ' AND k.KodeID = ?';
            $params[] = $kodeId;
        }
        if ($tahunId !== null && trim($tahunId) !== '') {
            $sql .= ' AND j.TahunID = ?';
            $params[] = trim($tahunId);
        }
        if ($prodiId !== null && trim($prodiId) !== '') {
            $sql .= ' AND m.ProdiID = ?';
            $params[] = trim($prodiId);
        }
        if ($mkKode !== null && trim($mkKode) !== '') {
            $sql .= ' AND mk.MKKode = ?';
            $params[] = trim($mkKode);
        }
        if ($namaKelas !== null && trim($namaKelas) !== '') {
            $sql .= ' AND j.NamaKelas = ?';
            $params[] = trim($namaKelas);
        }
        $sql .= ' ORDER BY k.MhswID';

        try {
            $rows = DB::connection('siakad')->select($sql, $params);
        } catch (\Throwable) {
            return [];
        }

        $out = [];
        foreach ($rows as $r) {
            $a = (array) $r;
            $out[] = [
                'krs_id' => (string) ($a['krs_id'] ?? ''),
                'mhsw_id' => (string) ($a['mhsw_id'] ?? ''),
                'nim' => (string) ($a['nim'] ?? ''),
                'nama_mahasiswa' => (string) ($a['nama_mahasiswa'] ?? ''),
                'jadwal_id' => (string) ($a['jadwal_id'] ?? ''),
                'tahun_id' => (string) ($a['tahun_id'] ?? ''),
                'mk_kode' => $this->nullableString($a['mk_kode'] ?? null),
                'nama_kelas' => $this->nullableString($a['nama_kelas'] ?? null),
                'nilai_angka' => $this->nullableFloat($a['nilai_angka'] ?? null),
                'nilai_huruf' => $this->nullableString($a['nilai_huruf'] ?? null),
                'bobot' => $this->nullableFloat($a['bobot'] ?? null),
            ];
        }

        return $out;
    }

    /**
     * Mahasiswa dengan data keluar (lulus / DO) dari tabel ta.
     * Filter: tahun_id = mhsw.TahunID tepat; angkatan = 4 digit pertama TahunID masuk (SUBSTRING).
     *
     * @return list<array<string, mixed>>
     */
    public function mahasiswaKeluar(
        ?string $programId = null,
        ?string $prodiId = null,
        ?string $tahunId = null,
        ?string $angkatan = null,
        ?string $statusLulusId = null,
    ): array {
        $kodeId = $this->kodeId();
        $params = [];
        $sql = 'SELECT m.MhswID AS mhsw_id, m.Login AS nim, m.Nama AS nama, m.ProdiID AS prodi_id, m.ProgramID AS program_id, m.TahunID AS tahun_id_masuk,
                       ta.TglSelesai AS tanggal_keluar, ta.NoIjazah AS nomor_ijazah, ta.StatusLulusID AS status_lulus_id, sl.Nama AS status_lulus_nama,
                       (SELECT k2.IP FROM khs k2 WHERE k2.MhswID = m.MhswID ORDER BY k2.TahunID DESC LIMIT 1) AS ipk
                FROM mhsw m
                INNER JOIN ta ON ta.MhswID = m.MhswID
                LEFT JOIN statuslulus sl ON ta.StatusLulusID = sl.StatusLulusID
                WHERE (m.NA = \'N\' OR m.NA IS NULL OR m.NA = \'\')';
        if ($kodeId !== '') {
            $sql .= ' AND m.KodeID = ?';
            $params[] = $kodeId;
        }
        if ($programId !== null && trim($programId) !== '') {
            $sql .= ' AND m.ProgramID = ?';
            $params[] = trim($programId);
        }
        if ($prodiId !== null && trim($prodiId) !== '') {
            $sql .= ' AND m.ProdiID = ?';
            $params[] = trim($prodiId);
        }
        if ($tahunId !== null && trim($tahunId) !== '') {
            $sql .= ' AND m.TahunID = ?';
            $params[] = trim($tahunId);
        }
        if ($angkatan !== null && preg_match('/^\d{4}$/', trim($angkatan))) {
            $sql .= ' AND SUBSTRING(TRIM(m.TahunID), 1, 4) = ?';
            $params[] = trim($angkatan);
        }
        if ($statusLulusId !== null && trim($statusLulusId) !== '') {
            $sql .= ' AND ta.StatusLulusID = ?';
            $params[] = trim($statusLulusId);
        }
        $sql .= ' ORDER BY m.MhswID';

        try {
            $rows = DB::connection('siakad')->select($sql, $params);
        } catch (\Throwable) {
            return [];
        }

        $out = [];
        foreach ($rows as $r) {
            $a = (array) $r;
            $out[] = [
                'mhsw_id' => (string) ($a['mhsw_id'] ?? ''),
                'nim' => (string) ($a['nim'] ?? ''),
                'nama' => (string) ($a['nama'] ?? ''),
                'prodi_id' => (string) ($a['prodi_id'] ?? ''),
                'program_id' => (string) ($a['program_id'] ?? ''),
                'tahun_id_masuk' => (string) ($a['tahun_id_masuk'] ?? ''),
                'tanggal_keluar' => $this->nullableString($a['tanggal_keluar'] ?? null),
                'nomor_ijazah' => $this->nullableString($a['nomor_ijazah'] ?? null),
                'status_lulus_id' => $this->nullableString($a['status_lulus_id'] ?? null),
                'status_lulus_nama' => $this->nullableString($a['status_lulus_nama'] ?? null),
                'ipk' => $this->nullableFloat($a['ipk'] ?? null),
            ];
        }

        return $out;
    }

    /**
     * KRS / nilai untuk mahasiswa pindahan & RPL (default status awal P, J) + join MK & jadwal.
     * Filter utama: angkatan = 4 digit pertama mhsw.TahunID (masuk). Opsional: tahun_krs = semester baris KRS.
     *
     * @return list<array<string, mixed>>
     */
    public function nilaiKonversi(
        ?string $angkatan = null,
        ?string $tahunKrs = null,
        ?string $prodiId = null,
        ?string $mhswId = null,
        ?string $statusAwalId = null,
        ?string $programId = null,
        ?string $nim = null,
    ): array {
        $kodeId = $this->kodeId();
        $params = [];
        $sql = 'SELECT k.KRSID AS krs_id, k.MhswID AS mhsw_id, m.Login AS nim, m.Nama AS nama_mahasiswa,
                       SUBSTRING(TRIM(m.TahunID), 1, 4) AS angkatan,
                       TRIM(m.TahunID) AS tahun_masuk,
                       m.ProgramID AS program_id, m.ProdiID AS prodi_id, m.StatusAwalID AS status_awal_id,
                       sa.Nama AS status_awal_nama,
                       k.TahunID AS tahun_id, k.JadwalID AS jadwal_id, k.MKID AS mk_id,
                       mk.MKKode AS mk_kode, mk.Nama AS nama_mk, mk.SKS AS sks_mk,
                       j.NamaKelas AS nama_kelas, j.TahunID AS tahun_jadwal,
                       k.StatusKRSID AS status_krs, k.Final AS final,
                       k.NilaiAkhir AS nilai_angka, k.GradeNilai AS nilai_huruf, k.BobotNilai AS bobot
                FROM krs k
                INNER JOIN mhsw m ON m.MhswID = k.MhswID
                LEFT JOIN statusawal sa ON sa.StatusAwalID = m.StatusAwalID
                LEFT JOIN mk ON mk.MKID = k.MKID
                LEFT JOIN jadwal j ON j.JadwalID = k.JadwalID
                WHERE (k.NA = \'N\' OR k.NA IS NULL OR k.NA = \'\')
                  AND (m.NA = \'N\' OR m.NA IS NULL OR m.NA = \'\')';
        if ($kodeId !== '') {
            $sql .= ' AND k.KodeID = ?';
            $params[] = $kodeId;
        }
        if ($statusAwalId !== null && trim($statusAwalId) !== '') {
            $sql .= ' AND m.StatusAwalID = ?';
            $params[] = trim($statusAwalId);
        } else {
            $sql .= ' AND m.StatusAwalID IN (\'P\', \'J\')';
        }
        if ($programId !== null && trim($programId) !== '') {
            $sql .= ' AND m.ProgramID = ?';
            $params[] = trim($programId);
        }
        if ($angkatan !== null && preg_match('/^\d{4}$/', trim($angkatan))) {
            $sql .= ' AND SUBSTRING(TRIM(m.TahunID), 1, 4) = ?';
            $params[] = trim($angkatan);
        }
        if ($tahunKrs !== null && trim($tahunKrs) !== '') {
            $sql .= ' AND k.TahunID = ?';
            $params[] = trim($tahunKrs);
        }
        if ($prodiId !== null && trim($prodiId) !== '') {
            $sql .= ' AND m.ProdiID = ?';
            $params[] = trim($prodiId);
        }
        if ($mhswId !== null && trim($mhswId) !== '') {
            $sql .= ' AND k.MhswID = ?';
            $params[] = trim($mhswId);
        }
        if ($nim !== null && trim($nim) !== '') {
            $sql .= ' AND m.Login = ?';
            $params[] = trim($nim);
        }
        $sql .= ' ORDER BY m.Login, k.TahunID, k.KRSID';

        try {
            $rows = DB::connection('siakad')->select($sql, $params);
        } catch (\Throwable) {
            return [];
        }

        $out = [];
        foreach ($rows as $r) {
            $a = (array) $r;
            $out[] = [
                'krs_id' => (string) ($a['krs_id'] ?? ''),
                'mhsw_id' => (string) ($a['mhsw_id'] ?? ''),
                'nim' => (string) ($a['nim'] ?? ''),
                'nama_mahasiswa' => (string) ($a['nama_mahasiswa'] ?? ''),
                'angkatan' => (string) ($a['angkatan'] ?? ''),
                'tahun_masuk' => (string) ($a['tahun_masuk'] ?? ''),
                'program_id' => (string) ($a['program_id'] ?? ''),
                'prodi_id' => (string) ($a['prodi_id'] ?? ''),
                'status_awal_id' => (string) ($a['status_awal_id'] ?? ''),
                'status_awal_nama' => $this->nullableString($a['status_awal_nama'] ?? null),
                'tahun_id' => (string) ($a['tahun_id'] ?? ''),
                'jadwal_id' => $this->nullableString($a['jadwal_id'] ?? null),
                'mk_id' => $this->nullableString($a['mk_id'] ?? null),
                'mk_kode' => $this->nullableString($a['mk_kode'] ?? null),
                'nama_mk' => $this->nullableString($a['nama_mk'] ?? null),
                'sks_mk' => isset($a['sks_mk']) ? (is_numeric($a['sks_mk']) ? (float) $a['sks_mk'] : null) : null,
                'nama_kelas' => $this->nullableString($a['nama_kelas'] ?? null),
                'tahun_jadwal' => $this->nullableString($a['tahun_jadwal'] ?? null),
                'status_krs' => $this->nullableString($a['status_krs'] ?? null),
                'final' => $this->nullableString($a['final'] ?? null),
                'nilai_angka' => $this->nullableFloat($a['nilai_angka'] ?? null),
                'nilai_huruf' => $this->nullableString($a['nilai_huruf'] ?? null),
                'bobot' => $this->nullableFloat($a['bobot'] ?? null),
            ];
        }

        return $out;
    }

    /**
     * @param  list<object>  $rows
     * @return list<array<string, mixed>>
     */
    protected function formatKhsRows(array $rows): array
    {
        $out = [];
        foreach ($rows as $r) {
            $a = (array) $r;
            $out[] = [
                'mhsw_id' => (string) ($a['mhsw_id'] ?? ''),
                'nim' => (string) ($a['nim'] ?? ''),
                'nama' => (string) ($a['nama'] ?? ''),
                'prodi_id' => (string) ($a['prodi_id'] ?? ''),
                'program_id' => (string) ($a['program_id'] ?? ''),
                'tahun_id' => (string) ($a['tahun_id'] ?? ''),
                'ip_semester' => $this->nullableFloat($a['ip_semester'] ?? null),
                'ipk' => $this->nullableFloat($a['ipk'] ?? null),
                'sks_semester' => isset($a['sks_semester']) && is_numeric($a['sks_semester']) ? (int) $a['sks_semester'] : null,
                'total_sks' => isset($a['total_sks']) && is_numeric($a['total_sks']) ? (int) $a['total_sks'] : null,
                'biaya' => $this->nullableFloat($a['biaya'] ?? null),
            ];
        }

        return $out;
    }

    /**
     * @return list<array<string, mixed>>
     */
    protected function khsPerSemesterFallback(string $tahunId, ?string $programId, ?string $prodiId, string $kodeId): array
    {
        $params = [$tahunId];
        $sql = 'SELECT m.MhswID AS mhsw_id, m.Login AS nim, m.Nama AS nama, m.ProdiID AS prodi_id, m.ProgramID AS program_id,
                       k.TahunID AS tahun_id, k.IPS AS ip_semester, k.IP AS ipk, k.SKS AS sks_semester, NULL AS total_sks, k.Biaya AS biaya
                FROM khs k
                INNER JOIN mhsw m ON m.MhswID = k.MhswID
                WHERE (k.NA = \'N\' OR k.NA IS NULL OR k.NA = \'\')
                  AND (m.NA = \'N\' OR m.NA IS NULL OR m.NA = \'\')
                  AND k.TahunID = ?';
        if ($kodeId !== '') {
            $sql .= ' AND k.KodeID = ? AND m.KodeID = ?';
            $params[] = $kodeId;
            $params[] = $kodeId;
        }
        if ($programId !== null && trim($programId) !== '') {
            $sql .= ' AND m.ProgramID = ?';
            $params[] = trim($programId);
        }
        if ($prodiId !== null && trim($prodiId) !== '') {
            $sql .= ' AND m.ProdiID = ?';
            $params[] = trim($prodiId);
        }
        $sql .= ' ORDER BY m.MhswID';

        try {
            $rows = DB::connection('siakad')->select($sql, $params);
        } catch (\Throwable) {
            return [];
        }

        return $this->formatKhsRows($rows);
    }

    /**
     * @return list<array<string, mixed>>
     */
    protected function mahasiswaSyncFallback(
        ?string $programId,
        ?string $prodiId,
        ?string $tahunId,
        ?string $angkatan,
        ?string $statusAwalId,
        string $kodeId,
        array $nims = [],
    ): array {
        $params = [];
        $sql = 'SELECT MhswID AS mhsw_id, Login AS nim, Nama AS nama, ProdiID AS prodi_id, ProgramID AS program_id,
                       TahunID AS tahun_id, StatusAwalID AS status_awal_id, NULL AS status_awal_nama,
                       TempatLahir AS tempat_lahir, TanggalLahir AS tanggal_lahir, NamaIbu AS nama_ibu_kandung,
                       Kelamin AS kelamin_kode_siakad, NULL AS kelamin_nama, NULL AS agama_nama, Agama AS agama_id_siakad,
                       Negara AS nik, Propinsi AS nisn_placeholder, Email AS email, Alamat AS alamat,
                       Handphone AS handphone, TotalSKSPindah AS total_sks_pindah, TotalSKS AS total_sks,
                       NULL AS tgl_kuliah_mulai, NULL AS warganegara_nama
                FROM mhsw
                WHERE (NA = \'N\' OR NA IS NULL OR NA = \'\')';
        if ($kodeId !== '') {
            $sql .= ' AND KodeID = ?';
            $params[] = $kodeId;
        }
        if ($programId !== null && trim($programId) !== '') {
            $sql .= ' AND ProgramID = ?';
            $params[] = trim($programId);
        }
        if ($prodiId !== null && trim($prodiId) !== '') {
            $sql .= ' AND ProdiID = ?';
            $params[] = trim($prodiId);
        }
        if ($tahunId !== null && trim($tahunId) !== '') {
            $sql .= ' AND TahunID = ?';
            $params[] = trim($tahunId);
        }
        if ($angkatan !== null && preg_match('/^\d{4}$/', trim($angkatan))) {
            $sql .= ' AND SUBSTRING(TRIM(TahunID), 1, 4) = ?';
            $params[] = trim($angkatan);
        }
        if ($statusAwalId !== null && trim($statusAwalId) !== '') {
            $sql .= ' AND StatusAwalID = ?';
            $params[] = trim($statusAwalId);
        }
        $params = $this->appendNimFilter($sql, $params, $nims, 'Login');
        $sql .= ' ORDER BY MhswID';

        try {
            $rows = DB::connection('siakad')->select($sql, $params);
        } catch (\Throwable) {
            return [];
        }

        $out = [];
        foreach ($rows as $r) {
            $out[] = $this->mapMahasiswaSyncRow((array) $r);
        }

        return $out;
    }

    /**
     * @param  list<string>  $nims
     * @param  list<mixed>  $params
     * @return list<mixed>
     */
    protected function appendNimFilter(string &$sql, array $params, array $nims, string $column): array
    {
        $nims = array_values(array_filter(array_map('trim', $nims), fn (string $nim) => $nim !== ''));
        if ($nims === []) {
            return $params;
        }

        $placeholders = implode(',', array_fill(0, count($nims), '?'));
        $sql .= " AND {$column} IN ({$placeholders})";
        array_push($params, ...$nims);

        return $params;
    }

    /**
     * @param  array<string, mixed>  $a
     * @return array<string, mixed>
     */
    protected function mapMahasiswaSyncRow(array $a): array
    {
        $kelaminNama = strtolower((string) ($a['kelamin_nama'] ?? ''));
        $jenisKelaminFeeder = (str_contains($kelaminNama, 'pria') || str_contains($kelaminNama, 'laki')) ? 'L' : 'P';

        return [
            'mhsw_id' => (string) ($a['mhsw_id'] ?? ''),
            'nim' => (string) ($a['nim'] ?? ''),
            'nama' => (string) ($a['nama'] ?? ''),
            'prodi_id' => (string) ($a['prodi_id'] ?? ''),
            'program_id' => (string) ($a['program_id'] ?? ''),
            'tahun_id' => (string) ($a['tahun_id'] ?? ''),
            'status_awal_id' => (string) ($a['status_awal_id'] ?? ''),
            'status_awal_nama' => $this->nullableString($a['status_awal_nama'] ?? null),
            'tempat_lahir' => $this->nullableString($a['tempat_lahir'] ?? null),
            'tanggal_lahir' => $this->nullableString($a['tanggal_lahir'] ?? null),
            'nama_ibu_kandung' => $this->nullableString($a['nama_ibu_kandung'] ?? null),
            'kelamin_kode_siakad' => $this->nullableString($a['kelamin_kode_siakad'] ?? null),
            'kelamin_nama' => $this->nullableString($a['kelamin_nama'] ?? null),
            'jenis_kelamin_feeder' => $jenisKelaminFeeder,
            'agama_id_siakad' => $this->nullableString($a['agama_id_siakad'] ?? null),
            'agama_nama' => $this->nullableString($a['agama_nama'] ?? null),
            'nik' => $this->nullableString($a['nik'] ?? null),
            'nisn_placeholder' => $this->nullableString($a['nisn_placeholder'] ?? null),
            'email' => $this->nullableString($a['email'] ?? null),
            'alamat' => $this->nullableString($a['alamat'] ?? null),
            'handphone' => $this->nullableString($a['handphone'] ?? null),
            'total_sks_pindah' => $this->nullableString($a['total_sks_pindah'] ?? null),
            'total_sks' => isset($a['total_sks']) && is_numeric($a['total_sks']) ? (int) $a['total_sks'] : null,
            'sks_diakui' => $this->resolveSksDiakui($a),
            'tgl_kuliah_mulai' => $this->formatDateYmd($a['tgl_kuliah_mulai'] ?? null),
            'warganegara_nama' => $this->nullableString($a['warganegara_nama'] ?? null),
        ];
    }

    /**
     * @param  array<string, mixed>  $a
     */
    protected function resolveSksDiakui(array $a): ?int
    {
        $pindah = trim((string) ($a['total_sks_pindah'] ?? ''));
        if ($pindah !== '' && is_numeric($pindah)) {
            return max(0, (int) $pindah);
        }

        $totalSks = $a['total_sks'] ?? null;
        if (is_numeric($totalSks) && (int) $totalSks > 0) {
            return (int) $totalSks;
        }

        return null;
    }

    protected function formatDateYmd(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $raw = trim((string) $value);
        if ($raw === '' || str_starts_with($raw, '0000-00-00')) {
            return null;
        }

        if (preg_match('/^(\d{4}-\d{2}-\d{2})/', $raw, $m) === 1) {
            return $m[1];
        }

        $timestamp = strtotime($raw);

        return $timestamp !== false ? date('Y-m-d', $timestamp) : null;
    }

    protected function nullableFloat(mixed $v): ?float
    {
        if ($v === null || $v === '') {
            return null;
        }
        if (is_numeric($v)) {
            return (float) $v;
        }

        return null;
    }

    protected function nullableString(mixed $v): ?string
    {
        if ($v === null) {
            return null;
        }
        $s = trim((string) $v);

        return $s === '' ? null : $s;
    }

    protected function extractYear(mixed ...$values): ?int
    {
        foreach ($values as $value) {
            if ($value === null) {
                continue;
            }

            if (preg_match('/(19|20)\d{2}/', (string) $value, $matches) === 1) {
                return (int) $matches[0];
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
            $rows = DB::connection('siakad')->select('SHOW COLUMNS FROM '.$table);
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

    protected function kodeId(): string
    {
        return trim((string) config('siakad_api.kode_id', ''));
    }

    protected function guessJenisSemester(string $tahunId): ?string
    {
        $last = substr($tahunId, -1);
        if ($last === '1' || $last === '3') {
            return 'ganjil';
        }
        if ($last === '2' || $last === '4') {
            return 'genap';
        }

        return null;
    }

    /*
    |--------------------------------------------------------------------------
    | Perluasan read-only untuk SIMAWA-GS (tidak mengubah method di atas).
    |--------------------------------------------------------------------------
    */

    /**
     * Master status operasional mahasiswa (tabel statusmhsw).
     *
     * @return list<array{id: string, nama: string, keluar: string|null}>
     */
    public function statusMhsw(): array
    {
        try {
            $rows = DB::connection('siakad')->select(
                'SELECT StatusMhswID AS id, Nama AS nama, Keluar AS keluar
                 FROM statusmhsw ORDER BY StatusMhswID'
            );
        } catch (\Throwable) {
            return [];
        }

        $out = [];
        foreach ($rows as $r) {
            $a = (array) $r;
            $id = trim((string) ($a['id'] ?? ''));
            if ($id === '') {
                continue;
            }
            $out[] = [
                'id' => $id,
                'nama' => (string) ($a['nama'] ?? ''),
                'keluar' => $this->nullableString($a['keluar'] ?? null),
            ];
        }

        return $out;
    }

    /**
     * Tahun akademik dengan nama dan flag NA (berbeda dari semesterAktif).
     *
     * @return list<array<string, mixed>>
     */
    public function tahunAkademik(): array
    {
        $kodeId = $this->kodeId();
        $params = [];
        $sql = 'SELECT DISTINCT t.TahunID AS tahun_id,
                       MAX(t.Nama) AS nama_tahun,
                       MAX(t.NA) AS na_flag,
                       MAX(t.TglKuliahMulai) AS tgl_kuliah_mulai
                FROM tahun t
                WHERE t.TahunID IS NOT NULL AND TRIM(t.TahunID) <> \'\'';
        if ($kodeId !== '') {
            $sql .= ' AND (t.KodeID = ? OR t.KodeID IS NULL OR t.KodeID = \'\')';
            $params[] = $kodeId;
        }
        $sql .= ' GROUP BY t.TahunID ORDER BY t.TahunID DESC';

        try {
            $rows = DB::connection('siakad')->select($sql, $params);
        } catch (\Throwable) {
            $fallback = [];
            foreach ($this->semesterAktif() as $row) {
                $fallback[] = [
                    'tahun_id' => (string) ($row['id'] ?? ''),
                    'nama_tahun' => (string) ($row['tahun_ajaran'] ?? $row['id'] ?? ''),
                    'jenis_semester' => $row['jenis'] ?? null,
                    'is_active' => (bool) ($row['is_active'] ?? true),
                ];
            }

            return $fallback;
        }

        $out = [];
        foreach ($rows as $r) {
            $a = (array) $r;
            $tahunId = trim((string) ($a['tahun_id'] ?? ''));
            if ($tahunId === '') {
                continue;
            }
            $na = strtoupper(trim((string) ($a['na_flag'] ?? 'N')));
            $out[] = [
                'tahun_id' => $tahunId,
                'nama_tahun' => $this->nullableString($a['nama_tahun'] ?? null) ?? $tahunId,
                'jenis_semester' => $this->guessJenisSemester($tahunId),
                'tgl_kuliah_mulai' => $this->formatDateYmd($a['tgl_kuliah_mulai'] ?? null),
                'is_active' => $na === 'N' || $na === '',
            ];
        }

        return $out;
    }

    /**
     * Mahasiswa lengkap untuk SIMAWA (Handphone, Foto, StatusMhsw) — di luar mahasiswaSync.
     *
     * @return list<array<string, mixed>>
     */
    public function mahasiswaSimawa(
        ?string $programId = null,
        ?string $prodiId = null,
        ?string $tahunId = null,
        ?string $angkatan = null,
        ?string $statusAwalId = null,
        ?string $statusMhswId = null,
    ): array {
        $kodeId = $this->kodeId();
        $params = [];
        $sql = 'SELECT m.MhswID AS mhsw_id, m.Login AS nim, m.Nama AS nama, m.ProdiID AS prodi_id, m.ProgramID AS program_id,
                       m.TahunID AS tahun_id, m.StatusAwalID AS status_awal_id, sa.Nama AS status_awal_nama,
                       m.StatusMhswID AS status_mhsw_id, sm.Nama AS status_mhsw_nama,
                       m.TempatLahir AS tempat_lahir, m.TanggalLahir AS tanggal_lahir,
                       m.Kelamin AS kelamin_kode_siakad, kl.Nama AS kelamin_nama,
                       m.Handphone AS handphone, m.Telepon AS telepon, m.Foto AS foto,
                       m.Email AS email, m.Alamat AS alamat
                FROM mhsw m
                LEFT JOIN agama ag ON m.Agama = ag.Agama
                LEFT JOIN kelamin kl ON m.Kelamin = kl.Kelamin
                LEFT JOIN statusawal sa ON m.StatusAwalID = sa.StatusAwalID
                LEFT JOIN statusmhsw sm ON m.StatusMhswID = sm.StatusMhswID
                WHERE (m.NA = \'N\' OR m.NA IS NULL OR m.NA = \'\')';
        if ($kodeId !== '') {
            $sql .= ' AND m.KodeID = ?';
            $params[] = $kodeId;
        }
        if ($programId !== null && trim($programId) !== '') {
            $sql .= ' AND m.ProgramID = ?';
            $params[] = trim($programId);
        }
        if ($prodiId !== null && trim($prodiId) !== '') {
            $sql .= ' AND m.ProdiID = ?';
            $params[] = trim($prodiId);
        }
        if ($tahunId !== null && trim($tahunId) !== '') {
            $sql .= ' AND m.TahunID = ?';
            $params[] = trim($tahunId);
        }
        if ($angkatan !== null && preg_match('/^\d{4}$/', trim($angkatan))) {
            $sql .= ' AND SUBSTRING(TRIM(m.TahunID), 1, 4) = ?';
            $params[] = trim($angkatan);
        }
        if ($statusAwalId !== null && trim($statusAwalId) !== '') {
            $sql .= ' AND m.StatusAwalID = ?';
            $params[] = trim($statusAwalId);
        }
        if ($statusMhswId !== null && trim($statusMhswId) !== '') {
            $sql .= ' AND m.StatusMhswID = ?';
            $params[] = trim($statusMhswId);
        }
        $sql .= ' ORDER BY m.MhswID';

        try {
            $rows = DB::connection('siakad')->select($sql, $params);
        } catch (\Throwable) {
            $sync = $this->mahasiswaSync($programId, $prodiId, $tahunId, $angkatan, $statusAwalId);

            return array_map(fn (array $row): array => array_merge($row, [
                'handphone' => null,
                'telepon' => null,
                'foto' => null,
                'status_mhsw_id' => null,
                'status_mhsw_nama' => null,
            ]), $sync);
        }

        $out = [];
        foreach ($rows as $r) {
            $out[] = $this->mapMahasiswaSimawaRow((array) $r);
        }

        return $out;
    }

    /**
     * Dosen dengan email dan handphone terpisah (dosen() menggabungkan ke email).
     *
     * @return list<array<string, mixed>>
     */
    public function dosenSimawa(): array
    {
        $columns = $this->tableColumns('dosen');
        if ($columns === []) {
            return [];
        }

        $nuptkCol = $this->firstExistingColumn($columns, ['NUPTK', 'Nuptk', 'nuptk']);
        $kodeId = $this->kodeId();
        $params = [];
        $nuptkFragment = $nuptkCol !== null ? sprintf('`%s` AS NUPTK', $nuptkCol) : 'NULL AS NUPTK';

        $sql = 'SELECT Login, NIDN, NIPPNS, '.$nuptkFragment.', Nama, Homebase, Email, Handphone, NA
                FROM dosen
                WHERE (NA = \'N\' OR NA IS NULL OR NA = \'\')';
        if ($kodeId !== '') {
            $sql .= ' AND KodeID = ?';
            $params[] = $kodeId;
        }
        $sql .= ' ORDER BY Login';

        try {
            $rows = DB::connection('siakad')->select($sql, $params);
        } catch (\Throwable) {
            return [];
        }

        $out = [];
        foreach ($rows as $r) {
            $a = (array) $r;
            $login = (string) ($a['Login'] ?? '');
            if ($login === '') {
                continue;
            }
            $na = strtoupper(trim((string) ($a['NA'] ?? 'N')));
            $out[] = [
                'id' => $login,
                'siakad_id' => $login,
                'nidn' => isset($a['NIDN']) ? (string) $a['NIDN'] : null,
                'nip' => isset($a['NIPPNS']) ? (string) $a['NIPPNS'] : null,
                'nuptk' => isset($a['NUPTK']) ? $this->nullableString($a['NUPTK']) : null,
                'nama' => (string) ($a['Nama'] ?? ''),
                'email' => $this->nullableString($a['Email'] ?? null),
                'handphone' => $this->nullableString($a['Handphone'] ?? null),
                'prodi_kode' => isset($a['Homebase']) ? (string) $a['Homebase'] : null,
                'is_active' => $na === 'N' || $na === '',
            ];
        }

        return $out;
    }

    /**
     * @param  array<string, mixed>  $a
     * @return array<string, mixed>
     */
    protected function mapMahasiswaSimawaRow(array $a): array
    {
        $base = $this->mapMahasiswaSyncRow($a);

        return array_merge($base, [
            'handphone' => $this->nullableString($a['handphone'] ?? null),
            'telepon' => $this->nullableString($a['telepon'] ?? null),
            'foto' => $this->nullableString($a['foto'] ?? null),
            'status_mhsw_id' => $this->nullableString($a['status_mhsw_id'] ?? null),
            'status_mhsw_nama' => $this->nullableString($a['status_mhsw_nama'] ?? null),
        ]);
    }
}
