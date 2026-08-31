<?php

use App\Filament\Resources\Suppliers\Pages\EditSupplier;
use App\Filament\Resources\Suppliers\Pages\ViewSupplier;
use App\Filament\Resources\Suppliers\RelationManagers\ContactsRelationManager;
use App\Models\Company;
use App\Models\Supplier;
use App\Models\SupplierContact;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\Support\TestPermissions;

uses(RefreshDatabase::class);

function grantContactResourceCapabilities(User $user, Company $company, bool $manage = true): void
{
    grantTestPermissions([
        'company_id' => $company->id,
        'user' => $user,
        'permissions' => TestPermissions::VIEW,
    ]);

    if ($manage) {
        grantTestPermissions([
            'company_id' => $company->id,
            'user' => $user,
            'permissions' => TestPermissions::MANAGE_MASTER_DATA,
        ]);
    }
}

it('shows only contacts of the tenant supplier to read-only viewers', function () {
    $viewer = User::factory()->create();
    $company = Company::factory()->create();
    grantContactResourceCapabilities($viewer, $company, manage: false);
    $supplier = Supplier::factory()->for($company)->create();
    $contact = SupplierContact::factory()->for($supplier)->create();
    $otherContact = SupplierContact::factory()->create();
    $this->actingAs($viewer);
    Filament::setTenant(($company)->tenantCompany);

    Livewire::test(ContactsRelationManager::class, [
        'ownerRecord' => $supplier,
        'pageClass' => ViewSupplier::class,
    ])
        ->assertCanSeeTableRecords([$contact])
        ->assertCanNotSeeTableRecords([$otherContact])
        ->assertTableActionDoesNotExist('delete', record: $contact)
        ->assertTableActionDoesNotExist('archive', record: $contact)
        ->assertTableActionHidden('edit', record: $contact);
});

it('creates and edits contacts for managers without lifecycle controls', function () {
    $manager = User::factory()->create();
    $company = Company::factory()->create();
    grantContactResourceCapabilities($manager, $company);
    $supplier = Supplier::factory()->for($company)->create();
    $this->actingAs($manager);
    Filament::setTenant(($company)->tenantCompany);

    $component = Livewire::test(ContactsRelationManager::class, [
        'ownerRecord' => $supplier,
        'pageClass' => EditSupplier::class,
    ])->callTableAction('create', data: [
        'first_name' => 'Ada',
        'last_name' => null,
        'phone' => null,
        'email' => 'ada@example.test',
        'notes' => null,
        'role_tags' => ['Tecnico'],
    ])->assertHasNoTableActionErrors();

    $contact = SupplierContact::query()->sole();

    $component->callTableAction('edit', record: $contact, data: [
        'first_name' => 'Ada Maria',
        'last_name' => null,
        'phone' => null,
        'email' => 'ada@example.test',
        'notes' => null,
        'role_tags' => [],
    ])->assertHasNoTableActionErrors()
        ->assertTableActionDoesNotExist('delete', record: $contact)
        ->assertTableActionDoesNotExist('archive', record: $contact);

    expect($contact->refresh()->first_name)->toBe('Ada Maria')
        ->and($contact->supplier_id)->toBe($supplier->id);
});
