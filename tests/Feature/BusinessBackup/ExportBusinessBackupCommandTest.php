<?php

use App\Models\Company;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

it('exports an active Company from the scheduler-ready command and rejects an archived Tenant', function (): void {
    $directory = storage_path('framework/testing/backup-command-'.Str::uuid());
    $company = Company::factory()->create(['name' => 'Comando Backup']);

    try {
        $this->artisan('business-backup:export', ['company' => $company->id, '--output' => $directory])
            ->assertSuccessful()
            ->expectsOutputToContain('Backup creato:');
        $files = File::files($directory);
        expect($files)->toHaveCount(1)
            ->and($files[0]->getFilename())->toMatch('/^MP2-comando-backup-\d{4}-\d{2}-\d{2}\.xlsx$/');

        $company->tenantCompany()->update(['status' => 'archived']);
        $this->artisan('business-backup:export', ['company' => $company->id, '--output' => $directory])
            ->assertFailed()
            ->expectsOutputToContain('Il Tenant non è operativo.');
        expect(File::files($directory))->toHaveCount(1);
    } finally {
        File::deleteDirectory($directory);
    }
});

it('fails explicitly for an unknown Company', function (): void {
    $this->artisan('business-backup:export', ['company' => 999999])->assertFailed()->expectsOutputToContain('Azienda non trovata.');
});
