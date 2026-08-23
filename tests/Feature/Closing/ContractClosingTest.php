<?php

use App\Actions\Closing\CloseExercise;
use App\Actions\Closing\PrepareExerciseClosing;
use App\Domain\Company\Capability;
use App\Models\Company;
use App\Models\CompanyCapability;
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

uses(RefreshDatabase::class);

afterEach(fn () => CarbonImmutable::setTestNow());

it('materializes missed automatic renewals only through the Closing cutoff and recalculates only Open Exercises', function (): void {
    CarbonImmutable::setTestNow('2026-08-23 12:00:00 Europe/Rome');
    $company = Company::factory()->create();
    $actor = User::factory()->create();
    foreach ([Capability::View, Capability::CloseExercise] as $capability) {
        CompanyCapability::query()->create(['company_id' => $company->id, 'user_id' => $actor->id, 'capability' => $capability]);
    }

    $contract = Contract::factory()->for($company)->create([
        'contractual_start_date' => '2024-01-01',
        'next_expiry_date' => '2024-12-31',
        'renewal_anchor_date' => '2024-12-31',
        'automatic_renewal' => true,
        'renewal_duration_months' => 12,
    ]);
    ContractRenewalConfiguration::factory()->forContract($contract)->create([
        'effective_from' => '2024-01-01',
        'automatic_renewal' => true,
        'expiry_anchor_date' => '2024-12-31',
        'renewal_duration_months' => 12,
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
        ->pluck('renewed_expiry_date')
        ->map(fn (mixed $date): string => (string) $date)
        ->all();

    expect($renewedDates)->toBe(['2024-12-31', '2025-12-31'])
        ->and($contract->nextExpiryDate()?->toDateString())->toBe('2026-12-31')
        ->and(Expense::query()->where('contract_id', $contract->id)->where('exercise_id', $closed2024->id)->exists())->toBeFalse()
        ->and($target->refresh()->allocation())->toBe('120.00')
        ->and($future->refresh()->allocation())->toBe('120.00')
        ->and($snapshot->total_final_allocation)->toBe('120.00')
        ->and($snapshot->rows()->where('origin_key', $contract->originKey())->sole()->end_state)->toBe('active');
});
