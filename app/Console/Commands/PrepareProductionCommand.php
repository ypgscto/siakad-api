<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;
use Throwable;

class PrepareProductionCommand extends Command
{
    protected $signature = 'app:prepare-production
                            {--force : Jalankan tanpa konfirmasi}
                            {--skip-db-check : Lewati uji koneksi database Siakad}';

    protected $description = 'Bersihkan artefak uji/cache dan validasi konfigurasi server produksi';

    /**
     * @var list<string>
     */
    protected array $localTables = [
        'sessions',
        'cache',
        'cache_locks',
        'jobs',
        'job_batches',
        'failed_jobs',
        'users',
        'password_reset_tokens',
    ];

    public function handle(): int
    {
        if (! $this->option('force') && ! $this->confirm('Cache, log, file uji, dan tabel lokal Laravel akan dikosongkan. Lanjutkan?')) {
            $this->info('Dibatalkan.');

            return self::SUCCESS;
        }

        $this->info('Membersihkan storage & cache...');
        $this->purgeTestArtifacts();
        $this->callSilent('cache:clear');
        $this->callSilent('config:clear');
        $this->callSilent('route:clear');
        $this->callSilent('view:clear');

        $this->info('Mengosongkan tabel aplikasi lokal (bukan siakad_db)...');
        $this->truncateLocalTables();

        $warnings = $this->validateProductionConfig();
        foreach ($warnings as $warning) {
            $this->components->warn($warning);
        }

        if (! $this->option('skip-db-check')) {
            $this->info('Menguji koneksi read-only ke siakad_db...');
            if (! $this->checkSiakadConnection()) {
                return self::FAILURE;
            }
        }

        $this->newLine();
        $this->components->info('Siakad-API siap deploy produksi.');
        $this->line('Checklist server:');
        $this->line('  • APP_ENV=production, APP_DEBUG=false');
        $this->line('  • SIAKAD_API_TOKEN kuat & sama dengan SIAKAD_API_TOKEN di SI-Tercapai');
        $this->line('  • SIAKAD_DB_* mengarah ke MySQL Siakad produksi (read-only user disarankan)');
        $this->line('  • php artisan config:cache && route:cache setelah .env final');
        $this->line('  • GET /api/health harus {"ok":true,"siakad_db":"ok"}');

        return $warnings === [] ? self::SUCCESS : self::SUCCESS;
    }

    protected function purgeTestArtifacts(): void
    {
        $patterns = [
            storage_path('login-test.json'),
            storage_path('*-test.json'),
            storage_path('framework/cache/data'),
        ];

        foreach ($patterns as $path) {
            if (str_contains($path, '*')) {
                foreach (glob($path) ?: [] as $file) {
                    File::delete($file);
                    $this->line("  dihapus: {$file}");
                }

                continue;
            }

            if (File::isDirectory($path)) {
                File::cleanDirectory($path);
                $this->line("  dikosongkan: {$path}");

                continue;
            }

            if (File::exists($path)) {
                File::delete($path);
                $this->line("  dihapus: {$path}");
            }
        }

        foreach (glob(storage_path('logs/*.log')) ?: [] as $log) {
            File::put($log, '');
            $this->line('  log dikosongkan: '.basename($log));
        }
    }

    protected function truncateLocalTables(): void
    {
        Schema::disableForeignKeyConstraints();

        foreach ($this->localTables as $table) {
            if (! Schema::hasTable($table)) {
                continue;
            }

            DB::table($table)->truncate();
            $this->line("  {$table}: dikosongkan");
        }

        Schema::enableForeignKeyConstraints();
    }

    /**
     * @return list<string>
     */
    protected function validateProductionConfig(): array
    {
        $warnings = [];

        if (config('app.debug')) {
            $warnings[] = 'APP_DEBUG masih true — set false di .env produksi.';
        }

        if (! app()->environment('production')) {
            $warnings[] = 'APP_ENV bukan production — set APP_ENV=production di server.';
        }

        $token = (string) config('siakad_api.token', '');
        if ($token === '') {
            $warnings[] = 'SIAKAD_API_TOKEN kosong — API tidak dapat dipanggil.';
        } elseif (in_array($token, config('siakad_api.insecure_tokens', []), true)) {
            $warnings[] = 'SIAKAD_API_TOKEN masih nilai contoh/development — ganti token rahasia di produksi.';
        }

        if ((string) config('siakad_api.kode_id', '') === '') {
            $warnings[] = 'SIAKAD_KODE_ID kosong — semua institusi di siakad_db dapat terbaca.';
        }

        return $warnings;
    }

    protected function checkSiakadConnection(): bool
    {
        try {
            DB::connection('siakad')->select('SELECT 1 AS ok');

            $this->components->info('Koneksi siakad_db: OK');

            return true;
        } catch (Throwable $e) {
            $this->components->error('Koneksi siakad_db gagal: '.$e->getMessage());

            return false;
        }
    }
}
