<?php

namespace App\Support;

use Illuminate\Support\Facades\DB;

/**
 * Verifikasi password gaya mysql_native_password.
 * Utama: fungsi MySQL PASSWORD() via SELECT (MySQL 5.7).
 * Cadangan: hash di PHP jika PASSWORD() tidak ada (MySQL 8+).
 */
final class SisfoMysqlNativePassword
{
    /**
     * Hash dari server MySQL: SELECT PASSWORD(?).
     */
    public static function hashViaMysql(string $password): ?string
    {
        try {
            $r = DB::connection('siakad')->selectOne('SELECT PASSWORD(?) AS pwd_hash', [$password]);
        } catch (\Throwable) {
            return null;
        }

        if ($r === null) {
            return null;
        }

        $hash = (string) (((array) $r)['pwd_hash'] ?? '');

        return $hash !== '' ? $hash : null;
    }

    /**
     * Cocokkan password plain dengan nilai kolom tersimpan memakai SELECT PASSWORD(?) di MySQL.
     */
    public static function matchesViaMysql(string $plain, mixed $stored): bool
    {
        if ($stored === null || $stored === '') {
            return false;
        }
        $stored = (string) $stored;

        $hash = self::hashViaMysql($plain);
        if ($hash === null) {
            return false;
        }

        if (strlen($stored) <= 10) {
            return substr($hash, 0, 10) === $stored;
        }

        return strcasecmp($hash, $stored) === 0;
    }

    /**
     * Hash di PHP (double SHA-1) — cadangan bila PASSWORD() tidak tersedia.
     */
    public static function hash(string $password): string
    {
        $stage1 = sha1($password, true);
        $stage2 = sha1($stage1, true);

        return '*'.strtoupper(bin2hex($stage2));
    }

    /**
     * Cocokkan password: utamakan MySQL PASSWORD(), lalu hash PHP.
     */
    public static function matches(string $plain, mixed $stored): bool
    {
        if ($stored === null || $stored === '') {
            return false;
        }
        $stored = (string) $stored;

        if (self::matchesViaMysql($plain, $stored)) {
            return true;
        }

        $full = self::hash($plain);
        if (strlen($stored) <= 10) {
            return substr($full, 0, 10) === $stored;
        }

        return strcasecmp($full, $stored) === 0;
    }
}
