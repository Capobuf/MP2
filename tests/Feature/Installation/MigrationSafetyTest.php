<?php

use App\Installer\Steps\RunMigrations;
use Database\Seeders\DatabaseSeeder;
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

it('runs forced MySQL migrations and the production seeder on an empty database', function () {
    $artisan = Artisan::getFacadeRoot();

    Artisan::shouldReceive('call')
        ->once()
        ->with('migrate', [
            '--database' => 'mysql',
            '--force' => true,
        ])
        ->andReturn(0);
    Artisan::shouldReceive('call')
        ->once()
        ->with('db:seed', [
            '--database' => 'mysql',
            '--class' => DatabaseSeeder::class,
            '--force' => true,
        ])
        ->andReturn(0);
    (new RunMigrations)->process();
    Artisan::swap($artisan);

    expect(file_get_contents(database_path('seeders/DatabaseSeeder.php')))
        ->not->toContain('Test User')
        ->not->toContain('User::factory');
});

it('rechecks the database immediately before migrating', function () {
    Schema::connection('mysql')->create('installer_sentinel', function ($table) {
        $table->id();
    });

    expect(fn () => (new RunMigrations)->process())
        ->toThrow(RuntimeException::class, 'contiene ancora tabelle o viste');
});

it('leaves a partial schema intact and blocks a direct retry', function () {
    $step = new class extends RunMigrations
    {
        protected function runMigrationsAndSeeder(): void
        {
            Schema::connection('mysql')->create('partial_install', function ($table) {
                $table->id();
            });

            throw new RuntimeException('Simulated migration failure');
        }
    };

    expect(fn () => $step->process())->toThrow(RuntimeException::class)
        ->and(Schema::connection('mysql')->hasTable('partial_install'))->toBeTrue()
        ->and(fn () => (new RunMigrations)->process())
        ->toThrow(RuntimeException::class, 'contiene ancora tabelle o viste');
});
