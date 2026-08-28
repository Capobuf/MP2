<?php

namespace App\Installer\Steps;

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use RelayerCore\LaravelInstaller\Contracts\InstallerStep;
use RuntimeException;
use Throwable;

class PrepareDatabase implements InstallerStep
{
    public function id(): string
    {
        return 'database';
    }

    public function label(): string
    {
        return __('installer::installer.step_database');
    }

    public function view(): string
    {
        return 'installer::steps.prepare-database';
    }

    public function isSkipped(): bool
    {
        return false;
    }

    /** @param array<string, mixed> $data */
    public function validate(array $data = []): bool
    {
        if (! $this->isEmpty()) {
            $this->assertConfirmed($data);
        }

        return true;
    }

    /** @param array<string, mixed> $data */
    public function process(array $data = []): void
    {
        if ($this->isEmpty()) {
            return;
        }

        $this->assertConfirmed($data);

        try {
            $this->wipeDatabase();
        } catch (Throwable $exception) {
            throw new RuntimeException(
                __('installer::installer.database_reset_error'),
                previous: $exception,
            );
        }

        if (! $this->isEmpty()) {
            throw new RuntimeException(__('installer::installer.database_not_empty_error'));
        }
    }

    public function databaseName(): string
    {
        if (config('database.connections.mysql.driver') !== 'mysql') {
            throw new RuntimeException(__('installer::installer.environment_error_connection'));
        }

        $database = trim((string) config('database.connections.mysql.database'));
        if ($database === '') {
            throw new RuntimeException(__('installer::installer.environment_error_database'));
        }

        return $database;
    }

    public function isEmpty(): bool
    {
        $database = $this->databaseName();

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

        return (int) ($objects->aggregate ?? 0) === 0;
    }

    protected function wipeDatabase(): void
    {
        $result = Artisan::call('db:wipe', [
            '--database' => 'mysql',
            '--drop-views' => true,
            '--force' => true,
        ]);

        if ($result !== 0) {
            throw new RuntimeException('Database wipe command failed.');
        }

        DB::purge('mysql');
    }

    /** @param array<string, mixed> $data */
    private function assertConfirmed(array $data): void
    {
        $confirmation = (string) ($data['database_confirmation'] ?? '');

        if (! hash_equals($this->databaseName(), $confirmation)) {
            throw new RuntimeException(__('installer::installer.database_confirmation_error'));
        }
    }
}
