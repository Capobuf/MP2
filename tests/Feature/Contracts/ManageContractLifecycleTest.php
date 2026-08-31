<?php

use App\Actions\Operations\AnnulContractLifecycleFact;
use App\Actions\Operations\CancelContract;
use App\Actions\Operations\CeaseContract;
use App\Actions\Operations\ReactivateContract;
use App\Actions\Operations\ReplaceContractLifecycleFact;
use App\Actions\Operations\UpdateContractRenewal;
use App\Models\AuditEvent;
use App\Models\BudgetSnapshot;
use App\Models\Company;
use App\Models\Contract;
use App\Models\ContractCondition;
use App\Models\ContractLifecycleFact;
use App\Models\ContractRenewalConfiguration;
use App\Models\Exercise;
use App\Models\Proposal;
use App\Models\ProposalItem;
use App\Models\Supplier;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Tests\Support\TestPermissions;

uses(RefreshDatabase::class);

beforeEach(function () {
    CarbonImmutable::setTestNow('2026-08-20 10:00:00 Europe/Rome');
});

afterEach(fn () => CarbonImmutable::setTestNow());

function lifecycleFixture(string $start = '2026-01-01'): array
{
    $actor = User::factory()->create();
    $company = Company::factory()->create(['timezone' => 'Europe/Rome']);
    foreach ([TestPermissions::VIEW, TestPermissions::MANAGE_OPERATIONS] as $capability) {
        grantTestPermissions(['company_id' => $company->id, 'user' => $actor, 'permissions' => $capability]);
    }
    $exercise = Exercise::factory()->for($company)->create(['year' => 2026, 'status' => 'open']);
    $supplier = Supplier::factory()->for($company)->create();
    $contract = Contract::factory()->for($company)->for($supplier)->create([
        'contractual_start_date' => $start,
        'next_expiry_date' => $start > '2026-12-31' ? '2027-12-31' : '2026-12-31',
        'renewal_anchor_date' => $start > '2026-12-31' ? '2027-12-31' : '2026-12-31',
    ]);
    ContractLifecycleFact::factory()->forContract($contract)->create([
        'type' => 'activation', 'declared_contractual_date' => $start, 'state_change_date' => $start, 'created_by_id' => $actor->id,
    ]);
    ContractCondition::factory()->forContract($contract)->create([
        'valid_from' => $start, 'valid_to' => null, 'amount' => '100.00', 'created_by_id' => $actor->id,
    ]);

    return compact('actor', 'company', 'exercise', 'contract');
}

it('cessates atomically on the following day closes open conditions and requires a note', function () {
    extract(lifecycleFixture());

    expect(fn () => app(CeaseContract::class)->execute($actor, $contract, '2026-09-30', '', $contract->revision, (string) Str::uuid()))
        ->toThrow(ValidationException::class);

    $operationId = (string) Str::uuid();
    $fact = app(CeaseContract::class)->execute($actor, $contract, '2026-09-30', 'Fine accordo', $contract->revision, $operationId);

    expect($fact->state_change_date->toDateString())->toBe('2026-10-01')
        ->and($contract->refresh()->stateAtDate('2026-09-30')->value)->toBe('active')
        ->and($contract->stateAtDate('2026-10-01')->value)->toBe('cessated')
        ->and(ContractCondition::query()->sole()->valid_to->toDateString())->toBe('2026-09-30')
        ->and(AuditEvent::query()->where('operation_id', $operationId)->pluck('event_sequence')->all())->toBe(range(0, AuditEvent::query()->where('operation_id', $operationId)->count() - 1));
});

it('reactivates only a terminal contract with a new condition and preserves the inactive interval', function () {
    extract(lifecycleFixture());
    app(CeaseContract::class)->execute($actor, $contract, '2026-06-30', 'Cessazione', $contract->revision, (string) Str::uuid());

    $reactivation = app(ReactivateContract::class)->execute($actor, $contract->refresh(), [
        'start_date' => '2026-09-01',
        'next_expiry_date' => '2027-08-31',
        'condition' => ['amount' => '150.00', 'cycle' => 'monthly', 'attribution_mode' => 'cycle_start', 'valid_from' => '2026-09-01', 'valid_to' => null],
        'reason' => 'Nuovo accordo',
        'expected_revision' => $contract->revision,
    ], (string) Str::uuid());

    expect($reactivation->type)->toBe('reactivation')
        ->and($contract->refresh()->stateAtDate('2026-08-31')->value)->toBe('cessated')
        ->and($contract->stateAtDate('2026-09-01')->value)->toBe('active')
        ->and($contract->conditions()->count())->toBe(2);
});

it('cancels only a never-active planned contract and annuls its future activation and conditions', function () {
    extract(lifecycleFixture('2027-01-01'));

    app(CancelContract::class)->execute($actor, $contract, 'Accordo non perfezionato', $contract->revision, (string) Str::uuid());

    expect($contract->refresh()->stateAtDate('2026-08-20')->value)->toBe('cancelled')
        ->and($contract->lifecycleFacts()->where('type', 'activation')->sole()->annulled_at)->not->toBeNull()
        ->and($contract->conditions()->sole()->annulled_at)->not->toBeNull();
});

it('annuls and replaces only future facts and rejects stale revisions without partial events', function () {
    extract(lifecycleFixture());
    $future = ContractLifecycleFact::factory()->forContract($contract)->create([
        'type' => 'cessation',
        'declared_contractual_date' => '2027-06-30',
        'state_change_date' => '2027-07-01',
        'reason' => 'Prima data',
        'created_by_id' => $actor->id,
    ]);

    expect(fn () => app(AnnulContractLifecycleFact::class)->execute($actor, $future, 'Cambio piano', $contract->revision + 1, (string) Str::uuid()))
        ->toThrow(ValidationException::class);

    $replacement = app(ReplaceContractLifecycleFact::class)->execute($actor, $future, [
        'type' => 'cessation',
        'declared_contractual_date' => '2027-12-31',
        'reason' => 'Nuova data',
        'replacement_reason' => 'Accordo aggiornato',
        'expected_revision' => $contract->revision,
    ], (string) Str::uuid());

    expect($future->refresh()->annulled_at)->not->toBeNull()
        ->and($replacement->declared_contractual_date->toDateString())->toBe('2027-12-31')
        ->and($replacement->state_change_date->toDateString())->toBe('2028-01-01');
});

it('appends a complete renewal configuration and keeps retry idempotent', function () {
    extract(lifecycleFixture());
    $operationId = (string) Str::uuid();
    $input = [
        'effective_from' => '2026-09-01',
        'automatic_renewal' => true,
        'expiry_anchor_date' => '2027-06-30',
        'renewal_duration_months' => 6,
        'notice_days' => 45,
        'impact_confirmed' => true,
        'expected_revision' => $contract->revision,
    ];

    $configuration = app(UpdateContractRenewal::class)->execute($actor, $contract, $input, $operationId);
    $retry = app(UpdateContractRenewal::class)->execute($actor, $contract, $input, $operationId);

    expect($retry->is($configuration))->toBeTrue()
        ->and(ContractRenewalConfiguration::query()->count())->toBe(1)
        ->and($contract->refresh()->next_expiry_date->toDateString())->toBe('2027-06-30')
        ->and($contract->renewal_duration_months)->toBe(6)
        ->and($contract->notice_days)->toBe(45);
});

it('requires renewal confirmation and a note after Budget and marks draft Proposals to realign', function () {
    extract(lifecycleFixture());
    $approvedProposal = Proposal::factory()->for($company)->for($exercise)->create([
        'status' => 'approved',
        'created_by_id' => $actor->id,
        'approved_by_id' => $actor->id,
        'approved_at' => now(),
    ]);
    BudgetSnapshot::factory()->for($approvedProposal)->create([
        'company_id' => $company->id,
        'exercise_id' => $exercise->id,
        'approved_by_id' => $actor->id,
    ]);
    $draft = Proposal::factory()->for($company)->for($exercise)->create(['created_by_id' => $actor->id]);
    $item = ProposalItem::factory()->for($draft)->create([
        'company_id' => $company->id,
        'source_type' => 'contract',
        'contract_id' => $contract->id,
        'baseline_revision' => $contract->revision,
        'baseline_fingerprint' => hash('sha256', 'contract-baseline'),
    ]);
    $input = [
        'effective_from' => '2026-09-01',
        'automatic_renewal' => true,
        'expiry_anchor_date' => '2027-06-30',
        'renewal_duration_months' => 6,
        'notice_days' => 45,
        'impact_confirmed' => true,
        'expected_revision' => $contract->revision,
    ];

    expect(fn () => app(UpdateContractRenewal::class)->execute(
        $actor,
        $contract,
        $input,
        (string) Str::uuid(),
    ))->toThrow(ValidationException::class);

    app(UpdateContractRenewal::class)->execute($actor, $contract, [
        ...$input,
        'reason' => 'Aggiornamento accordo di rinnovo',
    ], (string) Str::uuid());

    expect($item->refresh()->readiness_state->value)->toBe('to_realign')
        ->and(AuditEvent::query()->where('event_type', 'contract_renewal_changed')->sole()->reason)
        ->toBe('Aggiornamento accordo di rinnovo');
});
