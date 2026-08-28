<?php

namespace App\Installer\Steps;

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use RelayerCore\LaravelInstaller\Contracts\InstallerStep;
use RuntimeException;
use Throwable;

class RunMigrations implements InstallerStep
{
    public function id(): string
    {
        return 'migrations';
    }

    public function label(): string
    {
        return __('installer::installer.step_migrations');
    }

    public function view(): string
    {
        return 'installer::steps.migrations';
    }

    public function isSkipped(): bool
    {
        return false;
    }

    /** @param array<string, mixed> $data */
    public function validate(array $data = []): bool
    {
        $this->assertDatabaseIsEmpty();

        return true;
    }

    /** @param array<string, mixed> $data */
    public function process(array $data = []): void
    {
        $this->assertDatabaseIsEmpty();

        $originalCacheStore = config('cache.default');
        config(['cache.default' => 'array']);

        try {
            $this->runMigrationsAndSeeder();
        } catch (Throwable $exception) {
            Log::error('Installer migration failed', [
                'exception' => $exception::class,
                'error' => $exception->getMessage(),
            ]);

            throw new RuntimeException(
                __('installer::installer.migrations_error_prefix').' '.__('installer::installer.migrations_error_fallback'),
                previous: $exception,
            );
        } finally {
            config(['cache.default' => $originalCacheStore]);
        }
    }

    protected function runMigrationsAndSeeder(): void
    {
        $migrationResult = Artisan::call('migrate', [
            '--database' => 'mysql',
            '--force' => true,
        ]);

        if ($migrationResult !== 0) {
            throw new RuntimeException('Migration command failed.');
        }

        $seedResult = Artisan::call('db:seed', [
            '--database' => 'mysql',
            '--class' => config('installer.seeder'),
            '--force' => true,
        ]);

        if ($seedResult !== 0) {
            throw new RuntimeException('Database seeding command failed.');
        }
    }

    private function assertDatabaseIsEmpty(): void
    {
        if (config('database.connections.mysql.driver') !== 'mysql') {
            throw new RuntimeException(__('installer::installer.environment_error_connection'));
        }

        $database = trim((string) config('database.connections.mysql.database'));
        if ($database === '') {
            throw new RuntimeException(__('installer::installer.environment_error_database'));
        }

        DB::purge('mysql');

        $objects = DB::connection('mysql')->selectOne(
            <<<'SQL'
                SELECT COUNT(*) AS aggregate
                FROM information_schema.tables
                WHERE table_schema = ?
                  AND table_type IN ('BASE TABLE', 'VIEW')
            SQL,
            [$database],
        );

        if ((int) ($objects->aggregate ?? 0) !== 0) {
            throw new RuntimeException(__('installer::installer.database_not_empty_error'));
        }
    }
}
