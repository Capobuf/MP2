<?php

use App\Actions\BusinessBackup\ExportBusinessBackup;
use App\Actions\BusinessBackup\StoreBusinessBackupOnDrive;
use App\Domain\Company\Capability;
use App\Models\Company;
use App\Models\CompanyCapability;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

it('writes the exact generated XLSX bytes to the configured Drive disk', function (): void {
    Storage::fake('google');
    config()->set('filesystems.disks.google', [
        'driver' => 'google', 'clientId' => 'client', 'clientSecret' => 'secret', 'refreshToken' => 'refresh',
    ]);
    $company = Company::factory()->create();
    $actor = User::factory()->create();
    CompanyCapability::query()->create(['company_id' => $company->id, 'user_id' => $actor->id, 'capability' => Capability::View]);
    $artifact = app(ExportBusinessBackup::class)->execute($company, $actor);
    $expectedHash = hash_file('sha256', $artifact['path']);

    $filename = app(StoreBusinessBackupOnDrive::class)->store($artifact);

    Storage::disk('google')->assertExists($filename);
    expect(hash('sha256', (string) Storage::disk('google')->get($filename)))->toBe($expectedHash)
        ->and($filename)->toEndWith('.xlsx')
        ->and(is_file($artifact['path']))->toBeFalse();
});

it('reports Drive as unavailable without credentials', function (): void {
    config()->set('filesystems.disks.google', ['driver' => 'google']);

    expect(StoreBusinessBackupOnDrive::configured())->toBeFalse();
});
