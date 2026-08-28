<?php

use App\Installer\Steps\PrepareDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

beforeEach(function () {
    $database = (string) env('INSTALLER_TEST_DATABASE');

    expect(app()->environment())->toBe('testing')
        ->and($database)->toStartWith('testing_')
        ->not->toBe('testing')
        ->not->toBe('mp2');

    $this->originalMysqlConfig = config('database.connections.mysql');
    $installerConfig = $this->originalMysqlConfig;
    $installerConfig['database'] = $database;

    config(['database.connections.mysql' => $installerConfig]);
    DB::purge('mysql');
    DB::connection('mysql')->getPdo();

    Artisan::call('db:wipe', [
        '--database' => 'mysql',
        '--drop-views' => true,
        '--force' => true,
    ]);
});

afterEach(function () {
    Artisan::call('db:wipe', [
        '--database' => 'mysql',
        '--drop-views' => true,
        '--force' => true,
    ]);

    DB::purge('mysql');
    config(['database.connections.mysql' => $this->originalMysqlConfig]);
});

function createInstallerDatabaseSentinels(): void
{
    Schema::connection('mysql')->create('installer_sentinel', function ($table) {
        $table->id();
    });
    DB::connection('mysql')->statement('CREATE VIEW installer_sentinel_view AS SELECT id FROM installer_sentinel');
}

it('allows an empty database without running a reset', function () {
    $step = new PrepareDatabase;

    expect($step->isEmpty())->toBeTrue()
        ->and($step->validate())->toBeTrue();

    $step->process();

    expect($step->isEmpty())->toBeTrue();
});

it('preserves tables and views without the exact configured database confirmation', function () {
    createInstallerDatabaseSentinels();
    $step = new PrepareDatabase;

    expect($step->databaseName())->toBe((string) env('INSTALLER_TEST_DATABASE'))
        ->and(fn () => $step->process(['database_confirmation' => 'testing']))
        ->toThrow(RuntimeException::class, 'non corrisponde')
        ->and(Schema::connection('mysql')->hasTable('installer_sentinel'))->toBeTrue()
        ->and(DB::connection('mysql')->selectOne(
            "SELECT COUNT(*) AS aggregate FROM information_schema.views WHERE table_schema = ? AND table_name = 'installer_sentinel_view'",
            [$step->databaseName()],
        )->aggregate)->toBe(1);
});

it('drops tables and views only after exact confirmation and verifies the result', function () {
    createInstallerDatabaseSentinels();
    $step = new PrepareDatabase;

    $step->process(['database_confirmation' => $step->databaseName()]);

    expect($step->isEmpty())->toBeTrue()
        ->and(Schema::connection('mysql')->hasTable('installer_sentinel'))->toBeFalse();
});

it('does not report readiness when an authorized reset fails', function () {
    createInstallerDatabaseSentinels();

    $step = new class extends PrepareDatabase
    {
        protected function wipeDatabase(): void
        {
            throw new RuntimeException('Simulated permission failure');
        }
    };

    expect(fn () => $step->process(['database_confirmation' => $step->databaseName()]))
        ->toThrow(RuntimeException::class, 'non è riuscito')
        ->and($step->isEmpty())->toBeFalse();
});
