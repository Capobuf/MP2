<?php

use App\Actions\MasterData\CreateSupplierContact;
use App\Actions\MasterData\UpdateSupplierContact;
use App\Domain\Company\AuditEventType;
use App\Models\AuditEvent;
use App\Models\Company;
use App\Models\Supplier;
use App\Models\SupplierContact;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Tests\Support\TestPermissions;

uses(RefreshDatabase::class);

function grantContactManagement(User $user, Company $company): void
{
    grantTestPermissions([
        'company_id' => $company->id,
        'user' => $user,
        'permissions' => TestPermissions::MANAGE_MASTER_DATA,
    ]);
}

it('creates contacts with entirely optional fields and free role tags idempotently', function () {
    $actor = User::factory()->create();
    $company = Company::factory()->create();
    grantContactManagement($actor, $company);
    $supplier = Supplier::factory()->for($company)->create();
    $operationId = (string) Str::uuid();

    $empty = app(CreateSupplierContact::class)->execute($actor, $supplier, [], $operationId);
    $retried = app(CreateSupplierContact::class)->execute($actor, $supplier, [], $operationId);
    $tagged = app(CreateSupplierContact::class)->execute($actor, $supplier, [
        'first_name' => ' Ada ',
        'last_name' => ' Lovelace ',
        'phone' => ' 123 ',
        'email' => ' ada@example.test ',
        'notes' => ' Nota ',
        'role_tags' => ['Tecnico', 'Acquisti speciali'],
    ], (string) Str::uuid());

    expect($empty->id)->toBe($retried->id)
        ->and($empty->first_name)->toBeNull()
        ->and($empty->role_tags)->toBe([])
        ->and($tagged->first_name)->toBe('Ada')
        ->and($tagged->email)->toBe('ada@example.test')
        ->and($tagged->role_tags)->toBe(['Tecnico', 'Acquisti speciali'])
        ->and(AuditEvent::query()->count())->toBe(2);

    $event = AuditEvent::query()->where('subject_id', $tagged->id)->sole();

    expect($event->eventType())->toBe(AuditEventType::SupplierContactCreated)
        ->and($event->company_id)->toBe($company->id)
        ->and($event->subject_type)->toBe(SupplierContact::class)
        ->and($event->affected_exercise_ids)->toBe([])
        ->and($event->allocated_impact_by_exercise)->toBe([])
        ->and($event->actual_impact_by_exercise)->toBe([])
        ->and($event->previous_value)->toBeNull();
});

it('updates a contact without changing identity and emits nothing for a no-op', function () {
    $actor = User::factory()->create();
    $company = Company::factory()->create();
    grantContactManagement($actor, $company);
    $contact = SupplierContact::factory()->for(Supplier::factory()->for($company))->create([
        'first_name' => 'Prima',
        'role_tags' => [],
    ]);

    $updated = app(UpdateSupplierContact::class)->execute($actor, $contact, [
        'first_name' => 'Dopo',
        'last_name' => null,
        'phone' => null,
        'email' => null,
        'notes' => null,
        'role_tags' => ['Amministrazione'],
    ], (string) Str::uuid());
    $event = AuditEvent::query()->sole();

    app(UpdateSupplierContact::class)->execute($actor, $updated, [
        'first_name' => 'Dopo',
        'last_name' => null,
        'phone' => null,
        'email' => null,
        'notes' => null,
        'role_tags' => ['Amministrazione'],
    ], (string) Str::uuid());

    expect($updated->id)->toBe($contact->id)
        ->and($updated->role_tags)->toBe(['Amministrazione'])
        ->and($event->eventType())->toBe(AuditEventType::SupplierContactUpdated)
        ->and($event->previous_value['first_name'])->toBe('Prima')
        ->and($event->new_value['first_name'])->toBe('Dopo')
        ->and(AuditEvent::query()->count())->toBe(1);
});

it('validates contact input and exact-company authorization', function () {
    $actor = User::factory()->create();
    $companyA = Company::factory()->create();
    $companyB = Company::factory()->create();
    grantContactManagement($actor, $companyA);
    $supplierB = Supplier::factory()->for($companyB)->create();

    expect(fn () => app(CreateSupplierContact::class)->execute($actor, $supplierB, [], (string) Str::uuid()))
        ->toThrow(AuthorizationException::class)
        ->and(fn () => app(CreateSupplierContact::class)->execute($actor, $supplierB, [
            'email' => 'non-valida',
        ], (string) Str::uuid()))->toThrow(ValidationException::class);

    expect(SupplierContact::query()->count())->toBe(0)
        ->and(AuditEvent::query()->count())->toBe(0);
});

it('rolls contact persistence back when audit persistence fails', function () {
    $actor = User::factory()->create();
    $company = Company::factory()->create();
    grantContactManagement($actor, $company);
    $supplier = Supplier::factory()->for($company)->create();

    AuditEvent::creating(function (): never {
        throw new RuntimeException('audit unavailable');
    });

    expect(fn () => app(CreateSupplierContact::class)->execute($actor, $supplier, [], (string) Str::uuid()))
        ->toThrow(RuntimeException::class, 'audit unavailable');

    expect(SupplierContact::query()->count())->toBe(0)
        ->and(AuditEvent::query()->count())->toBe(0);
});
