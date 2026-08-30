<?php

use App\Domain\Company\Capability;
use App\Filament\Pages\BusinessDataBackup;
use App\Filament\Platform\Pages\ImportCompanyBackup;
use App\Models\Company;
use App\Models\CompanyCapability;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

it('shows tenant backup only to a viewer and hides Drive when unconfigured', function (): void {
    $company = Company::factory()->create();
    $viewer = User::factory()->create();
    CompanyCapability::query()->create(['company_id' => $company->id, 'user_id' => $viewer->id, 'capability' => Capability::View]);
    config()->set('filesystems.disks.google', ['driver' => 'google']);
    $this->actingAs($viewer);
    Filament::setCurrentPanel('admin');
    Filament::setTenant($company->tenantCompany);

    Livewire::test(BusinessDataBackup::class)
        ->assertSuccessful()
        ->assertSee('Un file, tutto il patrimonio business')
        ->assertActionVisible('download')
        ->assertActionHidden('importCompany')
        ->assertActionHidden('drive');

    $admin = User::factory()->platformAdmin()->create();
    CompanyCapability::query()->create(['company_id' => $company->id, 'user_id' => $admin->id, 'capability' => Capability::View]);
    $this->actingAs($admin);
    Livewire::test(BusinessDataBackup::class)
        ->assertActionVisible('download')
        ->assertActionVisible('importCompany')
        ->assertActionHasLabel('importCompany', 'Importa nuova Azienda')
        ->assertActionHasUrl('importCompany', ImportCompanyBackup::getUrl(panel: 'platform'));

    $outsider = User::factory()->create();
    $this->actingAs($outsider);
    expect(BusinessDataBackup::canAccess())->toBeFalse();
});
