<?php

use App\Actions\MasterData\CreateSupplier;
use App\Actions\MasterData\UpdateSupplier;
use App\Domain\Company\AuditEventType;
use App\Domain\Company\Capability;
use App\Models\AuditEvent;
use App\Models\Company;
use App\Models\CompanyCapability;
use App\Models\Supplier;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

uses(RefreshDatabase::class);

function grantSupplierManagement(User $user, Company $company): void
{
    foreach ([Capability::View, Capability::ManageMasterData] as $capability) {
        CompanyCapability::query()->create([
            'company_id' => $company->id,
            'user_id' => $user->id,
            'capability' => $capability,
        ]);
    }
}

it('creates a supplier with a complete audit event and retries idempotently', function () {
    $actor = User::factory()->create();
    $company = Company::factory()->create(['timezone' => 'Europe/Rome']);
    grantSupplierManagement($actor, $company);
    $operationId = (string) Str::uuid();

    $supplier = app(CreateSupplier::class)->execute($actor, $company, [
        'legal_name' => '  Fornitore S.r.l.  ',
        'vat_number' => '  IT12345678901  ',
        'notes' => '  Nota  ',
    ], $operationId);
    $retry = app(CreateSupplier::class)->execute($actor, $company, [
        'legal_name' => 'Fornitore S.r.l.',
    ], $operationId);
    $event = AuditEvent::query()->sole();

    expect($retry->is($supplier))->toBeTrue()
        ->and(Supplier::query()->count())->toBe(1)
        ->and($supplier->legal_name)->toBe('Fornitore S.r.l.')
        ->and($supplier->vat_number)->toBe('IT12345678901')
        ->and($supplier->notes)->toBe('Nota')
        ->and($event->operation_id)->toBe($operationId)
        ->and($event->event_type)->toBe(AuditEventType::SupplierCreated)
        ->and($event->company_id)->toBe($company->id)
        ->and($event->subject_type)->toBe(Supplier::class)
        ->and($event->subject_id)->toBe($supplier->id)
        ->and($event->affected_exercise_ids)->toBe([])
        ->and($event->allocated_impact_by_exercise)->toBe([])
        ->and($event->actual_impact_by_exercise)->toBe([])
        ->and($event->effective_from->toDateString())->toBe(now('Europe/Rome')->toDateString())
        ->and($event->previous_value)->toBeNull()
        ->and($event->new_value['legal_name'])->toBe('Fornitore S.r.l.')
        ->and($event->new_value['vat_number'])->toBe('IT12345678901')
        ->and($event->new_value['notes'])->toBe('Nota')
        ->and($event->new_value['archived'])->toBeFalse();
});

it('allows duplicate supplier names and VAT numbers', function () {
    $actor = User::factory()->create();
    $company = Company::factory()->create();
    grantSupplierManagement($actor, $company);

    foreach (range(1, 2) as $unused) {
        app(CreateSupplier::class)->execute($actor, $company, [
            'legal_name' => 'Duplicato',
            'vat_number' => 'IT00000000000',
        ], (string) Str::uuid());
    }

    expect(Supplier::query()->count())->toBe(2)
        ->and(AuditEvent::query()->count())->toBe(2);
});

it('updates a supplier with stable identity and no event for a no-op', function () {
    $actor = User::factory()->create();
    $company = Company::factory()->create();
    grantSupplierManagement($actor, $company);
    $supplier = Supplier::factory()->for($company)->create([
        'legal_name' => 'Prima',
        'vat_number' => null,
        'notes' => null,
    ]);

    $updated = app(UpdateSupplier::class)->execute($actor, $supplier, [
        'legal_name' => '  Dopo  ',
        'vat_number' => null,
        'notes' => ' Nota ',
    ], (string) Str::uuid());
    $event = AuditEvent::query()->sole();
    app(UpdateSupplier::class)->execute($actor, $supplier, [
        'legal_name' => 'Dopo',
        'vat_number' => null,
        'notes' => 'Nota',
    ], (string) Str::uuid());

    expect($updated->id)->toBe($supplier->id)
        ->and($updated->legal_name)->toBe('Dopo')
        ->and($updated->notes)->toBe('Nota')
        ->and(AuditEvent::query()->count())->toBe(1)
        ->and($event->event_type)->toBe(AuditEventType::SupplierUpdated)
        ->and($event->previous_value['legal_name'])->toBe('Prima')
        ->and($event->new_value['legal_name'])->toBe('Dopo');
});

it('rejects invalid and unauthorized supplier mutations', function () {
    $actor = User::factory()->create();
    $companyA = Company::factory()->create();
    $companyB = Company::factory()->create();
    grantSupplierManagement($actor, $companyA);
    $supplierB = Supplier::factory()->for($companyB)->create();

    expect(fn () => app(CreateSupplier::class)->execute($actor, $companyA, [
        'legal_name' => '   ',
    ], (string) Str::uuid()))->toThrow(ValidationException::class)
        ->and(fn () => app(CreateSupplier::class)->execute($actor, $companyB, [
            'legal_name' => 'Non autorizzato',
        ], (string) Str::uuid()))->toThrow(AuthorizationException::class)
        ->and(fn () => app(UpdateSupplier::class)->execute($actor, $supplierB, [
            'legal_name' => 'Non autorizzato',
        ], (string) Str::uuid()))->toThrow(AuthorizationException::class);

    expect(Supplier::query()->count())->toBe(1)
        ->and(AuditEvent::query()->count())->toBe(0);
});

it('rolls supplier changes back when audit persistence fails', function () {
    $actor = User::factory()->create();
    $company = Company::factory()->create();
    grantSupplierManagement($actor, $company);
    AuditEvent::creating(function (): never {
        throw new RuntimeException('Forced audit failure');
    });

    expect(fn () => app(CreateSupplier::class)->execute($actor, $company, [
        'legal_name' => 'Rollback',
    ], (string) Str::uuid()))->toThrow(RuntimeException::class, 'Forced audit failure');

    expect(Supplier::query()->count())->toBe(0)
        ->and(AuditEvent::query()->count())->toBe(0);

    AuditEvent::flushEventListeners();
});
