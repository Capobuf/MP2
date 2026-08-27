<?php

use App\Actions\Proposals\ApproveProposal;
use App\Actions\Proposals\InitializeProposal;
use App\Actions\Proposals\PlanContract;
use App\Domain\Company\Capability;
use App\Domain\Proposals\ContractPlan;
use App\Domain\Proposals\ProposalActionType;
use App\Domain\Proposals\ProposalReadiness;
use App\Models\BudgetSourceRow;
use App\Models\Company;
use App\Models\CompanyCapability;
use App\Models\Contract;
use App\Models\ContractCondition;
use App\Models\ContractRenewalConfiguration;
use App\Models\CostCenter;
use App\Models\Exercise;
use App\Models\Expense;
use App\Models\ExpenseLine;
use App\Models\Proposal;
use App\Models\Supplier;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

uses(RefreshDatabase::class);

beforeEach(fn () => CarbonImmutable::setTestNow('2026-08-21 10:00:00 Europe/Rome'));
afterEach(fn () => CarbonImmutable::setTestNow());

it('creates and plans a contract without any live write', function (): void {
    $proposal = Proposal::factory()->create();
    $user = User::factory()->create();
    $supplier = Supplier::factory()->create(['company_id' => $proposal->company_id]);
    CompanyCapability::query()->create(['company_id' => $proposal->company_id, 'user_id' => $user->id, 'capability' => Capability::ManageProposals]);
    $created = app(PlanContract::class)->create($user, $proposal, ['title' => 'Contratto futuro', 'notes' => null, 'supplier_id' => $supplier->id, 'contractual_start_date' => '2026-01-01', 'next_expiry_date' => '2026-12-31', 'automatic_renewal' => false, 'renewal_duration_months' => null, 'notice_days' => 30, 'exercise_id' => $proposal->exercise_id, 'cost_center_id' => null], (string) Str::uuid(), 0);
    app(PlanContract::class)->execute($user, $proposal->refresh(), $created->item, ProposalActionType::AddContractCondition, ['cycle' => 'annual', 'attribution_mode' => 'cycle_start', 'amount' => '100.00', 'valid_from' => '2026-01-01', 'valid_to' => null, 'reason' => null], null, (string) Str::uuid(), 1);
    expect($created->item->refresh()->contract_id)->toBeNull()->and($created->item->result['planned_conditions'])->toHaveCount(1)->and(Contract::query()->count())->toBe(0);
});

it('inserts only planned renewal configurations during approval', function (): void {
    $company = Company::factory()->create();
    $exercise = Exercise::factory()->for($company)->create(['year' => 2026]);
    $user = User::factory()->create();
    foreach ([Capability::ManageProposals, Capability::ApproveBudget] as $capability) {
        CompanyCapability::query()->create(['company_id' => $company->id, 'user_id' => $user->id, 'capability' => $capability]);
    }
    $contract = Contract::factory()->for($company)->create([
        'contractual_start_date' => '2026-01-01',
        'next_expiry_date' => null,
        'renewal_anchor_date' => null,
        'automatic_renewal' => false,
        'renewal_duration_months' => null,
        'notice_days' => null,
    ]);
    ContractCondition::factory()->forContract($contract)->create(['valid_from' => '2026-01-01', 'valid_to' => null]);
    $configuration = ContractRenewalConfiguration::factory()->forContract($contract)->create([
        'effective_from' => '2026-01-01',
        'expiry_anchor_date' => null,
        'automatic_renewal' => false,
        'renewal_duration_months' => null,
        'notice_days' => null,
    ]);
    $proposal = app(InitializeProposal::class)->execute($user, $company, $exercise, (string) Str::uuid());
    $item = $proposal->items()->where('contract_id', $contract->id)->sole();
    app(PlanContract::class)->execute($user, $proposal, $item, ProposalActionType::SetContractRenewal, [
        'effective_from' => '2026-09-01',
        'expiry_anchor_date' => '2026-12-31',
        'automatic_renewal' => true,
        'renewal_duration_months' => 12,
        'notice_days' => 60,
    ], null, (string) Str::uuid(), 0);

    app(ApproveProposal::class)->execute($user, $proposal->refresh(), (string) Str::uuid());

    $configurations = ContractRenewalConfiguration::query()->where('contract_id', $contract->id)->orderBy('effective_from')->get();
    expect($configurations)->toHaveCount(2)
        ->and($configurations->first()->id)->toBe($configuration->id)
        ->and($configurations->last()->effectiveFrom()->toDateString())->toBe('2026-09-01');
});

it('derives and requires the canonical no-prorata Contract economic boundary', function (): void {
    $company = Company::factory()->create(['timezone' => 'Europe/Rome']);
    $exercise = Exercise::factory()->for($company)->create(['year' => 2026]);
    $user = User::factory()->create();
    foreach ([Capability::ManageProposals, Capability::ApproveBudget] as $capability) {
        CompanyCapability::query()->create(['company_id' => $company->id, 'user_id' => $user->id, 'capability' => $capability]);
    }
    $contract = Contract::factory()->for($company)->create(['contractual_start_date' => '2026-01-01', 'next_expiry_date' => null, 'renewal_anchor_date' => null]);
    $condition = ContractCondition::factory()->forContract($contract)->create(['cycle' => 'monthly', 'valid_from' => '2026-01-01', 'valid_to' => null]);
    $proposal = app(InitializeProposal::class)->execute($user, $company, $exercise, (string) Str::uuid());
    $item = $proposal->items()->where('contract_id', $contract->id)->sole();
    $payload = ['condition_id' => $condition->id, 'amount' => '120.00', 'cycle' => 'quarterly', 'attribution_mode' => 'cycle_end', 'requested_date' => '2026-08-22', 'confirmed_effective_date' => '2026-09-01', 'reason' => 'Nuovo accordo'];

    $action = app(PlanContract::class)->execute($user, $proposal, $item, ProposalActionType::ChangeContractEconomics, $payload, 'Nuovo accordo', (string) Str::uuid(), 0);

    expect($action->payload['minimum_date'])->toBe('2026-09-01')
        ->and($action->payload['effective_date'])->toBe('2026-09-01')
        ->and($action->payload['no_prorata'])->toBeTrue()
        ->and($item->refresh()->result['planned_condition_changes'][0]['cycle'])->toBe('quarterly');

    $invalid = [...$payload, 'confirmed_effective_date' => '2026-08-22'];
    expect(fn () => app(PlanContract::class)->execute($user, $proposal->refresh(), $item->refresh(), ProposalActionType::ChangeContractEconomics, $invalid, 'Nuovo accordo', (string) Str::uuid(), 1))->toThrow(ValidationException::class);
    expect(fn () => app(PlanContract::class)->execute($user, $proposal->refresh(), $item->refresh(), ProposalActionType::ChangeContractEconomics, $payload, 'Seconda sostituzione', (string) Str::uuid(), 1))->toThrow(ValidationException::class)
        ->and(fn () => app(PlanContract::class)->execute($user, $proposal->refresh(), $item->refresh(), ProposalActionType::AddContractCondition, ['cycle' => 'annual', 'attribution_mode' => 'cycle_start', 'amount' => '50.00', 'valid_from' => '2026-10-01', 'valid_to' => null, 'reason' => null], null, (string) Str::uuid(), 1))->toThrow(ValidationException::class);

    ContractPlan::validateForApproval($item->refresh()->load(['actions', 'contract', 'proposal.company']));
    $budget = app(ApproveProposal::class)->execute($user, $proposal->refresh(), (string) Str::uuid());
    $condition->refresh();
    $replacement = ContractCondition::query()->where('contract_id', $contract->id)->whereDate('valid_from', '2026-09-01')->sole();
    $row = BudgetSourceRow::query()->where('source_type', 'contract')->sole();

    expect($condition->validTo()?->toDateString())->toBe('2026-08-31')
        ->and($replacement->cycle)->toBe('quarterly')
        ->and($replacement->amount)->toBe('120.00')
        ->and($contract->refresh()->revision)->toBeGreaterThan(0)
        ->and($budget->proposal_id)->toBe($proposal->id)
        ->and($row->detail['contract'])->toHaveKeys(['conditions', 'annual_composition', 'cancellation_deadline', 'approved_lifecycle'])
        ->and($row->detail['contract']['approved_estimate_total'])->toBe($row->approved_estimates)
        ->and(collect($row->detail['contract']['conditions'])->pluck('condition_id')->all())->toContain($condition->id, $replacement->id);
});

it('rejects overlapping conditions and plans lifecycle renewal and annual classification canonically', function (): void {
    $company = Company::factory()->create();
    $exercise = Exercise::factory()->for($company)->create(['year' => 2026]);
    $user = User::factory()->create();
    CompanyCapability::query()->create(['company_id' => $company->id, 'user_id' => $user->id, 'capability' => Capability::ManageProposals]);
    $contract = Contract::factory()->for($company)->create(['contractual_start_date' => '2026-01-01', 'next_expiry_date' => '2026-12-31']);
    ContractCondition::factory()->forContract($contract)->create(['valid_from' => '2026-01-01', 'valid_to' => '2026-06-30']);
    $costCenter = CostCenter::factory()->for($company)->create();
    $proposal = app(InitializeProposal::class)->execute($user, $company, $exercise, (string) Str::uuid());
    $item = $proposal->items()->where('contract_id', $contract->id)->sole();

    expect(fn () => app(PlanContract::class)->execute($user, $proposal, $item, ProposalActionType::AddContractCondition, ['cycle' => 'monthly', 'attribution_mode' => 'cycle_start', 'amount' => '20.00', 'valid_from' => '2026-06-01', 'valid_to' => '2026-12-31', 'reason' => null], null, (string) Str::uuid(), 0))->toThrow(ValidationException::class);

    app(PlanContract::class)->execute($user, $proposal->refresh(), $item->refresh(), ProposalActionType::PlanContractLifecycle, ['type' => 'cessation', 'declared_contractual_date' => '2026-10-31', 'effective_date' => '2026-11-01', 'next_expiry_date' => null, 'reason' => 'Cessazione concordata'], 'Cessazione concordata', (string) Str::uuid(), 0);
    app(PlanContract::class)->execute($user, $proposal->refresh(), $item->refresh(), ProposalActionType::SetContractRenewal, ['effective_from' => '2026-09-01', 'expiry_anchor_date' => '2026-12-31', 'automatic_renewal' => true, 'renewal_duration_months' => 12, 'notice_days' => 60], null, (string) Str::uuid(), 1);
    app(PlanContract::class)->execute($user, $proposal->refresh(), $item->refresh(), ProposalActionType::SetContractCostCenter, ['exercise_id' => $exercise->id, 'cost_center_id' => $costCenter->id], null, (string) Str::uuid(), 2);

    expect($item->refresh()->result['planned_lifecycle'])->toHaveCount(1)
        ->and($item->result['renewal_duration_months'])->toBe(12)
        ->and($item->result['cost_center_id'])->toBe($costCenter->id)
        ->and($contract->refresh()->notice_days)->not->toBe(60);
});

it('rejects Contract annual reclassification when Actuals exist', function (): void {
    $company = Company::factory()->create();
    $exercise = Exercise::factory()->for($company)->create(['year' => 2026]);
    $user = User::factory()->create();
    CompanyCapability::query()->create(['company_id' => $company->id, 'user_id' => $user->id, 'capability' => Capability::ManageProposals]);
    $contract = Contract::factory()->for($company)->create();
    ContractCondition::factory()->forContract($contract)->create();
    $expense = Expense::factory()->forExercise($exercise)->for($contract)->create();
    ExpenseLine::factory()->for($expense)->create(['type' => 'actual', 'amount' => '1.00']);
    $proposal = app(InitializeProposal::class)->execute($user, $company, $exercise, (string) Str::uuid());
    $item = $proposal->items()->where('contract_id', $contract->id)->sole();
    expect(fn () => app(PlanContract::class)->execute($user, $proposal, $item, ProposalActionType::SetContractCostCenter, ['exercise_id' => $exercise->id, 'cost_center_id' => null], null, (string) Str::uuid(), 0))->toThrow(ValidationException::class);
});

it('blocks approval when the canonical Contract boundary changes after confirmation', function (): void {
    $company = Company::factory()->create(['timezone' => 'Europe/Rome']);
    $exercise = Exercise::factory()->for($company)->create(['year' => 2026]);
    $user = User::factory()->create();
    CompanyCapability::query()->create(['company_id' => $company->id, 'user_id' => $user->id, 'capability' => Capability::ManageProposals]);
    $contract = Contract::factory()->for($company)->create(['contractual_start_date' => '2026-01-01', 'next_expiry_date' => null, 'renewal_anchor_date' => null]);
    $condition = ContractCondition::factory()->forContract($contract)->create(['cycle' => 'monthly', 'valid_from' => '2026-01-01', 'valid_to' => null]);
    $proposal = app(InitializeProposal::class)->execute($user, $company, $exercise, (string) Str::uuid());
    $item = $proposal->items()->where('contract_id', $contract->id)->sole();
    app(PlanContract::class)->execute($user, $proposal, $item, ProposalActionType::ChangeContractEconomics, [
        'condition_id' => $condition->id, 'amount' => '120.00', 'cycle' => 'quarterly', 'attribution_mode' => 'cycle_end',
        'requested_date' => '2026-08-22', 'confirmed_effective_date' => '2026-09-01', 'reason' => 'Nuovo accordo',
    ], 'Nuovo accordo', (string) Str::uuid(), 0);

    CarbonImmutable::setTestNow('2026-09-15 10:00:00 Europe/Rome');
    $review = app(ProposalReadiness::class)->assessProposal($proposal->refresh());

    expect($review['ready'])->toBeFalse()
        ->and(collect($review['blocks'])->pluck('code')->all())->toContain('stale_concurrent_action');
});
