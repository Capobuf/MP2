<?php

use App\Domain\Company\TenantCompanyStatus;
use App\Filament\Platform\Resources\TenantCompanies\Pages\ListTenantCompanies;
use App\Models\Attachment;
use App\Models\Company;
use App\Models\Contract;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    Filament::setCurrentPanel('platform');
    Filament::setTenant(null);
});

it('allows only platform administrators to reach global Tenant management without an operational Tenant', function (): void {
    $platformAdmin = User::factory()->platformAdmin()->create();
    $ordinaryUser = User::factory()->create();

    $this->actingAs($platformAdmin)
        ->get('/platform/tenant-companies')
        ->assertOk();

    $this->actingAs($ordinaryUser)
        ->get('/platform/tenant-companies')
        ->assertForbidden();
});

it('lists both states and exposes only the valid lifecycle action for each row', function (): void {
    $platformAdmin = User::factory()->platformAdmin()->create();
    $active = Company::factory()->create(['name' => 'Attiva'])->tenantCompany;
    $archived = Company::factory()->create(['name' => 'Archiviata'])->tenantCompany;
    $archived->update(['status' => TenantCompanyStatus::Archived]);

    $this->actingAs($platformAdmin);

    Livewire::test(ListTenantCompanies::class)
        ->assertCanSeeTableRecords([$active, $archived])
        ->assertTableActionVisible('archive', $active)
        ->assertTableActionHidden('restore', $active)
        ->assertTableActionVisible('destroy', $active)
        ->assertTableActionHidden('archive', $archived)
        ->assertTableActionVisible('restore', $archived)
        ->assertTableActionVisible('destroy', $archived);
});

it('requires both Wizard confirmations and destroys only after both are present', function (): void {
    $platformAdmin = User::factory()->platformAdmin()->create();
    $company = Company::factory()->create(['name' => 'Da eliminare']);
    $this->actingAs($platformAdmin);

    Livewire::test(ListTenantCompanies::class)
        ->callTableAction('destroy', $company->tenantCompany, data: [
            'irreversibility_confirmed' => true,
            'destruction_confirmed' => false,
        ])
        ->assertHasTableActionErrors(['destruction_confirmed']);
    expect(Company::query()->whereKey($company->id)->exists())->toBeTrue();

    Livewire::test(ListTenantCompanies::class)
        ->callTableAction('destroy', $company->tenantCompany, data: [
            'irreversibility_confirmed' => false,
            'destruction_confirmed' => true,
        ])
        ->assertHasTableActionErrors(['irreversibility_confirmed']);
    expect(Company::query()->whereKey($company->id)->exists())->toBeTrue();

    Livewire::test(ListTenantCompanies::class)
        ->callTableAction('destroy', $company->tenantCompany, data: [
            'irreversibility_confirmed' => true,
            'destruction_confirmed' => true,
        ])
        ->assertNotified('Cancellazione completata');

    expect(Company::query()->whereKey($company->id)->exists())->toBeFalse();
});

it('reports pending file cleanup without claiming database and storage atomicity', function (): void {
    $platformAdmin = User::factory()->platformAdmin()->create();
    $company = Company::factory()->create();
    $contract = Contract::factory()->create(['company_id' => $company->id]);
    Attachment::factory()->forContract($contract)->create([
        'storage_disk' => 'platform-cleanup-failure',
        'storage_path' => 'attachments/pending.pdf',
        'uploaded_by_id' => $platformAdmin->id,
    ]);
    $filesystem = Mockery::mock(Filesystem::class);
    $filesystem->shouldReceive('exists')->once()->andReturn(true);
    $filesystem->shouldReceive('delete')->once()->andReturn(false);
    Storage::set('platform-cleanup-failure', $filesystem);
    $this->actingAs($platformAdmin);

    Livewire::test(ListTenantCompanies::class)
        ->callTableAction('destroy', $company->tenantCompany, data: [
            'irreversibility_confirmed' => true,
            'destruction_confirmed' => true,
        ])
        ->assertNotified('Dati eliminati; pulizia file in attesa');

    expect(Company::query()->whereKey($company->id)->exists())->toBeFalse();
});
