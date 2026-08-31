<?php

use App\Actions\Proposals\ApproveProposal;
use App\Actions\Proposals\InitializeProposal;
use App\Actions\Proposals\PlanContract;
use App\Domain\Company\AuditEventType;
use App\Domain\Proposals\ProposalActionType;
use App\Domain\Proposals\ProposalImpactPlan;
use App\Domain\Proposals\ProposalReadiness;
use App\Models\AuditEvent;
use App\Models\BudgetSnapshot;
use App\Models\Company;
use App\Models\Contract;
use App\Models\ContractCondition;
use App\Models\Exercise;
use App\Models\Expense;
use App\Models\ExpenseLine;
use App\Models\Project;
use App\Models\Proposal;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\Support\TestPermissions;

uses(RefreshDatabase::class);

beforeEach(fn () => CarbonImmutable::setTestNow('2026-08-21 10:00:00 Europe/Rome'));
afterEach(fn () => CarbonImmutable::setTestNow());

it('does not erase a Project allocation outside the Proposal main Exercise', function (): void {
    $company = Company::factory()->create(['timezone' => 'Europe/Rome']);
    $exercise2026 = Exercise::factory()->for($company)->create(['year' => 2026]);
    $exercise2028 = Exercise::factory()->for($company)->create(['year' => 2028]);
    $actor = User::factory()->create();
    grantTestPermissions(['company_id' => $company->id, 'user' => $actor, 'permissions' => TestPermissions::MANAGE_PROPOSALS]);
    $project = Project::factory()->for($company)->create(['initial_state' => 'open', 'initial_effective_date' => '2026-01-01']);
    $expense = Expense::factory()->forExercise($exercise2026)->for($project)->create();
    ExpenseLine::factory()->for($expense)->create(['type' => 'estimate', 'amount' => '25.00']);
    $proposal = app(InitializeProposal::class)->execute($actor, $company, $exercise2028, (string) Str::uuid());

    $impacts = ProposalImpactPlan::build($proposal);

    expect(array_column($impacts, 'year'))->toBe([2028])
        ->and($impacts[0]['allocation_before'])->toBe('0.00')
        ->and($impacts[0]['allocation_after'])->toBe('0.00');
});

it('enumerates exact multi-Exercise Contract impacts unchanged Budgets stale Drafts and closed divergences', function (): void {
    $company = Company::factory()->create(['timezone' => 'Europe/Rome']);
    $exercise2026 = Exercise::factory()->for($company)->create(['year' => 2026]);
    $exercise2027 = Exercise::factory()->for($company)->create(['year' => 2027]);
    $actor = User::factory()->create();
    foreach ([TestPermissions::MANAGE_PROPOSALS, TestPermissions::APPROVE_BUDGET] as $capability) {
        grantTestPermissions(['company_id' => $company->id, 'user' => $actor, 'permissions' => $capability]);
    }
    $contract = Contract::factory()->for($company)->create([
        'contractual_start_date' => '2026-01-01', 'next_expiry_date' => null, 'renewal_anchor_date' => null,
    ]);
    $condition = ContractCondition::factory()->forContract($contract)->create([
        'cycle' => 'monthly', 'amount' => '100.00', 'valid_from' => '2026-01-01', 'valid_to' => null,
    ]);

    $otherDraft = app(InitializeProposal::class)->execute($actor, $company, $exercise2027, (string) Str::uuid());
    $approvedProposal = Proposal::factory()->for($company)->for($exercise2027)->create([
        'status' => 'approved', 'approved_by_id' => $actor->id, 'approved_at' => now(), 'approval_operation_id' => (string) Str::uuid(),
    ]);
    $unchangedBudget = BudgetSnapshot::factory()->for($approvedProposal)->create([
        'company_id' => $company->id, 'exercise_id' => $exercise2027->id, 'approved_by_id' => $actor->id,
    ]);
    $proposal = app(InitializeProposal::class)->execute($actor, $company, $exercise2026, (string) Str::uuid());
    $item = $proposal->items()->where('contract_id', $contract->id)->sole();
    $action = app(PlanContract::class)->execute($actor, $proposal, $item, ProposalActionType::ChangeContractEconomics, [
        'condition_id' => $condition->id, 'amount' => '120.00', 'cycle' => 'quarterly', 'attribution_mode' => 'cycle_end',
        'requested_date' => '2026-08-22', 'confirmed_effective_date' => '2026-09-01', 'reason' => 'Nuovo accordo',
    ], 'Nuovo accordo', (string) Str::uuid(), 0);

    $impacts = ProposalImpactPlan::build($proposal->refresh());
    $impact2027 = collect($impacts)->firstWhere('exercise_id', $exercise2027->id);

    expect(array_column($impacts, 'year'))->toBe([2026, 2027])
        ->and($impact2027['allocation_before'])->toBe($action->payload['exercise_impacts'][(string) $exercise2027->id]['allocation_before'])
        ->and($impact2027['allocation_after'])->toBe($action->payload['exercise_impacts'][(string) $exercise2027->id]['allocation_after'])
        ->and($impact2027['unchanged_budgets'])->toContain(['budget_id' => $unchangedBudget->id, 'version' => 1])
        ->and($impact2027['stale_proposals'])->toContain(['proposal_id' => $otherDraft->id, 'exercise_id' => $exercise2027->id]);

    closeExerciseFixture($exercise2027, $actor);
    $review = app(ProposalReadiness::class)->assessProposal($proposal->refresh());

    expect($review['ready'])->toBeFalse()
        ->and(collect($review['impacts'])->firstWhere('exercise_id', $exercise2027->id)['historical_divergence'])->toBeTrue()
        ->and(collect($review['blocks'])->pluck('code')->all())->toContain('stale_concurrent_action')
        ->not->toContain('closed_exercise_action');
});

it('applies the approved Contract allocation to every affected open Exercise', function (): void {
    $company = Company::factory()->create(['timezone' => 'Europe/Rome']);
    $exercise2026 = Exercise::factory()->for($company)->create(['year' => 2026]);
    $exercise2027 = Exercise::factory()->for($company)->create(['year' => 2027]);
    $actor = User::factory()->create();
    foreach ([TestPermissions::MANAGE_PROPOSALS, TestPermissions::APPROVE_BUDGET] as $capability) {
        grantTestPermissions(['company_id' => $company->id, 'user' => $actor, 'permissions' => $capability]);
    }
    $contract = Contract::factory()->for($company)->create([
        'contractual_start_date' => '2026-01-01', 'next_expiry_date' => null, 'renewal_anchor_date' => null,
    ]);
    $condition = ContractCondition::factory()->forContract($contract)->create([
        'cycle' => 'monthly', 'amount' => '100.00', 'valid_from' => '2026-01-01', 'valid_to' => null,
    ]);
    $otherDraft = app(InitializeProposal::class)->execute($actor, $company, $exercise2027, (string) Str::uuid());
    $proposal = app(InitializeProposal::class)->execute($actor, $company, $exercise2026, (string) Str::uuid());
    $item = $proposal->items()->where('contract_id', $contract->id)->sole();
    $action = app(PlanContract::class)->execute($actor, $proposal, $item, ProposalActionType::ChangeContractEconomics, [
        'condition_id' => $condition->id, 'amount' => '120.00', 'cycle' => 'quarterly', 'attribution_mode' => 'cycle_end',
        'requested_date' => '2026-08-22', 'confirmed_effective_date' => '2026-09-01', 'reason' => 'Nuovo accordo',
    ], 'Nuovo accordo', (string) Str::uuid(), 0);

    $budget = app(ApproveProposal::class)->execute($actor, $proposal->refresh(), (string) Str::uuid());
    $allocations = Expense::query()->where('contract_id', $contract->id)->whereIn('exercise_id', [$exercise2026->id, $exercise2027->id])->with('lines')->get()->mapWithKeys(
        fn (Expense $expense): array => [$expense->exercise_id => $expense->allocation()],
    );

    expect($allocations[$exercise2026->id])->toBe($action->payload['exercise_impacts'][(string) $exercise2026->id]['allocation_after'])
        ->and($allocations[$exercise2027->id])->toBe($action->payload['exercise_impacts'][(string) $exercise2027->id]['allocation_after'])
        ->and(collect($budget->affected_exercises)->pluck('exercise_id')->all())->toBe([$exercise2026->id, $exercise2027->id])
        ->and($otherDraft->items()->where('contract_id', $contract->id)->sole()->readiness_state->value)->toBe('to_realign')
        ->and(AuditEvent::query()->where('operation_id', $budget->operation_id)->where('event_type', AuditEventType::ProposalMarkedToRealign)->where('subject_id', $otherDraft->id)->count())->toBe(1);
});
