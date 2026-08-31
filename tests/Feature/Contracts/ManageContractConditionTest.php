<?php

use App\Actions\Operations\CreateContractCondition;
use App\Actions\Operations\SetContractConditionAnnulled;
use App\Domain\Company\AuditEventType;
use App\Models\AuditEvent;
use App\Models\Company;
use App\Models\Contract;
use App\Models\ContractCondition;
use App\Models\ContractLifecycleFact;
use App\Models\Exercise;
use App\Models\Expense;
use App\Models\Supplier;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Tests\Support\TestPermissions;

uses(RefreshDatabase::class);

beforeEach(fn () => CarbonImmutable::setTestNow('2026-08-20 10:00:00 Europe/Rome'));
afterEach(fn () => CarbonImmutable::setTestNow());

function conditionFixture(): array
{
    $actor = User::factory()->create();
    $company = Company::factory()->create(['timezone' => 'Europe/Rome']);
    foreach ([TestPermissions::VIEW, TestPermissions::MANAGE_OPERATIONS] as $capability) {
        grantTestPermissions(['company_id' => $company->id, 'user' => $actor, 'permissions' => $capability]);
    }
    $exercise = Exercise::factory()->for($company)->create(['year' => 2026]);
    $supplier = Supplier::factory()->for($company)->create();
    $contract = Contract::factory()->for($company)->for($supplier)->create([
        'contractual_start_date' => '2026-01-01', 'next_expiry_date' => null, 'renewal_anchor_date' => null,
    ]);
    ContractLifecycleFact::factory()->forContract($contract)->create(['declared_contractual_date' => '2026-01-01', 'state_change_date' => '2026-01-01']);

    return compact('actor', 'company', 'exercise', 'contract');
}

it('creates non-overlapping conditions and recalculates stable estimates idempotently', function () {
    ['actor' => $actor, 'contract' => $contract] = conditionFixture();
    ContractCondition::factory()->forContract($contract)->create(['valid_from' => '2026-01-01', 'valid_to' => '2026-06-30']);
    $operationId = (string) Str::uuid();
    $input = ['amount' => '200.00', 'cycle' => 'monthly', 'attribution_mode' => 'cycle_start', 'valid_from' => '2026-07-01', 'valid_to' => null];

    $condition = app(CreateContractCondition::class)->execute($actor, $contract, $input, $operationId);
    $expense = Expense::query()->where('origin', 'system')->sole();
    $retry = app(CreateContractCondition::class)->execute($actor, $contract, $input, $operationId);

    expect($retry->is($condition))->toBeTrue()
        ->and($expense->refresh()->allocation())->toBe('1800.00')
        ->and(Expense::query()->count())->toBe(1)
        ->and(AuditEvent::query()->where('operation_id', $operationId)->orderBy('event_sequence')->pluck('event_type')->map->value->all())
        ->toContain(AuditEventType::ContractConditionCreated->value, AuditEventType::ContractEstimateRecalculated->value);
});

it('rejects inclusive overlap and invalid state without partial effects', function () {
    ['actor' => $actor, 'contract' => $contract] = conditionFixture();
    ContractCondition::factory()->forContract($contract)->create(['valid_from' => '2026-01-01', 'valid_to' => '2026-06-30']);

    expect(fn () => app(CreateContractCondition::class)->execute($actor, $contract, [
        'amount' => '1.00', 'cycle' => 'monthly', 'attribution_mode' => 'cycle_start', 'valid_from' => '2026-06-30', 'valid_to' => null,
    ], (string) Str::uuid()))->toThrow(ValidationException::class)
        ->and(ContractCondition::query()->count())->toBe(1)
        ->and(AuditEvent::query()->count())->toBe(0);
});

it('annuls and restores the same condition with history and overlap revalidation', function () {
    ['actor' => $actor, 'contract' => $contract] = conditionFixture();
    $condition = ContractCondition::factory()->forContract($contract)->create(['valid_from' => '2026-01-01']);

    app(SetContractConditionAnnulled::class)->execute($actor, $condition, true, 'Inserimento errato', (string) Str::uuid());
    expect($condition->refresh()->isAnnulled())->toBeTrue();

    app(SetContractConditionAnnulled::class)->execute($actor, $condition, false, 'Ripristino verificato', (string) Str::uuid());
    expect($condition->refresh()->isAnnulled())->toBeFalse()
        ->and(AuditEvent::query()->whereIn('event_type', [AuditEventType::ContractConditionAnnulled, AuditEventType::ContractConditionRestored])->count())->toBe(2);
});
