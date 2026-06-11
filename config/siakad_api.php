<?php

return [

    'token' => env('SIAKAD_API_TOKEN', ''),

    /*
    | Token contoh yang tidak boleh dipakai di produksi.
    */
    'insecure_tokens' => [
        'change-me-long-secret',
        'siakad-api-shared-token-2026',
    ],

    /*
    | Filter institusi (kolom KodeID di banyak tabel Sisfo). Kosongkan jika tidak dipakai.
    */
    'kode_id' => env('SIAKAD_KODE_ID', ''),

    /*
    | LevelID yang boleh login tanpa password (selaras opsi khusus Siakad-GS untuk mhsw/dosen).
    | Contoh: [100, 120]. Kosongkan agar semua level wajib password di API.
    */
    'auth_password_optional_level_ids' => array_values(array_filter(array_map(
        'trim',
        explode(',', (string) env('SIAKAD_AUTH_PASSWORD_OPTIONAL_LEVEL_IDS', ''))
    ))),

    /*
    | Pemetaan peran SiFeeder (level_id form) ke dua sumber akun Siakad:
    | - karyawan: LevelID (login bawaan Siakad)
    | - user: jenis_user (aplikasi pendukung)
    */
    'sifeeder_roles' => [
        '1' => [
            'label' => 'Superadmin',
            'karyawan_level_id' => '1',
            'user_jenis_user' => '9',
        ],
        '20' => [
            'label' => 'Administrator',
            'karyawan_level_id' => '20',
            'user_jenis_user' => '8',
        ],
        '91' => [
            'label' => 'Kepala Lembaga',
            'karyawan_level_id' => '91',
            'user_jenis_user' => '5',
        ],
    ],

    /*
    | Login SI-Tercapai & aplikasi OBE: tabel user, kolom argon / argon_password.
    | Urutan: prioritas jenis tertinggi dulu (9 superadmin).
    */
    'app_login_jenis_user' => ['9', '8', '7', '6', '5', '4'],

    'jenis_user_labels' => [
        '9' => 'Superadmin',
        '8' => 'Administrator',
        '7' => 'Dosen',
        '6' => 'Ketua Prodi',
        '5' => 'Kepala Lembaga',
        '4' => 'Operator',
        '1' => 'Admin PMB',
        '0' => 'Tamu',
    ],

    /*
    | Jenis tamu / PMB (nilai mentah di kolom jenis_user) — ditolak login meski ada pemetaan SSO.
    */
    'app_login_denied_jenis_user' => ['0', '1'],

    /*
    | Pemetaan akun migrasi SSO Siakad-GS (jenis_user 1–4) ke jenis OBE (9–4).
    | Hanya untuk normalisasi profil, BUKAN untuk melewati larangan login jenis 0/1.
    | null = tidak boleh masuk aplikasi OBE.
    */
    'sso_jenis_user_to_obe' => [
        '1' => '9',
        '2' => '8',
        '3' => '7',
        '4' => null,
    ],

    /*
    | Cadangan jika kolom user_type (admin/pegawai/dosen) terisi dari SSO.
    */
    'sso_user_type_to_obe' => [
        'admin' => '9',
        'pegawai' => '8',
        'dosen' => '7',
        'mahasiswa' => null,
    ],

    /*
    | LevelID dari tabel users (SSO) ke jenis OBE jika jenis_user belum OBE.
    */
    'sso_level_id_to_obe' => [
        '1' => '9',
        '20' => '8',
        '6' => '6',
        '91' => '5',
        '110' => '7',
        '100' => '7',
    ],

    /*
    | Nama tabel akun aplikasi di siakad_db (umumnya users).
    */
    'user_table' => env('SIAKAD_USER_TABLE', 'users'),

    /*
    | SIMAWA-GS — sinkron read-only (/api/simawa/*).
    */
    'simawa' => [
        'default_limit' => (int) env('SIMAWA_API_DEFAULT_LIMIT', 50),
        'max_limit' => (int) env('SIMAWA_API_MAX_LIMIT', 500),
        // Opsional: prefix URL foto mahasiswa (mis. https://siakad.../ )
        'foto_base_url' => env('SIMAWA_FOTO_BASE_URL', ''),
    ],

    /*
    | SIMAWA-GS — pemetaan akun login (/api/simawa/login-users).
    */
    'simawa_user_sync' => [
        'allowed_jenis_user' => ['4', '5', '6', '7', '8', '9'],
        'denied_jenis_user' => ['0', '1'],
        'jenis_user_map' => [
            '9' => ['category' => 'pegawai', 'roles' => ['super_admin']],
            '8' => ['category' => 'pegawai', 'roles' => ['admin_kemahasiswaan']],
            '7' => ['category' => 'dosen', 'roles' => ['dosen']],
            '6' => ['category' => 'pegawai', 'roles' => ['prodi']],
            '5' => ['category' => 'pegawai', 'roles' => ['pimpinan']],
            '4' => ['category' => 'pegawai', 'roles' => ['pegawai']],
        ],
        'level_id_map' => [
            '100' => ['category' => 'mahasiswa', 'roles' => ['mahasiswa']],
        ],
        'user_type_map' => [
            'admin' => ['category' => 'pegawai', 'roles' => ['admin_kemahasiswaan']],
            'pegawai' => ['category' => 'pegawai', 'roles' => ['pegawai']],
            'dosen' => ['category' => 'dosen', 'roles' => ['dosen']],
            'mahasiswa' => ['category' => 'mahasiswa', 'roles' => ['mahasiswa']],
            'alumni' => ['category' => 'alumni', 'roles' => ['alumni']],
        ],
    ],

    /*
    | SiPepeng — pemetaan akun login (/api/sipepeng/login-users).
    | Termasuk akun karyawan-only (KodeLogin tanpa baris users).
    */
    'sipepeng_user_sync' => [
        'karyawan_enabled' => true,
        'email_domain' => env('SIPEPENG_SIAKAD_EMAIL_DOMAIN', 'stikesgunungsari.ac.id'),
        'allowed_jenis_user' => ['4', '5', '6', '7', '8', '9'],
        'denied_jenis_user' => ['0', '1'],
        'jenis_user_map' => [
            '9' => ['category' => 'pegawai', 'roles' => ['super_admin']],
            '8' => ['category' => 'pegawai', 'roles' => ['admin_lppm']],
            '7' => ['category' => 'dosen', 'roles' => ['dosen']],
            '6' => ['category' => 'pegawai', 'roles' => ['ketua_prodi']],
            '5' => ['category' => 'pegawai', 'roles' => ['pimpinan']],
            '4' => ['category' => 'pegawai', 'roles' => []],
        ],
        'level_id_map' => [
            '91' => ['category' => 'pegawai', 'roles' => ['ketua_lppm']],
            '100' => ['category' => 'mahasiswa', 'roles' => ['mahasiswa']],
        ],
        'user_type_map' => [
            'admin' => ['category' => 'pegawai', 'roles' => ['admin_lppm']],
            'pegawai' => ['category' => 'pegawai', 'roles' => []],
            'dosen' => ['category' => 'dosen', 'roles' => ['dosen']],
            'mahasiswa' => ['category' => 'mahasiswa', 'roles' => ['mahasiswa']],
        ],
    ],

    /*
    | SiGanteng — pemetaan akun login (/api/siganteng/*).
    | jenis_user OBE: 9=admin, 7=dosen (SSO 3), 8=karyawan (SSO 2).
    */
    'siganteng_user_sync' => [
        'karyawan_enabled' => true,
        'email_domain' => env('SIGANTENG_SIAKAD_EMAIL_DOMAIN', 'stikesgunungsari.ac.id'),
        'allowed_jenis_user' => ['9', '7', '8'],
        'denied_jenis_user' => ['0', '1'],
        'jenis_user_map' => [
            '9' => ['category' => 'admin', 'role_slug' => 'super_admin'],
            '7' => ['category' => 'dosen', 'role_slug' => 'pegawai'],
            '8' => ['category' => 'karyawan', 'role_slug' => 'admin_sdm'],
        ],
        'level_id_map' => [
            '1' => ['category' => 'admin', 'role_slug' => 'super_admin'],
            '20' => ['category' => 'karyawan', 'role_slug' => 'admin_sdm'],
            '110' => ['category' => 'dosen', 'role_slug' => 'pegawai'],
            '100' => ['category' => 'dosen', 'role_slug' => 'pegawai'],
        ],
        'user_type_map' => [
            'admin' => ['category' => 'admin', 'role_slug' => 'super_admin'],
            'pegawai' => ['category' => 'karyawan', 'role_slug' => 'admin_sdm'],
            'dosen' => ['category' => 'dosen', 'role_slug' => 'pegawai'],
        ],
    ],

];
