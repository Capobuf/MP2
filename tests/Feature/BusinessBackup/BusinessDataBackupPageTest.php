<?php

use App\Filament\Pages\BusinessDataBackup;
use App\Filament\Platform\Pages\ImportCompanyBackup;
use App\Models\Company;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\Support\TestPermissions;

uses(RefreshDatabase::class);

it('shows tenant backup only to a viewer and hides Drive when unconfigured', function (): void {
    $company = Company::factory()->create();
    $viewer = User::factory()->create();
    grantTestPermissions(['company_id' => $company->id, 'user' => $viewer, 'permissions' => TestPermissions::VIEW]);
    config()->set('filesystems.disks.google', ['driver' => 'google']);
    $this->actingAs($viewer);
    Filament::setCurrentPanel('admin');
    Filament::setTenant($company->tenantCompany);

    Livewire::test(BusinessDataBackup::class)
        ->assertSuccessful()
        ->assertSee('Un File, Tutto il Patrimonio Business')
        ->assertSee('logo deve essere configurato nuovamente')
        ->assertActionVisible('download')
        ->assertActionHidden('importCompany')
        ->assertActionHidden('drive');

    $admin = User::factory()->platformAdmin()->create();
    grantTestPermissions(['company_id' => $company->id, 'user' => $admin, 'permissions' => TestPermissions::VIEW]);
    $this->actingAs($admin);
    Livewire::test(BusinessDataBackup::class)
        ->assertActionVisible('download')
        ->assertActionVisible('importCompany')
        ->assertActionHasLabel('importCompany', 'Importa Nuova Azienda')
        ->assertActionHasUrl('importCompany', ImportCompanyBackup::getUrl(panel: 'platform'));

    $outsider = User::factory()->create();
    $this->actingAs($outsider);
    expect(BusinessDataBackup::canAccess())->toBeFalse();
});
