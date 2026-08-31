<?php

use App\Filament\Resources\Suppliers\Pages\CreateSupplier;
use App\Filament\Resources\Suppliers\Pages\ListSuppliers;
use App\Filament\Resources\Suppliers\Pages\ViewSupplier;
use App\Filament\Resources\Suppliers\SupplierResource;
use App\Models\Company;
use App\Models\Supplier;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\Support\TestPermissions;

uses(RefreshDatabase::class);

function grantSupplierResourceCapabilities(User $user, Company $company, bool $manage = true): void
{
    $capabilities = [TestPermissions::VIEW];

    if ($manage) {
        $capabilities[] = TestPermissions::MANAGE_MASTER_DATA;
    }

    foreach ($capabilities as $capability) {
        grantTestPermissions([
            'company_id' => $company->id,
            'user' => $user,
            'permissions' => $capability,
        ]);
    }
}

it('lists and views only suppliers from the current tenant', function () {
    $viewer = User::factory()->create();
    $companyA = Company::factory()->create();
    $companyB = Company::factory()->create();
    grantSupplierResourceCapabilities($viewer, $companyA, manage: false);
    $supplierA = Supplier::factory()->for($companyA)->create();
    $supplierB = Supplier::factory()->for($companyB)->create();
    $this->actingAs($viewer);
    Filament::setTenant(($companyA)->tenantCompany);

    Livewire::test(ListSuppliers::class)
        ->assertCanSeeTableRecords([$supplierA])
        ->assertCanNotSeeTableRecords([$supplierB]);

    Livewire::test(ViewSupplier::class, ['record' => $supplierA->getRouteKey()])
        ->assertSuccessful();

    $this->get(SupplierResource::getUrl('view', ['record' => $supplierB], tenant: $companyA))
        ->assertNotFound();
});

it('creates suppliers from the tenant resource only for a master data manager', function () {
    $manager = User::factory()->create();
    $company = Company::factory()->create();
    grantSupplierResourceCapabilities($manager, $company);
    $this->actingAs($manager);
    Filament::setTenant(($company)->tenantCompany);

    Livewire::test(CreateSupplier::class)
        ->fillForm([
            'legal_name' => 'Fornitore UI',
            'vat_number' => 'IT123',
            'notes' => null,
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    expect(Supplier::query()->sole()->company_id)->toBe($company->id);

    $viewer = User::factory()->create();
    grantSupplierResourceCapabilities($viewer, $company, manage: false);
    $this->actingAs($viewer);
    Filament::setTenant(($company)->tenantCompany);

    $this->get(SupplierResource::getUrl('create', tenant: $company))->assertForbidden();
});

it('creates a distinct supplier after save and create another', function () {
    $manager = User::factory()->create();
    $company = Company::factory()->create();
    grantSupplierResourceCapabilities($manager, $company);
    $this->actingAs($manager);
    Filament::setTenant(($company)->tenantCompany);

    Livewire::test(CreateSupplier::class)
        ->fillForm([
            'legal_name' => 'Primo fornitore',
            'vat_number' => null,
            'notes' => null,
        ])
        ->call('create', true)
        ->assertHasNoFormErrors()
        ->fillForm([
            'legal_name' => 'Secondo fornitore',
            'vat_number' => null,
            'notes' => null,
        ])
        ->call('create', true)
        ->assertHasNoFormErrors();

    expect(Supplier::query()->orderBy('id')->pluck('legal_name')->all())
        ->toBe(['Primo fornitore', 'Secondo fornitore']);
});

it('defaults to active suppliers and exposes archived and all filters without delete', function () {
    $manager = User::factory()->create();
    $company = Company::factory()->create();
    grantSupplierResourceCapabilities($manager, $company);
    $active = Supplier::factory()->for($company)->create();
    $archived = Supplier::factory()->for($company)->archived()->create();
    $this->actingAs($manager);
    Filament::setTenant(($company)->tenantCompany);

    $component = Livewire::test(ListSuppliers::class)
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
