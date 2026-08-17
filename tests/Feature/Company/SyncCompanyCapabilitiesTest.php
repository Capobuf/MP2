<?php

use App\Actions\CreateCompany;
use App\Actions\SyncCompanyCapabilities;
use App\Domain\Company\AuditEventType;
use App\Domain\Company\Capability;
use App\Models\AuditEvent;
use App\Models\CompanyCapability;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('synchronizes exact company capabilities with one complete event per change', function () {
    $administrator = User::factory()->platformAdmin()->create();
    $beneficiary = User::factory()->create();
    $company = app(CreateCompany::class)->execute($administrator, [
        'name' => 'Azienda',
        'timezone' => 'Europe/Rome',
    ]);

    $changes = app(SyncCompanyCapabilities::class)->execute(
        $administrator,
        $company,
        $beneficiary,
        [Capability::View, Capability::ManageSettings],
        'Accesso iniziale',
    );

    expect($changes)->toBe(2)
        ->and($beneficiary->hasCapability($company, Capability::View))->toBeTrue()
        ->and($beneficiary->hasCapability($company, Capability::ManageSettings))->toBeTrue();

    $events = AuditEvent::query()
        ->where('company_id', $company->id)
        ->where('beneficiary_id', $beneficiary->id)
        ->where('event_type', AuditEventType::CapabilityAssigned->value)
        ->get();

    expect($events)->toHaveCount(2);

    foreach ($events as $event) {
        expect($event->subject_type)->toBe(User::class)
            ->and($event->subject_id)->toBe($beneficiary->id)
            ->and($event->previous_value)->toBeFalse()
            ->and($event->new_value)->toBeTrue()
            ->and($event->reason)->toBe('Accesso iniziale')
            ->and($event->affected_exercise_ids)->toBe([])
            ->and($event->allocated_impact_by_exercise)->toBe([])
            ->and($event->actual_impact_by_exercise)->toBe([]);
    }
});

it('revokes missing capabilities and treats an identical set as a no-op', function () {
    $administrator = User::factory()->platformAdmin()->create();
    $beneficiary = User::factory()->create();
    $company = app(CreateCompany::class)->execute($administrator, [
        'name' => 'Azienda',
        'timezone' => 'Europe/Rome',
    ]);
    $action = app(SyncCompanyCapabilities::class);

    $action->execute($administrator, $company, $beneficiary, [
        Capability::View,
        Capability::ManageSettings,
    ]);
    $eventsBeforeNoOp = AuditEvent::query()->count();

    expect($action->execute($administrator, $company, $beneficiary, [
        Capability::View,
        Capability::ManageSettings,
    ]))->toBe(0)
        ->and(AuditEvent::query()->count())->toBe($eventsBeforeNoOp);

    expect($action->execute($administrator, $company, $beneficiary, [
        Capability::View,
    ]))->toBe(1)
        ->and($beneficiary->hasCapability($company, Capability::ManageSettings))->toBeFalse();

    $revocation = AuditEvent::query()
        ->where('beneficiary_id', $beneficiary->id)
        ->where('event_type', AuditEventType::CapabilityRevoked->value)
        ->sole();

    expect($revocation->previous_value)->toBeTrue()
        ->and($revocation->new_value)->toBeFalse();
});

it('allows self revocation and applies the new authorization to later requests', function () {
    $administrator = User::factory()->platformAdmin()->create();
    $company = app(CreateCompany::class)->execute($administrator, [
        'name' => 'Azienda',
        'timezone' => 'Europe/Rome',
    ]);
    $action = app(SyncCompanyCapabilities::class);

    $action->execute($administrator, $company, $administrator, [Capability::View]);

    expect($administrator->hasCapability($company, Capability::ManagePermissions))->toBeFalse()
        ->and(fn () => $action->execute(
            $administrator,
            $company,
            $administrator,
            Capability::cases(),
        ))->toThrow(AuthorizationException::class);
});

it('rejects missing and cross-company permission authority', function () {
    $administrator = User::factory()->platformAdmin()->create();
    $manager = User::factory()->create();
    $beneficiary = User::factory()->create();
    $companyA = app(CreateCompany::class)->execute($administrator, [
        'name' => 'Azienda A',
        'timezone' => 'Europe/Rome',
    ]);
    $companyB = app(CreateCompany::class)->execute($administrator, [
        'name' => 'Azienda B',
        'timezone' => 'Europe/Rome',
    ]);
    CompanyCapability::query()->create([
        'company_id' => $companyA->id,
        'user_id' => $manager->id,
        'capability' => Capability::ManagePermissions,
    ]);

    expect(fn () => app(SyncCompanyCapabilities::class)->execute(
        $manager,
        $companyB,
        $beneficiary,
        [Capability::View],
    ))->toThrow(AuthorizationException::class);

    expect($beneficiary->capabilities()->count())->toBe(0);
});

it('rolls back assignment changes when audit persistence fails', function () {
    $administrator = User::factory()->platformAdmin()->create();
    $beneficiary = User::factory()->create();
    $company = app(CreateCompany::class)->execute($administrator, [
        'name' => 'Azienda',
        'timezone' => 'Europe/Rome',
    ]);
    $initialEventCount = AuditEvent::query()->count();
    AuditEvent::creating(function (): never {
        throw new RuntimeException('Forced audit failure');
    });

    expect(fn () => app(SyncCompanyCapabilities::class)->execute(
        $administrator,
        $company,
        $beneficiary,
        [Capability::View],
    ))->toThrow(RuntimeException::class, 'Forced audit failure');

    expect($beneficiary->capabilities()->count())->toBe(0)
        ->and(AuditEvent::query()->count())->toBe($initialEventCount);

    AuditEvent::flushEventListeners();
});
