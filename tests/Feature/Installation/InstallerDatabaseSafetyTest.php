<?php

use App\Installer\Steps\CheckRequirements;
use App\Installer\Steps\ConfigureEnvironment;
use App\Installer\Steps\ConfigureScheduler;
use App\Installer\Steps\PrepareDatabase;
use App\Installer\Steps\RunMigrations;
use RelayerCore\LaravelInstaller\Services\StepManager;
use RelayerCore\LaravelInstaller\Steps\CheckPermissions;
use RelayerCore\LaravelInstaller\Steps\CreateAdmin;

it('uses a dedicated fail-closed database target for destructive installer tests', function () {
    $database = (string) env('INSTALLER_TEST_DATABASE');

    expect(app()->environment())->toBe('testing')
        ->and(config('database.connections.mysql.database'))->toBe('testing')
        ->and($database)->toStartWith('testing_')
        ->not->toBe('testing')
        ->not->toBe('mp2');
});

it('registers the MP2 database steps instead of the package defaults', function () {
    $steps = app(StepManager::class)->getSteps();

    expect($steps['environment'])->toBeInstanceOf(ConfigureEnvironment::class)
        ->and($steps['migrations'])->toBeInstanceOf(RunMigrations::class)
        ->and(array_map(static fn ($step): string => $step::class, array_values($steps)))->toBe([
            CheckRequirements::class,
            CheckPermissions::class,
            ConfigureEnvironment::class,
            PrepareDatabase::class,
            RunMigrations::class,
            CreateAdmin::class,
            ConfigureScheduler::class,
        ]);
});
