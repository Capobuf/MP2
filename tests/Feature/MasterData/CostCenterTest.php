<?php

use App\Actions\MasterData\CreateCostCenter;
use App\Actions\MasterData\RenameCostCenter;
use App\Domain\Company\AuditEventType;
use App\Models\AuditEvent;
use App\Models\Company;
use App\Models\CostCenter;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Tests\Support\TestPermissions;

uses(RefreshDatabase::class);

function grantCostCenterManagement(User $user, Company $company): void
{
    grantTestPermissions([
        'company_id' => $company->id,
        'user' => $user,
        'permissions' => TestPermissions::MANAGE_MASTER_DATA,
    ]);
}

it('creates duplicate cost center denominations as distinct idempotent identities', function () {
    $actor = User::factory()->create();
    $company = Company::factory()->create();
    grantCostCenterManagement($actor, $company);
    $operationId = (string) Str::uuid();

    $first = app(CreateCostCenter::class)->execute($actor, $company, ['name' => ' Operations '], $operationId);
    $retried = app(CreateCostCenter::class)->execute($actor, $company, ['name' => 'Operations'], $operationId);
    $duplicate = app(CreateCostCenter::class)->execute($actor, $company, ['name' => 'Operations'], (string) Str::uuid());

    expect($first->id)->toBe($retried->id)
        ->and($first->id)->not->toBe($duplicate->id)
        ->and($first->name)->toBe('Operations')
        ->and(AuditEvent::query()->count())->toBe(2);

    $event = AuditEvent::query()->where('subject_id', $duplicate->id)->sole();

    expect($event->eventType())->toBe(AuditEventType::CostCenterCreated)
        ->and($event->company_id)->toBe($company->id)
        ->and($event->subject_type)->toBe(CostCenter::class)
        ->and($event->previous_value)->toBeNull()
        ->and($event->affected_exercise_ids)->toBe([])
        ->and($event->allocated_impact_by_exercise)->toBe([])
        ->and($event->actual_impact_by_exercise)->toBe([]);
});

it('renames a cost center while preserving identity and emits nothing for a no-op', function () {
    $actor = User::factory()->create();
    $company = Company::factory()->create();
    grantCostCenterManagement($actor, $company);
    $costCenter = CostCenter::factory()->for($company)->create(['name' => 'Prima']);
    $operationId = (string) Str::uuid();

    $renamed = app(RenameCostCenter::class)->execute($actor, $costCenter, ['name' => ' Dopo '], $operationId);
    $retried = app(RenameCostCenter::class)->execute($actor, $costCenter, ['name' => 'Dopo'], $operationId);
    app(RenameCostCenter::class)->execute($actor, $costCenter, ['name' => 'Dopo'], (string) Str::uuid());
    $event = AuditEvent::query()->sole();

    expect($renamed->id)->toBe($costCenter->id)
        ->and($retried->id)->toBe($costCenter->id)
        ->and($renamed->name)->toBe('Dopo')
        ->and($event->eventType())->toBe(AuditEventType::CostCenterRenamed)
        ->and($event->previous_value['name'])->toBe('Prima')
        ->and($event->new_value['name'])->toBe('Dopo')
        ->and(AuditEvent::query()->count())->toBe(1);
});

it('validates denominations and exact-company authorization', function () {
    $actor = User::factory()->create();
    $companyA = Company::factory()->create();
    $companyB = Company::factory()->create();
    grantCostCenterManagement($actor, $companyA);
    $costCenterB = CostCenter::factory()->for($companyB)->create();

    expect(fn () => app(CreateCostCenter::class)->execute($actor, $companyA, ['name' => ' '], (string) Str::uuid()))
        ->toThrow(ValidationException::class)
        ->and(fn () => app(RenameCostCenter::class)->execute($actor, $costCenterB, ['name' => 'Altro'], (string) Str::uuid()))
        ->toThrow(AuthorizationException::class);

    expect(AuditEvent::query()->count())->toBe(0);
});

it('rolls cost center persistence back when audit persistence fails', function () {
    $actor = User::factory()->create();
    $company = Company::factory()->create();
    grantCostCenterManagement($actor, $company);

    AuditEvent::creating(function (): never {
        throw new RuntimeException('audit unavailable');
    });

    expect(fn () => app(CreateCostCenter::class)->execute($actor, $company, ['name' => 'Operations'], (string) Str::uuid()))
        ->toThrow(RuntimeException::class, 'audit unavailable');

    expect(CostCenter::query()->count())->toBe(0)
        ->and(AuditEvent::query()->count())->toBe(0);
});
