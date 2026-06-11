<?php

namespace App\Support;

/**
 * Normalisasi REQUEST_URI saat aplikasi di subfolder (mis. /siakad-api/public).
 */
class SubdirectoryRequest
{
    public static function applyFromEnvFile(string $envPath): void
    {
        $base = self::readSubdirectoryFromEnv($envPath);
        if ($base === '') {
            return;
        }

        self::apply($base);
    }

    public static function apply(string $base): void
    {
        $base = '/'.trim($base, '/');
        if ($base === '/') {
            return;
        }

        $requestUri = $_SERVER['REQUEST_URI'] ?? '/';
        $path = parse_url($requestUri, PHP_URL_PATH) ?? '/';
        $query = parse_url($requestUri, PHP_URL_QUERY);

        if (! str_starts_with($path, $base)) {
            return;
        }

        $path = substr($path, strlen($base)) ?: '/';
        if ($path !== '/' && ! str_starts_with($path, '/')) {
            $path = '/'.$path;
        }

        $_SERVER['REQUEST_URI'] = $path.($query ? '?'.$query : '');
        $_SERVER['SCRIPT_NAME'] = rtrim($base, '/').'/index.php';
        $_SERVER['PHP_SELF'] = $_SERVER['SCRIPT_NAME'];
    }

    protected static function readSubdirectoryFromEnv(string $envPath): string
    {
        if (! is_readable($envPath)) {
            return '';
        }

        $lines = file($envPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        if ($lines === false) {
            return '';
        }

        foreach ($lines as $line) {
            $line = trim($line);
            if ($line === '' || str_starts_with($line, '#')) {
                continue;
            }

            if (! str_starts_with($line, 'APP_SUBDIRECTORY=')) {
                continue;
            }

            $value = trim(substr($line, strlen('APP_SUBDIRECTORY=')));

            return trim($value, " \t\"'");
        }

        return '';
    }
}
