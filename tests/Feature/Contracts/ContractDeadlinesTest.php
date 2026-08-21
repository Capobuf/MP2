<?php

use App\Domain\Contracts\ContractDeadline;
use App\Models\Company;
use App\Models\Contract;
use App\Models\ContractCondition;
use App\Models\ContractExerciseClassification;
use App\Models\ContractLifecycleFact;
use App\Models\ContractRenewalConfiguration;
use App\Models\CostCenter;
use App\Models\Exercise;
use App\Models\Supplier;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('builds the canonical deadline row from persisted Contract facts', function () {
    $company = Company::factory()->create(['timezone' => 'Europe/Rome']);
    $supplier = Supplier::factory()->for($company)->create();
    $exercise = Exercise::factory()->for($company)->create(['year' => 2026]);
    $costCenter = CostCenter::factory()->for($company)->create();
    $contract = Contract::factory()->for($company)->for($supplier)->create([
        'contractual_start_date' => '2026-01-01',
        'next_expiry_date' => '2026-12-31',
        'renewal_anchor_date' => '2026-12-31',
        'automatic_renewal' => true,
        'renewal_duration_months' => 12,
        'notice_days' => 60,
    ]);
    ContractRenewalConfiguration::factory()->forContract($contract)->create([
        'effective_from' => '2026-01-01',
        'expiry_anchor_date' => '2026-12-31',
        'automatic_renewal' => true,
        'renewal_duration_months' => 12,
        'notice_days' => 60,
    ]);
    ContractLifecycleFact::factory()->forContract($contract)->create([
        'type' => 'activation', 'declared_contractual_date' => '2026-01-01', 'state_change_date' => '2026-01-01',
    ]);
    ContractLifecycleFact::factory()->forContract($contract)->create([
        'type' => 'cessation', 'declared_contractual_date' => '2026-11-30', 'state_change_date' => '2026-11-30', 'reason' => 'Cessazione pianificata',
    ]);
    ContractCondition::factory()->forContract($contract)->create(['valid_from' => '2026-01-01', 'valid_to' => '2026-12-31']);
    ContractExerciseClassification::factory()->forContractAndExercise($contract, $exercise)->create(['cost_center_id' => $costCenter->id]);

    $deadline = ContractDeadline::fromContract($contract, $exercise, CarbonImmutable::parse('2026-08-21', 'Europe/Rome'));

    expect($deadline->contractId)->toBe($contract->id)
        ->and($deadline->supplierId)->toBe($supplier->id)
        ->and($deadline->state->value)->toBe('active')
        ->and($deadline->nextExpiryDate)->toBe('2026-12-31')
        ->and($deadline->noticeLimitDate)->toBe('2026-11-01')
        ->and($deadline->plannedCessationDate)->toBe('2026-11-30')
        ->and($deadline->costCenterId)->toBe($costCenter->id)
        ->and($deadline->renewalWithoutCondition)->toBeTrue();
});

it('does not derive an expiry or reminder for an indefinite Contract', function () {
    $company = Company::factory()->create();
    $exercise = Exercise::factory()->for($company)->create(['year' => 2026]);
    $contract = Contract::factory()->for($company)->create([
        'contractual_start_date' => '2026-01-01',
        'next_expiry_date' => null,
        'renewal_anchor_date' => null,
        'notice_days' => 30,
    ]);

    $deadline = ContractDeadline::fromContract($contract, $exercise, CarbonImmutable::parse('2026-08-21'));

    expect($deadline->nextExpiryDate)->toBeNull()
        ->and($deadline->noticeLimitDate)->toBeNull()
        ->and($deadline->daysUntilExpiry)->toBeNull()
        ->and($deadline->renewalWithoutCondition)->toBeFalse();
});
