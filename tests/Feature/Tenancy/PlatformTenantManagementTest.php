<?php

use App\Domain\Company\TenantCompanyStatus;
use App\Filament\Pages\Tenancy\RegisterCompany;
use App\Filament\Platform\Pages\ImportCompanyBackup;
use App\Filament\Platform\Resources\TenantCompanies\Pages\ListTenantCompanies;
use App\Filament\Platform\Resources\TenantCompanies\TenantCompanyResource;
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
        ->get('/platform')
        ->assertRedirect('/platform/tenant-companies');

    $this->actingAs($platformAdmin)
        ->get('/platform/tenant-companies')
        ->assertOk();

    $this->actingAs($ordinaryUser)
        ->get('/platform')
        ->assertForbidden();

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
        ->assertActionHasLabel('createCompany', 'Nuova Azienda')
        ->assertActionHasUrl('createCompany', Filament::getPanel('admin')->getTenantRegistrationUrl())
        ->assertActionHasLabel('importCompany', 'Importa Azienda')
        ->assertActionHasUrl('importCompany', ImportCompanyBackup::getUrl(panel: 'platform'))
        ->assertTableActionHasLabel('openTenant', 'Apri Amministrazione', $active)
        ->assertTableActionHasUrl('openTenant', Filament::getPanel('admin')->getUrl($active), $active)
        ->assertTableActionVisible('archive', $active)
        ->assertTableActionHidden('restore', $active)
        ->assertTableActionVisible('destroy', $active)
        ->assertTableActionHidden('archive', $archived)
        ->assertTableActionVisible('restore', $archived)
        ->assertTableActionVisible('destroy', $archived);

    $settingsItems = collect(Filament::getNavigation())
        ->first(fn ($group): bool => $group->getLabel() === 'Impostazioni')
        ?->getItems();
    $navigationGroups = collect(Filament::getNavigation());
    $settings = $navigationGroups
        ->first(fn ($group): bool => $group->getLabel() === 'Impostazioni');
    $adminEntryUrl = route('filament.admin.tenant');

    expect(TenantCompanyResource::getNavigationLabel())->toBe('Aziende')
        ->and(ImportCompanyBackup::shouldRegisterNavigation())->toBeFalse()
        ->and(Filament::getPanel('admin')->getTenantRegistrationPage())->toBe(RegisterCompany::class)
        ->and($navigationGroups->map(fn ($group): ?string => $group->getLabel())->values()->all())
        ->toBe([null, 'Impostazioni'])
        ->and($settings?->isCollapsed())->toBeTrue()
        ->and($settings?->isCollapsible())->toBeTrue()
        ->and(collect($settingsItems)->map(fn ($item): string => $item->getLabel())->all())
        ->toBe(['Super Admin', 'Ruoli'])
        ->and($adminEntryUrl)->toBe(url('/admin'));

    $this->get('/platform/tenant-companies')
        ->assertOk()
        ->assertSee('aria-label="Master Plan IT"', escape: false)
        ->assertSee('Vai alle Aziende')
        ->assertSee('href="'.$adminEntryUrl.'"', escape: false)
        ->assertSee('Riduci Menu');
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
        ->assertNotified('Cancellazione Completata');

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
        ->assertNotified('Dati Eliminati; Pulizia File in Attesa');

    expect(Company::query()->whereKey($company->id)->exists())->toBeFalse();
});
