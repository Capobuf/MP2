<?php

use App\Actions\MasterData\SetCostCenterArchived;
use App\Actions\MasterData\SetSupplierArchived;
use App\Domain\Company\AuditEventType;
use App\Domain\Company\Capability;
use App\Models\AuditEvent;
use App\Models\Company;
use App\Models\CompanyCapability;
use App\Models\CostCenter;
use App\Models\Supplier;
use App\Models\SupplierContact;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

function grantArchiveManagement(User $user, Company $company): void
{
    CompanyCapability::query()->create([
        'company_id' => $company->id,
        'user_id' => $user->id,
        'capability' => Capability::ManageMasterData,
    ]);
}

it('archives and restores a supplier without losing identity contacts or direct resolution', function () {
    $actor = User::factory()->create();
    $company = Company::factory()->create();
    grantArchiveManagement($actor, $company);
    $supplier = Supplier::factory()->for($company)->create();
    $contact = SupplierContact::factory()->for($supplier)->create();
    $archiveOperation = (string) Str::uuid();

    $archived = app(SetSupplierArchived::class)->execute($actor, $supplier, true, $archiveOperation);
    $retried = app(SetSupplierArchived::class)->execute($actor, $supplier, true, $archiveOperation);
    app(SetSupplierArchived::class)->execute($actor, $supplier, true, (string) Str::uuid());

    expect($archived->id)->toBe($supplier->id)
        ->and($retried->id)->toBe($supplier->id)
        ->and($archived->isArchived())->toBeTrue()
        ->and(Supplier::query()->find($supplier->id)?->id)->toBe($supplier->id)
        ->and(Supplier::query()->active()->find($supplier->id))->toBeNull()
        ->and(Supplier::query()->archived()->find($supplier->id)?->id)->toBe($supplier->id)
        ->and($supplier->contacts()->sole()->id)->toBe($contact->id)
        ->and(AuditEvent::query()->count())->toBe(1);

    $restored = app(SetSupplierArchived::class)->execute($actor, $supplier, false, (string) Str::uuid());

    expect($restored->id)->toBe($supplier->id)
        ->and($restored->isArchived())->toBeFalse()
        ->and(Supplier::query()->active()->find($supplier->id)?->id)->toBe($supplier->id)
        ->and(AuditEvent::query()->pluck('event_type')->all())->toBe([
            AuditEventType::SupplierArchived,
            AuditEventType::SupplierRestored,
        ]);
});

it('archives and restores a cost center with the same stable identity', function () {
    $actor = User::factory()->create();
    $company = Company::factory()->create();
    grantArchiveManagement($actor, $company);
    $costCenter = CostCenter::factory()->for($company)->create();

    $archived = app(SetCostCenterArchived::class)->execute($actor, $costCenter, true, (string) Str::uuid());
    app(SetCostCenterArchived::class)->execute($actor, $costCenter, true, (string) Str::uuid());
    $restored = app(SetCostCenterArchived::class)->execute($actor, $costCenter, false, (string) Str::uuid());

    expect($archived->id)->toBe($costCenter->id)
        ->and($restored->id)->toBe($costCenter->id)
        ->and($restored->isArchived())->toBeFalse()
        ->and(CostCenter::query()->find($costCenter->id)?->id)->toBe($costCenter->id)
        ->and(AuditEvent::query()->count())->toBe(2)
        ->and(AuditEvent::query()->firstOrFail()->eventType())->toBe(AuditEventType::CostCenterArchived)
        ->and(AuditEvent::query()->latest('id')->firstOrFail()->eventType())->toBe(AuditEventType::CostCenterRestored);
});

it('rejects cross-company archive operations and all physical deletion', function () {
    $actor = User::factory()->create();
    $companyA = Company::factory()->create();
    $companyB = Company::factory()->create();
    grantArchiveManagement($actor, $companyA);
    $supplierB = Supplier::factory()->for($companyB)->create();
    $costCenterB = CostCenter::factory()->for($companyB)->create();

    expect(fn () => app(SetSupplierArchived::class)->execute($actor, $supplierB, true, (string) Str::uuid()))
        ->toThrow(AuthorizationException::class)
        ->and(fn () => app(SetCostCenterArchived::class)->execute($actor, $costCenterB, true, (string) Str::uuid()))
        ->toThrow(AuthorizationException::class)
        ->and(fn () => $supplierB->delete())->toThrow(LogicException::class)
        ->and(fn () => $costCenterB->delete())->toThrow(LogicException::class);

    expect(AuditEvent::query()->count())->toBe(0);
});

it('rolls archive state back when audit persistence fails', function () {
    $actor = User::factory()->create();
    $company = Company::factory()->create();
    grantArchiveManagement($actor, $company);
    $supplier = Supplier::factory()->for($company)->create();

    AuditEvent::creating(function (): never {
        throw new RuntimeException('audit unavailable');
    });

    expect(fn () => app(SetSupplierArchived::class)->execute($actor, $supplier, true, (string) Str::uuid()))
        ->toThrow(RuntimeException::class, 'audit unavailable');

    expect($supplier->refresh()->isArchived())->toBeFalse()
        ->and(AuditEvent::query()->count())->toBe(0);
});
