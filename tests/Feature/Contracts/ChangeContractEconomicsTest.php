<?php

use App\Actions\Operations\ChangeContractCondition;
use App\Actions\Operations\CorrectContractCondition;
use App\Domain\Company\AuditEventType;
use App\Domain\Company\Capability;
use App\Models\AuditEvent;
use App\Models\Company;
use App\Models\CompanyCapability;
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

uses(RefreshDatabase::class);

beforeEach(fn () => CarbonImmutable::setTestNow('2026-08-21 10:00:00 Europe/Rome'));
afterEach(fn () => CarbonImmutable::setTestNow());

/** @return array{actor: User, company: Company, contract: Contract, condition: ContractCondition, exercises: array<int, Exercise>} */
function economicChangeFixture(array $years = [2026, 2027]): array
{
    $actor = User::factory()->create();
    $company = Company::factory()->create(['timezone' => 'Europe/Rome']);
    foreach ([Capability::View, Capability::ManageOperations] as $capability) {
        CompanyCapability::query()->create(['company_id' => $company->id, 'user_id' => $actor->id, 'capability' => $capability]);
    }
    $supplier = Supplier::factory()->for($company)->create();
    $exercises = [];
    foreach ($years as $year) {
        $exercises[$year] = Exercise::factory()->for($company)->create(['year' => $year, 'status' => 'open']);
    }
    $contract = Contract::factory()->for($company)->for($supplier)->create([
        'contractual_start_date' => '2026-01-31',
        'next_expiry_date' => null,
        'renewal_anchor_date' => null,
        'automatic_renewal' => false,
    ]);
    ContractLifecycleFact::factory()->forContract($contract)->create([
        'type' => 'activation', 'declared_contractual_date' => '2026-01-31', 'state_change_date' => '2026-01-31',
    ]);
    $condition = ContractCondition::factory()->forContract($contract)->create([
        'amount' => '100.00', 'cycle' => 'quarterly', 'attribution_mode' => 'cycle_start',
        'valid_from' => '2026-01-31', 'valid_to' => null,
    ]);

    return compact('actor', 'company', 'contract', 'condition', 'exercises');
}

it('previews and applies a real multi-exercise change at the confirmed boundary', function () {
    ['actor' => $actor, 'contract' => $contract, 'condition' => $condition, 'exercises' => $exercises] = economicChangeFixture();
    $input = ['requested_date' => '2026-08-21', 'amount' => '150.00', 'cycle' => 'monthly', 'attribution_mode' => 'cycle_start', 'reason' => 'Nuovo accordo'];
    $action = app(ChangeContractCondition::class);
    $plan = $action->preview($contract, $condition, $input);

    expect($plan->effectiveDate)->toBe('2026-10-31')
        ->and($plan->noProrata)->toBeTrue()
        ->and($plan->exerciseImpacts)->toHaveCount(2);

    $newCondition = $action->execute(
        $actor, $contract, $condition, $input, $plan->fingerprint(), $plan->effectiveDate, (string) Str::uuid(),
    );

    expect($condition->refresh()->validTo()?->toDateString())->toBe('2026-10-30')
        ->and($newCondition->validFrom()->toDateString())->toBe('2026-10-31')
        ->and($newCondition->amount)->toBe('150.00')
        ->and($contract->refresh()->revision)->toBe(1)
        ->and($exercises[2026]->refresh()->revision)->toBe(1)
        ->and($exercises[2027]->refresh()->revision)->toBe(1)
        ->and(AuditEvent::query()->where('event_type', AuditEventType::ContractConditionChanged)->count())->toBe(1)
        ->and(AuditEvent::query()->where('event_type', AuditEventType::ContractEstimateRecalculated)->count())->toBe(2);
});

it('rejects stale or unconfirmed plans with no partial economic effect', function () {
    ['actor' => $actor, 'contract' => $contract, 'condition' => $condition] = economicChangeFixture();
    $input = ['requested_date' => '2026-08-21', 'amount' => '150.00', 'cycle' => 'monthly', 'attribution_mode' => 'cycle_start'];
    $action = app(ChangeContractCondition::class);
    $plan = $action->preview($contract, $condition, $input);
    $contract->increment('revision');

    expect(fn () => $action->execute($actor, $contract, $condition, $input, $plan->fingerprint(), '2026-10-31', (string) Str::uuid()))
        ->toThrow(ValidationException::class)
        ->and($condition->refresh()->valid_to)->toBeNull()
        ->and(ContractCondition::query()->count())->toBe(1)
        ->and(Expense::query()->count())->toBe(0)
        ->and(AuditEvent::query()->count())->toBe(0);
});

it('corrects a material input error only with declarations reason and open affected exercises', function () {
    ['actor' => $actor, 'contract' => $contract, 'condition' => $condition] = economicChangeFixture();
    $input = [
        'amount' => '90.00', 'cycle' => 'quarterly', 'attribution_mode' => 'cycle_start',
        'reason' => 'Importo trascritto in modo errato', 'declared_input_error' => true, 'declared_no_new_agreement' => true,
    ];
    $action = app(CorrectContractCondition::class);
    $plan = $action->preview($contract, $condition, $input);
    $corrected = $action->execute($actor, $contract, $condition, $input, $plan->fingerprint(), (string) Str::uuid());

    expect($corrected->is($condition))->toBeTrue()
        ->and($condition->refresh()->amount)->toBe('90.00')
        ->and(AuditEvent::query()->where('event_type', AuditEventType::ContractConditionCorrected)->count())->toBe(1)
        ->and(AuditEvent::query()->where('event_type', AuditEventType::ContractEstimateRecalculated)->count())->toBe(2);
});

it('blocks material correction when an economically affected exercise is closed', function () {
    ['actor' => $actor, 'contract' => $contract, 'condition' => $condition] = economicChangeFixture([2026]);
    Exercise::factory()->for($contract->company)->create(['year' => 2027, 'status' => 'closed']);
    $input = [
        'amount' => '90.00', 'cycle' => 'quarterly', 'attribution_mode' => 'cycle_start',
        'reason' => 'Errore materiale', 'declared_input_error' => true, 'declared_no_new_agreement' => true,
    ];

    expect(fn () => app(CorrectContractCondition::class)->preview($contract, $condition, $input))
        ->toThrow(ValidationException::class)
        ->and($condition->refresh()->amount)->toBe('100.00');
});

it('rolls back condition and every exercise when recalculation fails', function () {
    ['actor' => $actor, 'contract' => $contract, 'condition' => $condition] = economicChangeFixture();
    $input = ['requested_date' => '2026-08-21', 'amount' => '150.00', 'cycle' => 'monthly', 'attribution_mode' => 'cycle_start'];
    $action = app(ChangeContractCondition::class);
    $plan = $action->preview($contract, $condition, $input);
    $fail = true;
    AuditEvent::creating(function (AuditEvent $event) use (&$fail): void {
        if ($fail && $event->eventType() === AuditEventType::ContractEstimateRecalculated) {
            $fail = false;
            throw new RuntimeException('forced rollback');
        }
    });

    expect(fn () => $action->execute($actor, $contract, $condition, $input, $plan->fingerprint(), $plan->effectiveDate, (string) Str::uuid()))
        ->toThrow(RuntimeException::class)
        ->and($condition->refresh()->valid_to)->toBeNull()
        ->and(ContractCondition::query()->count())->toBe(1)
        ->and(Expense::query()->count())->toBe(0)
        ->and(AuditEvent::query()->count())->toBe(0);
});
