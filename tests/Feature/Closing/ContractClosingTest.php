<?php

use App\Actions\Closing\CloseExercise;
use App\Actions\Closing\PrepareExerciseClosing;
use App\Models\Company;
use App\Models\Contract;
use App\Models\ContractCondition;
use App\Models\ContractLifecycleFact;
use App\Models\ContractRenewalConfiguration;
use App\Models\Exercise;
use App\Models\Expense;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\Support\TestPermissions;

uses(RefreshDatabase::class);

afterEach(fn () => CarbonImmutable::setTestNow());

it('materializes missed automatic renewals only through the Closing cutoff and recalculates only Open Exercises', function (): void {
    CarbonImmutable::setTestNow('2026-08-23 12:00:00 Europe/Rome');
    $company = Company::factory()->create();
    $actor = User::factory()->create();
    foreach ([TestPermissions::VIEW, TestPermissions::CLOSE_EXERCISE] as $capability) {
        grantTestPermissions(['company_id' => $company->id, 'user' => $actor, 'permissions' => $capability]);
    }

    $contract = Contract::factory()->for($company)->create([
        'contractual_start_date' => '2024-01-01',
        'next_expiry_date' => '2024-12-31',
        'renewal_anchor_date' => '2024-12-31',
        'automatic_renewal' => true,
        'renewal_duration_months' => 12,
    ]);
    $closingConfiguration = ContractRenewalConfiguration::factory()->forContract($contract)->create([
        'effective_from' => '2024-01-01',
        'automatic_renewal' => true,
        'expiry_anchor_date' => '2024-12-31',
        'renewal_duration_months' => 12,
    ]);
    ContractRenewalConfiguration::factory()->forContract($contract)->create([
        'effective_from' => '2026-01-01',
        'automatic_renewal' => false,
        'expiry_anchor_date' => '2026-12-31',
        'renewal_duration_months' => null,
    ]);
    ContractCondition::factory()->forContract($contract)->create([
        'valid_from' => '2024-01-01',
        'valid_to' => null,
        'cycle' => 'monthly',
        'amount' => '10.00',
    ]);

    $closed2024 = Exercise::factory()->for($company)->create(['year' => 2024, 'status' => 'closed']);
    $target = Exercise::factory()->for($company)->create(['year' => 2025]);
    $future = Exercise::factory()->for($company)->create(['year' => 2026]);
    $prepared = app(PrepareExerciseClosing::class)->execute($actor, $target, ['projects' => []]);

    expect($prepared['review']->totals['final_allocation'])->toBe('120.00');

    $snapshot = app(CloseExercise::class)->execute($actor, $target, [
        ...$prepared['input'],
        'review_fingerprint' => $prepared['execution_fingerprint'],
        'warnings_acknowledged' => true,
        'confirmed' => true,
    ], (string) Str::uuid());

    $contract->refresh();
    $renewedDates = ContractLifecycleFact::query()
        ->where('contract_id', $contract->id)
        ->where('type', 'renewal')
        ->orderBy('renewed_expiry_date')
        ->get()
        ->map(fn (ContractLifecycleFact $fact): string => $fact->renewedExpiryDate()?->toDateString() ?? '')
        ->all();

    expect($contract->nextExpiryDate()?->toDateString())->toBe('2026-12-31')
        ->and($renewedDates)->toBe(['2024-12-31', '2025-12-31'])
        ->and(Expense::query()->where('contract_id', $contract->id)->where('exercise_id', $closed2024->id)->exists())->toBeFalse()
        ->and($target->refresh()->allocation())->toBe('120.00')
        ->and($future->refresh()->allocation())->toBe('120.00')
        ->and($snapshot->total_final_allocation)->toBe('120.00')
        ->and($snapshot->rows()->where('origin_key', $contract->originKey())->sole()->end_state)->toBe('active')
        ->and(data_get($snapshot->rows()->where('origin_key', $contract->originKey())->sole()->detail, 'renewal_configuration_at_31_december.id'))->toBe($closingConfiguration->id);
});

it('materializes a year-end expiry whose cessation changes state on the following day', function (): void {
    CarbonImmutable::setTestNow('2026-08-23 12:00:00 Europe/Rome');
    $company = Company::factory()->create();
    $actor = User::factory()->create();
    foreach ([TestPermissions::VIEW, TestPermissions::CLOSE_EXERCISE] as $capability) {
        grantTestPermissions(['company_id' => $company->id, 'user' => $actor, 'permissions' => $capability]);
    }
    $target = Exercise::factory()->for($company)->create(['year' => 2025]);
    Exercise::factory()->for($company)->create(['year' => 2026]);
    $contract = Contract::factory()->for($company)->create([
        'contractual_start_date' => '2025-01-01',
        'next_expiry_date' => '2025-12-31',
        'renewal_anchor_date' => '2025-12-31',
        'automatic_renewal' => false,
        'renewal_duration_months' => null,
    ]);
    ContractRenewalConfiguration::factory()->forContract($contract)->create([
        'effective_from' => '2025-01-01',
        'automatic_renewal' => false,
        'expiry_anchor_date' => '2025-12-31',
        'renewal_duration_months' => null,
    ]);
    ContractCondition::factory()->forContract($contract)->create([
        'valid_from' => '2025-01-01',
        'valid_to' => null,
        'cycle' => 'monthly',
        'amount' => '10.00',
    ]);

    $prepared = app(PrepareExerciseClosing::class)->execute($actor, $target, ['projects' => []]);
    $snapshot = app(CloseExercise::class)->execute($actor, $target, [
        ...$prepared['input'],
        'review_fingerprint' => $prepared['execution_fingerprint'],
        'warnings_acknowledged' => true,
        'confirmed' => true,
    ], (string) Str::uuid());
    $expiryFacts = ContractLifecycleFact::query()
        ->where('contract_id', $contract->id)
        ->where('type', 'expiry_cessation')
        ->get();
    $lifecycle = $snapshot->rows()
        ->where('origin_key', $contract->originKey())
        ->sole()
        ->detail['lifecycle_events'];

    expect($expiryFacts)->toHaveCount(1)
        ->and($lifecycle)->toHaveCount(1)
        ->and($lifecycle[0]['type'])->toBe('expiry_cessation')
        ->and($lifecycle[0]['declared_contractual_date'])->toBe('2025-12-31')
        ->and($lifecycle[0]['state_change_date'])->toBe('2026-01-01');
});

it('preserves overlapping annulled Contract conditions without using them in the annual composition', function (): void {
    CarbonImmutable::setTestNow('2026-08-23 12:00:00 Europe/Rome');
    $company = Company::factory()->create();
    $actor = User::factory()->create();
    foreach ([TestPermissions::VIEW, TestPermissions::CLOSE_EXERCISE] as $capability) {
        grantTestPermissions(['company_id' => $company->id, 'user' => $actor, 'permissions' => $capability]);
    }
    $target = Exercise::factory()->for($company)->create(['year' => 2025]);
    Exercise::factory()->for($company)->create(['year' => 2026]);
    $contract = Contract::factory()->for($company)->create([
        'contractual_start_date' => '2025-01-01',
        'next_expiry_date' => '2026-12-31',
        'renewal_anchor_date' => '2026-12-31',
    ]);
    ContractRenewalConfiguration::factory()->forContract($contract)->create([
        'effective_from' => '2025-01-01',
        'automatic_renewal' => true,
        'expiry_anchor_date' => '2026-12-31',
        'renewal_duration_months' => 12,
    ]);
    $active = ContractCondition::factory()->forContract($contract)->create([
        'valid_from' => '2025-01-01',
        'valid_to' => null,
        'cycle' => 'monthly',
        'amount' => '10.00',
    ]);
    $annulled = ContractCondition::factory()->forContract($contract)->create([
        'valid_from' => '2025-01-01',
        'valid_to' => '2025-12-31',
        'cycle' => 'annual',
        'amount' => '999.00',
        'reason' => 'Condizione annullata',
        'annulled_at' => '2025-06-01 10:00:00',
        'annulled_by_id' => $actor->id,
    ]);

    $prepared = app(PrepareExerciseClosing::class)->execute($actor, $target, ['projects' => []]);
    $snapshot = app(CloseExercise::class)->execute($actor, $target, [
        ...$prepared['input'],
        'review_fingerprint' => $prepared['execution_fingerprint'],
        'warnings_acknowledged' => true,
        'confirmed' => true,
    ], (string) Str::uuid());
    $detail = $snapshot->rows()->where('origin_key', $contract->originKey())->sole()->detail;

    expect(collect($detail['conditions'])->pluck('id')->all())->toBe([$active->id, $annulled->id])
        ->and(collect($detail['conditions'])->firstWhere('id', $annulled->id)['annulled'])->toBeTrue()
        ->and(collect($detail['annual_composition'])->pluck('condition_id')->unique()->all())->toBe([$active->id])
        ->and($snapshot->total_final_allocation)->toBe('120.00');
});
