<?php

use App\Actions\Operations\CreateContract;
use App\Actions\Operations\UpdateContract;
use App\Domain\Company\AuditEventType;
use App\Domain\Company\Capability;
use App\Models\AuditEvent;
use App\Models\Company;
use App\Models\CompanyCapability;
use App\Models\Contract;
use App\Models\ContractCondition;
use App\Models\ContractExerciseClassification;
use App\Models\ContractLifecycleFact;
use App\Models\ContractRenewalConfiguration;
use App\Models\Exercise;
use App\Models\Expense;
use App\Models\Supplier;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

uses(RefreshDatabase::class);

beforeEach(function () {
    CarbonImmutable::setTestNow('2026-08-20 10:00:00 Europe/Rome');
});

afterEach(function () {
    CarbonImmutable::setTestNow();
});

function grantContractOperations(User $user, Company $company): void
{
    foreach ([Capability::View, Capability::ManageOperations] as $capability) {
        CompanyCapability::query()->create([
            'company_id' => $company->id,
            'user_id' => $user->id,
            'capability' => $capability,
        ]);
    }
}

/** @return array<string, mixed> */
function validContractInput(Supplier $supplier, Exercise $exercise): array
{
    return [
        'title' => '  Manutenzione impianti  ',
        'notes' => '  Canone concordato  ',
        'supplier_id' => $supplier->id,
        'contractual_start_date' => '2026-01-31',
        'next_expiry_date' => '2027-01-31',
        'renewal_effective_from' => '2026-01-01',
        'automatic_renewal' => true,
        'renewal_duration_months' => 12,
        'notice_days' => 60,
        'condition' => [
            'amount' => '100.15',
            'cycle' => 'monthly',
            'attribution_mode' => 'cycle_start',
            'valid_from' => '2026-01-31',
            'valid_to' => null,
        ],
        'classifications' => [(string) $exercise->id => null],
    ];
}

it('creates the aggregate estimates and ordered timeline atomically and idempotently', function () {
    $actor = User::factory()->create();
    $company = Company::factory()->create(['timezone' => 'Europe/Rome']);
    grantContractOperations($actor, $company);
    $exercise = Exercise::factory()->for($company)->create(['year' => 2026]);
    $supplier = Supplier::factory()->for($company)->create();
    $operationId = (string) Str::uuid();

    $contract = app(CreateContract::class)->execute($actor, $company, validContractInput($supplier, $exercise), $operationId);
    $retry = app(CreateContract::class)->execute($actor, $company, validContractInput($supplier, $exercise), $operationId);
    $events = AuditEvent::query()->where('operation_id', $operationId)->orderBy('event_sequence')->get();

    expect($retry->is($contract))->toBeTrue()
        ->and($contract->originKey())->toBe('contract:'.$contract->id)
        ->and($contract->title)->toBe('Manutenzione impianti')
        ->and($contract->notes)->toBe('Canone concordato')
        ->and(ContractRenewalConfiguration::query()->sole()->effective_from->toDateString())->toBe('2026-01-01')
        ->and(ContractLifecycleFact::query()->where('type', 'activation')->count())->toBe(1)
        ->and(ContractCondition::query()->count())->toBe(1)
        ->and(ContractExerciseClassification::query()->count())->toBe(1)
        ->and(Expense::query()->where('origin', 'system')->count())->toBe(1)
        ->and(Expense::query()->sole()->allocation())->toBe('1201.80')
        ->and($events->pluck('event_sequence')->all())->toBe(range(0, $events->count() - 1))
        ->and($events->pluck('event_type')->map->value->all())->toContain(
            AuditEventType::ContractCreated->value,
            AuditEventType::ContractActivation->value,
            AuditEventType::ContractConditionCreated->value,
            AuditEventType::ContractEstimateRecalculated->value,
        );
});

it('rejects invalid or archived references without partial rows', function () {
    $actor = User::factory()->create();
    $company = Company::factory()->create();
    grantContractOperations($actor, $company);
    $exercise = Exercise::factory()->for($company)->create(['year' => 2026]);
    $archivedSupplier = Supplier::factory()->for($company)->archived()->create();
    $input = validContractInput($archivedSupplier, $exercise);
    $input['condition']['valid_from'] = '2025-12-31';

    expect(fn () => app(CreateContract::class)->execute($actor, $company, $input, (string) Str::uuid()))
        ->toThrow(ValidationException::class)
        ->and(Contract::query()->count())->toBe(0)
        ->and(ContractRenewalConfiguration::query()->count())->toBe(0)
        ->and(ContractLifecycleFact::query()->count())->toBe(0)
        ->and(ContractCondition::query()->count())->toBe(0)
        ->and(Expense::query()->count())->toBe(0)
        ->and(AuditEvent::query()->count())->toBe(0);
});

it('late-censuses real dates and every elapsed renewal but recalculates only open exercises', function () {
    $actor = User::factory()->create();
    $company = Company::factory()->create(['timezone' => 'Europe/Rome']);
    grantContractOperations($actor, $company);
    $closed = Exercise::factory()->for($company)->create(['year' => 2025, 'status' => 'closed']);
    $open = Exercise::factory()->for($company)->create(['year' => 2026, 'status' => 'open']);
    $supplier = Supplier::factory()->for($company)->create();
    $input = validContractInput($supplier, $open);
    $input['contractual_start_date'] = '2024-01-31';
    $input['next_expiry_date'] = '2024-07-31';
    $input['renewal_effective_from'] = '2024-01-01';
    $input['renewal_duration_months'] = 6;
    $input['condition']['valid_from'] = '2024-01-31';
    $operationId = (string) Str::uuid();

    $contract = app(CreateContract::class)->execute($actor, $company, $input, $operationId);

    expect($contract->contractual_start_date->toDateString())->toBe('2024-01-31')
        ->and($contract->next_expiry_date->isFuture())->toBeTrue()
        ->and(ContractLifecycleFact::query()->where('type', 'renewal')->pluck('renewed_expiry_date')->map->toDateString()->all())
        ->toBe(['2024-07-31', '2025-01-31', '2025-07-31', '2026-01-31', '2026-07-31'])
        ->and(Expense::query()->where('exercise_id', $closed->id)->exists())->toBeFalse()
        ->and(Expense::query()->where('exercise_id', $open->id)->exists())->toBeTrue()
        ->and(AuditEvent::query()->where('operation_id', $operationId)->where('event_type', AuditEventType::ContractRenewed)->count())->toBe(5)
        ->and(AuditEvent::query()->where('operation_id', $operationId)->where('event_type', AuditEventType::ContractCensused)->count())->toBe(1);
});

it('updates only descriptive fields with operation idempotency', function () {
    $actor = User::factory()->create();
    $company = Company::factory()->create();
    grantContractOperations($actor, $company);
    $supplier = Supplier::factory()->for($company)->create();
    $contract = Contract::factory()->for($company)->for($supplier)->create(['title' => 'Prima']);
    $operationId = (string) Str::uuid();

    app(UpdateContract::class)->execute($actor, $contract, ['title' => ' Dopo ', 'notes' => ' Nota ', 'supplier_id' => $supplier->id], $operationId);
    app(UpdateContract::class)->execute($actor, $contract, ['title' => 'Retry ignorato'], $operationId);

    expect($contract->refresh()->title)->toBe('Dopo')
        ->and($contract->notes)->toBe('Nota')
        ->and($contract->supplier_id)->toBe($supplier->id)
        ->and($contract->revision)->toBe(1)
        ->and(AuditEvent::query()->where('operation_id', $operationId)->count())->toBe(1);
});
