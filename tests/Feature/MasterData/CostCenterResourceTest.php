<?php

use App\Domain\Company\Capability;
use App\Filament\Resources\CostCenters\CostCenterResource;
use App\Filament\Resources\CostCenters\Pages\CreateCostCenter;
use App\Filament\Resources\CostCenters\Pages\ListCostCenters;
use App\Filament\Resources\CostCenters\Pages\ViewCostCenter;
use App\Models\Company;
use App\Models\CompanyCapability;
use App\Models\CostCenter;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

function grantCostCenterResourceCapabilities(User $user, Company $company, bool $manage = true): void
{
    foreach ($manage ? [Capability::View, Capability::ManageMasterData] : [Capability::View] as $capability) {
        CompanyCapability::query()->create([
            'company_id' => $company->id,
            'user_id' => $user->id,
            'capability' => $capability,
        ]);
    }
}

it('lists and resolves cost centers only inside the current tenant', function () {
    $viewer = User::factory()->create();
    $companyA = Company::factory()->create();
    $companyB = Company::factory()->create();
    grantCostCenterResourceCapabilities($viewer, $companyA, manage: false);
    $costCenterA = CostCenter::factory()->for($companyA)->create();
    $costCenterB = CostCenter::factory()->for($companyB)->create();
    $this->actingAs($viewer);
    Filament::setTenant(($companyA)->tenantCompany);

    Livewire::test(ListCostCenters::class)
        ->assertCanSeeTableRecords([$costCenterA])
        ->assertCanNotSeeTableRecords([$costCenterB]);

    Livewire::test(ViewCostCenter::class, ['record' => $costCenterA->getRouteKey()])
        ->assertSuccessful();

    $this->get(CostCenterResource::getUrl('view', ['record' => $costCenterB], tenant: $companyA))
        ->assertNotFound();
});

it('creates cost centers for managers without exposing later-slice fields', function () {
    $manager = User::factory()->create();
    $company = Company::factory()->create();
    grantCostCenterResourceCapabilities($manager, $company);
    $this->actingAs($manager);
    Filament::setTenant(($company)->tenantCompany);

    Livewire::test(CreateCostCenter::class)
        ->assertFormFieldDoesNotExist('exercise_id')
        ->assertFormFieldDoesNotExist('classification')
        ->assertFormFieldDoesNotExist('parent_id')
        ->assertFormFieldDoesNotExist('percentage')
        ->assertFormFieldDoesNotExist('amount')
        ->fillForm(['name' => 'Operations'])
        ->call('create')
        ->assertHasNoFormErrors();

    expect(CostCenter::query()->sole()->company_id)->toBe($company->id);

    $viewer = User::factory()->create();
    grantCostCenterResourceCapabilities($viewer, $company, manage: false);
    $this->actingAs($viewer);
    Filament::setTenant(($company)->tenantCompany);

    $this->get(CostCenterResource::getUrl('create', tenant: $company))->assertForbidden();
});

it('creates a distinct cost center after save and create another', function () {
    $manager = User::factory()->create();
    $company = Company::factory()->create();
    grantCostCenterResourceCapabilities($manager, $company);
    $this->actingAs($manager);
    Filament::setTenant(($company)->tenantCompany);

    Livewire::test(CreateCostCenter::class)
        ->fillForm(['name' => 'Operations'])
        ->call('create', true)
        ->assertHasNoFormErrors()
        ->fillForm(['name' => 'Amministrazione'])
        ->call('create', true)
        ->assertHasNoFormErrors();

    expect(CostCenter::query()->orderBy('id')->pluck('name')->all())
        ->toBe(['Operations', 'Amministrazione']);
});

it('defaults to active cost centers and exposes archived and all filters without delete', function () {
    $manager = User::factory()->create();
    $company = Company::factory()->create();
    grantCostCenterResourceCapabilities($manager, $company);
    $active = CostCenter::factory()->for($company)->create();
    $archived = CostCenter::factory()->for($company)->archived()->create();
    $this->actingAs($manager);
    Filament::setTenant(($company)->tenantCompany);

    $component = Livewire::test(ListCostCenters::class)
        ->assertCanSeeTableRecords([$active])
        ->assertCanNotSeeTableRecords([$archived])
        ->assertTableActionDoesNotExist('delete', record: $active)
        ->callTableAction('archive', record: $active)
        ->assertHasNoTableActionErrors();

    expect($active->refresh()->isArchived())->toBeTrue();

    $component->filterTable('archived_at', true)
        ->assertCanSeeTableRecords([$active, $archived])
        ->callTableAction('restore', record: $active)
        ->assertHasNoTableActionErrors();

    expect($active->refresh()->isArchived())->toBeFalse();

    $component->filterTable('archived_at', null)
        ->assertCanSeeTableRecords([$active, $archived]);
});
