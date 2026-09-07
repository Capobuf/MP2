<?php

use App\BusinessBackup\V1\BusinessBackupValidator;
use App\Models\Company;
use App\Models\CostCenter;
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
            ->and($files[0]->getFilename())->toMatch('/^MP2-comando-backup-\d{4}-\d{2}-\d{2}-[0-9a-f-]{36}\.xlsx$/');

        $company->tenantCompany()->update(['status' => 'archived']);
        $this->artisan('business-backup:export', ['company' => $company->id, '--output' => $directory])
            ->assertFailed()
            ->expectsOutputToContain('Il Tenant non è operativo.');
        expect(File::files($directory))->toHaveCount(1);
    } finally {
        File::deleteDirectory($directory);
    }
});

it('keeps distinct readable files for Companies with the same filesystem-safe name', function (bool $customOutput): void {
    $identifier = (string) Str::uuid();
    $companyName = 'Azienda Omonima '.$identifier;
    $directory = $customOutput
        ? storage_path('framework/testing/backup-command-'.$identifier)
        : storage_path('app/private/business-backups/published');
    $first = Company::factory()->create(['name' => $companyName]);
    $second = Company::factory()->create(['name' => $companyName]);
    CostCenter::factory()->for($first)->create(['name' => 'Centro Prima']);
    CostCenter::factory()->for($second)->create(['name' => 'Centro Seconda']);
    $createdPaths = [];

    try {
        foreach ([$first, $second] as $company) {
            $arguments = ['company' => $company->id];
            if ($customOutput) {
                $arguments['--output'] = $directory;
            }
            $this->artisan('business-backup:export', $arguments)->assertSuccessful();
        }

        $files = collect(File::files($directory))
            ->filter(fn ($file): bool => str_contains($file->getFilename(), Str::slug($companyName)))
            ->values();
        $createdPaths = $files->map->getPathname()->all();
        expect($files)->toHaveCount(2)
            ->and($files[0]->getFilename())->not->toBe($files[1]->getFilename());

        $centers = [];
        foreach ($files as $file) {
            $package = app(BusinessBackupValidator::class)->validate($file->getPathname());
            expect($file->getFilename())->toContain($package['manifest']['package_id']);
            $centers[] = $package['machine']['_MP2_cost_centers']['rows'][0][1];
        }

        expect($centers)->toContain('Centro Prima', 'Centro Seconda');
    } finally {
        File::delete($createdPaths);
        if ($customOutput) {
            File::deleteDirectory($directory);
        }
    }
})->with([
    'directory predefinita' => false,
    'opzione --output' => true,
]);

it('fails explicitly for an unknown Company', function (): void {
    $this->artisan('business-backup:export', ['company' => 999999])->assertFailed()->expectsOutputToContain('Azienda non trovata.');
});
