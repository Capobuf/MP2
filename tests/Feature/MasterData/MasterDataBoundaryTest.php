<?php

use App\Domain\Company\Capability;
use App\Models\Company;
use App\Models\CompanyCapability;
use App\Models\CostCenter;
use App\Models\Supplier;
use App\Models\SupplierContact;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Schema;

uses(RefreshDatabase::class);

it('creates the complete forward S2 master data schema', function () {
    expect(Schema::hasColumns('suppliers', [
        'company_id',
        'legal_name',
        'vat_number',
        'notes',
        'archived_at',
    ]))->toBeTrue()
        ->and(Schema::hasColumns('supplier_contacts', [
            'supplier_id',
            'first_name',
            'last_name',
            'phone',
            'email',
            'notes',
            'role_tags',
        ]))->toBeTrue()
        ->and(Schema::hasColumns('cost_centers', [
            'company_id',
            'name',
            'archived_at',
        ]))->toBeTrue()
        ->and(Schema::hasColumn('audit_events', 'operation_id'))->toBeTrue();
});

it('keeps suppliers contacts and cost centers inside one company boundary', function () {
    $company = Company::factory()->create();
    $supplier = Supplier::factory()->for($company)->create();
    $contact = SupplierContact::factory()->for($supplier)->create();
    $costCenter = CostCenter::factory()->for($company)->create();

    expect($company->suppliers->modelKeys())->toBe([$supplier->id])
        ->and($company->costCenters->modelKeys())->toBe([$costCenter->id])
        ->and($supplier->company->is($company))->toBeTrue()
        ->and($supplier->contacts->modelKeys())->toBe([$contact->id])
        ->and($contact->supplier->is($supplier))->toBeTrue()
        ->and($contact->supplier->company->is($company))->toBeTrue()
        ->and($costCenter->company->is($company))->toBeTrue();
});

it('allows duplicate descriptive identities without merging records', function () {
    $company = Company::factory()->create();

    $supplierA = Supplier::factory()->for($company)->create([
        'legal_name' => 'Fornitore duplicato',
        'vat_number' => 'IT00000000000',
    ]);
    $supplierB = Supplier::factory()->for($company)->create([
        'legal_name' => 'Fornitore duplicato',
        'vat_number' => 'IT00000000000',
    ]);
    $costCenterA = CostCenter::factory()->for($company)->create(['name' => 'IT']);
    $costCenterB = CostCenter::factory()->for($company)->create(['name' => 'IT']);

    expect($supplierA->isNot($supplierB))->toBeTrue()
        ->and($costCenterA->isNot($costCenterB))->toBeTrue()
        ->and(Supplier::query()->count())->toBe(2)
        ->and(CostCenter::query()->count())->toBe(2);
});

it('keeps archived identities directly resolvable while active scopes exclude them', function () {
    $company = Company::factory()->create();
    $activeSupplier = Supplier::factory()->for($company)->create();
    $archivedSupplier = Supplier::factory()->for($company)->archived()->create();
    $activeCostCenter = CostCenter::factory()->for($company)->create();
    $archivedCostCenter = CostCenter::factory()->for($company)->archived()->create();

    expect(Supplier::query()->find($archivedSupplier->id)?->is($archivedSupplier))->toBeTrue()
        ->and(Supplier::query()->active()->pluck('id')->all())->toBe([$activeSupplier->id])
        ->and(Supplier::query()->archived()->pluck('id')->all())->toBe([$archivedSupplier->id])
        ->and(CostCenter::query()->find($archivedCostCenter->id)?->is($archivedCostCenter))->toBeTrue()
        ->and(CostCenter::query()->active()->pluck('id')->all())->toBe([$activeCostCenter->id])
        ->and(CostCenter::query()->archived()->pluck('id')->all())->toBe([$archivedCostCenter->id]);
});

it('rejects ordinary physical deletion of every persisted S2 identity', function () {
    $supplier = Supplier::factory()->create();
    $contact = SupplierContact::factory()->for($supplier)->create();
    $costCenter = CostCenter::factory()->for($supplier->company)->create();

    foreach ([$supplier, $contact, $costCenter] as $model) {
        expect(fn () => $model->delete())->toThrow(LogicException::class, 'Persisted master data cannot be deleted.');
    }

    expect(Supplier::query()->whereKey($supplier)->exists())->toBeTrue()
        ->and(SupplierContact::query()->whereKey($contact)->exists())->toBeTrue()
        ->and(CostCenter::query()->whereKey($costCenter)->exists())->toBeTrue();
});

it('authorizes master data only through exact-company capabilities', function () {
    $user = User::factory()->create();
    $companyA = Company::factory()->create();
    $companyB = Company::factory()->create();
    $supplierA = Supplier::factory()->for($companyA)->create();
    $supplierB = Supplier::factory()->for($companyB)->create();
    $contactA = SupplierContact::factory()->for($supplierA)->create();
    $costCenterA = CostCenter::factory()->for($companyA)->create();

    foreach ([Capability::View, Capability::ManageMasterData] as $capability) {
        CompanyCapability::query()->create([
            'company_id' => $companyA->id,
            'user_id' => $user->id,
            'capability' => $capability,
        ]);
    }

    expect(Gate::forUser($user)->allows('view', $supplierA))->toBeTrue()
        ->and(Gate::forUser($user)->allows('update', $supplierA))->toBeTrue()
        ->and(Gate::forUser($user)->allows('update', $contactA))->toBeTrue()
        ->and(Gate::forUser($user)->allows('update', $costCenterA))->toBeTrue()
        ->and(Gate::forUser($user)->allows('view', $supplierB))->toBeFalse()
        ->and(Gate::forUser($user)->allows('update', $supplierB))->toBeFalse();
});
