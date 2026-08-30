<?php

use App\Actions\BusinessBackup\ExportBusinessBackup;
use App\Domain\Company\Capability;
use App\Filament\Platform\Pages\ImportCompanyBackup;
use App\Models\Company;
use App\Models\CompanyCapability;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    Filament::setCurrentPanel('platform');
    Filament::setTenant(null);
});

it('allows only a Platform Admin to validate and preview without writes', function (): void {
    Storage::fake('local');
    $source = Company::factory()->create(['name' => 'Anteprima Backup']);
    $admin = User::factory()->platformAdmin()->create();
    $ordinary = User::factory()->create();
    CompanyCapability::query()->create(['company_id' => $source->id, 'user_id' => $admin->id, 'capability' => Capability::View]);
    $artifact = app(ExportBusinessBackup::class)->execute($source, $admin);
    $upload = 'business-backup-uploads/preview.xlsx';
    Storage::disk('local')->put($upload, (string) file_get_contents($artifact['path']));
    @unlink($artifact['path']);
    $companyCount = Company::query()->count();

    $this->actingAs($admin);
    Livewire::test(ImportCompanyBackup::class)
        ->assertSuccessful()
        ->assertSee('Importa Azienda da backup')
        ->set('data.backup', [$upload])
        ->call('validateBackup')
        ->assertHasNoErrors()
        ->assertSet('previewData.company_name', 'Anteprima Backup')
        ->assertNotified('Backup valido');
    expect(Company::query()->count())->toBe($companyCount);

    $this->actingAs($ordinary)
        ->get('/platform/import-company-backup')
        ->assertForbidden();
});
