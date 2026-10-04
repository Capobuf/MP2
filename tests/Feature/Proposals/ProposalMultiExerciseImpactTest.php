<?php

use App\Actions\Proposals\ApproveProposal;
use App\Actions\Proposals\InitializeProposal;
use App\Actions\Proposals\PlanContract;
use App\Actions\Proposals\PlanExpense;
use App\Actions\Proposals\PlanProject;
use App\Actions\Reporting\BuildReport;
use App\Domain\Proposals\ProposalActionType;
use App\Domain\Proposals\ProposalImpactPlan;
use App\Domain\Reporting\ReportDefinition;
use App\Models\AuditEvent;
use App\Models\BudgetSnapshot;
use App\Models\BudgetSourceRow;
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
use Illuminate\Validation\ValidationException;
use Tests\Support\TestPermissions;

uses(RefreshDatabase::class);

beforeEach(fn () => CarbonImmutable::setTestNow('2026-08-21 10:00:00 Europe/Rome'));
afterEach(fn () => CarbonImmutable::setTestNow());

it('keeps a new out-year standalone Expense out of the main Budget while preserving its decision', function (): void {
    $company = Company::factory()->create();
    $exercise2026 = Exercise::factory()->for($company)->create(['year' => 2026]);
    $exercise2027 = Exercise::factory()->for($company)->create(['year' => 2027]);
    $actor = User::factory()->create();
    foreach ([TestPermissions::VIEW, TestPermissions::MANAGE_PROPOSALS, TestPermissions::APPROVE_BUDGET] as $capability) {
        grantTestPermissions(['company_id' => $company->id, 'user' => $actor, 'permissions' => $capability]);
    }
    $currentExpense = Expense::factory()->forExercise($exercise2026)->create(['description' => 'Costo 2026']);
    ExpenseLine::factory()->for($currentExpense)->create(['type' => 'estimate', 'amount' => '100.00']);
    $futureBudgetProposal = Proposal::factory()->for($company)->for($exercise2027)->create([
        'status' => 'approved', 'approved_by_id' => $actor->id, 'approved_at' => now(),
        'approval_operation_id' => (string) Str::uuid(),
    ]);
    $futureBudget = BudgetSnapshot::factory()->for($futureBudgetProposal)->create([
        'approved_by_id' => $actor->id, 'total_approved_allocation' => '40.00',
    ]);
    BudgetSourceRow::factory()->for($futureBudget, 'budget')->create([
        'company_id' => $company->id, 'approved_estimates' => '40.00', 'approved_allocation' => '40.00',
    ]);
    $storedFutureBudget = $futureBudget->fresh(['rows'])->toArray();
    $proposal = app(InitializeProposal::class)->execute($actor, $company, $exercise2026, (string) Str::uuid());
    $action = app(PlanExpense::class)->create($actor, $proposal, [
        'description' => 'Costo 2027', 'exercise_id' => $exercise2027->id, 'supplier_id' => null,
        'cost_center_id' => null, 'project_id' => null, 'project_item_id' => null,
        'estimate_lines' => [[
            'proposal_line_id' => (string) Str::uuid(), 'line_id' => null, 'amount' => '300.00',
            'quantity' => '3', 'unit_amount' => '100.00', 'unit_of_measure' => 'licenze',
            'note' => 'Decisione pluriennale', 'annulled' => false,
        ]],
    ], 'Pianificazione 2027', (string) Str::uuid(), 0);

    $impacts = collect(ProposalImpactPlan::build($proposal->fresh()))->keyBy('exercise_id');

    expect($impacts[$exercise2026->id]['allocation_before'])->toBe('100.00')
        ->and($impacts[$exercise2026->id]['allocation_after'])->toBe('100.00')
        ->and($impacts[$exercise2026->id]['allocation_delta'])->toBe('0.00')
        ->and($impacts[$exercise2027->id]['allocation_before'])->toBe('0.00')
        ->and($impacts[$exercise2027->id]['allocation_after'])->toBe('300.00')
        ->and($impacts[$exercise2027->id]['allocation_delta'])->toBe('300.00');

    $operationId = (string) Str::uuid();
    $budget = app(ApproveProposal::class)->execute($actor, $proposal->refresh(), $operationId);
    $retry = app(ApproveProposal::class)->execute($actor, $proposal->refresh(), $operationId);
    $row = $budget->rows()->where('proposal_item_id', $action->item->proposal_item_id)->sole();
    $liveExpense = Expense::query()->where('exercise_id', $exercise2027->id)->sole();
    $futureImpact = collect($budget->affected_exercises)->firstWhere('exercise_id', $exercise2027->id);
    $reportDefinition = ReportDefinition::fromArray([
        'company_id' => $company->id,
        'exercise_id' => $exercise2026->id,
        'kind' => 'suppliers',
        'final_reference' => [
            'type' => 'budget', 'exercise_id' => $exercise2026->id, 'budget_snapshot_id' => $budget->id,
        ],
    ]);
    $report = app(BuildReport::class)->execute($actor, $reportDefinition);
    $storedRow = $row->fresh()->toArray();

    expect($retry->is($budget))->toBeTrue()
        ->and($budget->total_approved_allocation)->toBe('100.00')
        ->and($row->approved_estimates)->toBe('0.00')
        ->and($row->approved_carryover)->toBe('0.00')
        ->and($row->approved_allocation)->toBe('0.00')
        ->and($row->proposal_item_id)->toBe($action->item->proposal_item_id)
        ->and($row->detail['expense']['exercise_id'])->toBe($exercise2027->id)
        ->and($row->detail['expense']['exercise_year'])->toBe(2027)
        ->and($row->detail['expense']['approved_estimate_total'])->toBe('0.00')
        ->and($row->detail['expense']['active_estimate_lines'])->toBe([])
        ->and($row->detail['approved_actions'][0]['payload']['exercise_id'])->toBe($exercise2027->id)
        ->and($row->detail['approved_actions'][0]['payload']['estimate_lines'][0]['amount'])->toBe('300.00')
        ->and($futureImpact['allocation_delta'])->toBe('300.00')
        ->and($liveExpense->allocation())->toBe('300.00')
        ->and($liveExpense->lines()->where('type', 'actual')->exists())->toBeFalse()
        ->and(Expense::query()->where('exercise_id', $exercise2027->id)->count())->toBe(1)
        ->and(BudgetSnapshot::query()->where('exercise_id', $exercise2026->id)->count())->toBe(1)
        ->and(BudgetSnapshot::query()->where('exercise_id', $exercise2027->id)->count())->toBe(1)
        ->and($futureBudget->fresh(['rows'])->toArray())->toBe($storedFutureBudget)
        ->and($report->header['budget_version'])->toBe(1)
        ->and($report->totals['allocation'])->toBe('100.00')
        ->and(collect($report->sources)->sum(fn ($source): float => (float) $source->allocation))->toBe(100.0)
        ->and(collect($report->sources)->firstWhere('originKey', $liveExpense->originKey())->allocation)->toBe('0.00');

    $liveExpense->lines()->where('type', 'estimate')->sole()->update(['amount' => '999.00']);
    $liveExpense->update(['description' => 'Costo 2027 modificato']);
    $reportAfterLiveChange = app(BuildReport::class)->execute($actor, $reportDefinition);

    expect($row->fresh()->toArray())->toBe($storedRow)
        ->and($reportAfterLiveChange->header['budget_version'])->toBe(1)
        ->and($reportAfterLiveChange->totals['allocation'])->toBe('100.00')
        ->and(collect($reportAfterLiveChange->sources)->firstWhere('originKey', $liveExpense->originKey())->allocation)->toBe('0.00')
        ->and(collect($reportAfterLiveChange->sources)->firstWhere('originKey', $liveExpense->originKey())->label)->toBe('Costo 2027');
});

it('keeps a new out-year child Expense linked to its new Project without adding it to the main Budget', function (): void {
    $company = Company::factory()->create();
    $exercise2026 = Exercise::factory()->for($company)->create(['year' => 2026]);
    $exercise2027 = Exercise::factory()->for($company)->create(['year' => 2027]);
    $actor = User::factory()->create();
    foreach ([TestPermissions::MANAGE_PROPOSALS, TestPermissions::APPROVE_BUDGET] as $capability) {
        grantTestPermissions(['company_id' => $company->id, 'user' => $actor, 'permissions' => $capability]);
    }
    $proposal = app(InitializeProposal::class)->execute($actor, $company, $exercise2026, (string) Str::uuid());
    $projectAction = app(PlanProject::class)->create($actor, $proposal, [
        'title' => 'Progetto 2027', 'description' => null, 'notes' => null, 'initial_state' => 'planned',
        'initial_effective_date' => '2027-01-01', 'exercise_id' => $exercise2027->id, 'cost_center_id' => null,
    ], (string) Str::uuid(), 0);
    $expenseAction = app(PlanExpense::class)->create($actor, $proposal->refresh(), [
        'description' => 'Figlia 2027', 'exercise_id' => $exercise2027->id, 'supplier_id' => null,
        'cost_center_id' => null, 'project_id' => null, 'project_item_id' => $projectAction->item->proposal_item_id,
        'estimate_lines' => [[
            'proposal_line_id' => (string) Str::uuid(), 'line_id' => null, 'amount' => '300.00',
            'note' => null, 'annulled' => false,
        ]],
    ], null, (string) Str::uuid(), 1);

    $budget = app(ApproveProposal::class)->execute($actor, $proposal->refresh(), (string) Str::uuid());
    $project = Project::query()->sole();
    $expense = Expense::query()->sole();
    $projectRow = $budget->rows()->where('proposal_item_id', $projectAction->item->proposal_item_id)->sole();
    $expenseRow = $budget->rows()->where('proposal_item_id', $expenseAction->item->proposal_item_id)->sole();

    expect($budget->total_approved_allocation)->toBe('0.00')
        ->and($expense->exercise_id)->toBe($exercise2027->id)
        ->and($expense->project_id)->toBe($project->id)
        ->and($expense->allocation())->toBe('300.00')
        ->and($projectRow->approved_allocation)->toBe('0.00')
        ->and($projectRow->detail['project']['expenses'])->toBe([])
        ->and($expenseRow->approved_allocation)->toBe('0.00')
        ->and($expenseRow->detail['expense']['owner']['origin_id'])->toBe($project->id)
        ->and($expenseRow->detail['expense']['exercise_year'])->toBe(2027)
        ->and($expenseRow->detail['expense']['active_estimate_lines'])->toBe([]);
});

it('moves an existing standalone Expense out of year without attributing it to the main Budget', function (): void {
    $company = Company::factory()->create();
    $exercise2026 = Exercise::factory()->for($company)->create(['year' => 2026]);
    $exercise2027 = Exercise::factory()->for($company)->create(['year' => 2027]);
    $actor = User::factory()->create();
    foreach ([TestPermissions::MANAGE_PROPOSALS, TestPermissions::APPROVE_BUDGET] as $capability) {
        grantTestPermissions(['company_id' => $company->id, 'user' => $actor, 'permissions' => $capability]);
    }
    $expense = Expense::factory()->forExercise($exercise2026)->create(['description' => 'Spesa da spostare']);
    $line = ExpenseLine::factory()->for($expense)->create(['type' => 'estimate', 'amount' => '300.00']);
    $proposal = app(InitializeProposal::class)->execute($actor, $company, $exercise2026, (string) Str::uuid());
    $item = $proposal->items()->where('expense_id', $expense->id)->sole();
    app(PlanExpense::class)->execute($actor, $proposal, $item, ProposalActionType::SetExpenseOwner, [
        'exercise_id' => $exercise2027->id, 'project_id' => null, 'project_item_id' => null,
    ], 'Spostamento al 2027', (string) Str::uuid(), 0);
    $impacts = collect(ProposalImpactPlan::build($proposal->fresh()))->keyBy('exercise_id');

    expect($impacts[$exercise2026->id]['allocation_delta'])->toBe('-300.00')
        ->and($impacts[$exercise2027->id]['allocation_delta'])->toBe('300.00');

    $budget = app(ApproveProposal::class)->execute($actor, $proposal->refresh(), (string) Str::uuid());
    $row = $budget->rows()->where('proposal_item_id', $item->proposal_item_id)->sole();

    expect($budget->total_approved_allocation)->toBe('0.00')
        ->and($expense->refresh()->exercise_id)->toBe($exercise2027->id)
        ->and($expense->id)->toBe($item->expense_id)
        ->and($line->refresh()->expense_id)->toBe($expense->id)
        ->and($line->amount)->toBe('300.00')
        ->and($row->approved_allocation)->toBe('0.00')
        ->and($row->detail['expense']['exercise_id'])->toBe($exercise2027->id)
        ->and($row->detail['expense']['exercise_year'])->toBe(2027)
        ->and($row->detail['expense']['approved_estimate_total'])->toBe('0.00')
        ->and($row->detail['expense']['active_estimate_lines'])->toBe([]);
});

it('rolls back a two-year Expense move when Budget row materialization fails', function (): void {
    $company = Company::factory()->create();
    $exercise2026 = Exercise::factory()->for($company)->create(['year' => 2026]);
    $exercise2027 = Exercise::factory()->for($company)->create(['year' => 2027]);
    $actor = User::factory()->create();
    foreach ([TestPermissions::MANAGE_PROPOSALS, TestPermissions::APPROVE_BUDGET] as $capability) {
        grantTestPermissions(['company_id' => $company->id, 'user' => $actor, 'permissions' => $capability]);
    }
    $expense = Expense::factory()->forExercise($exercise2026)->create();
    $line = ExpenseLine::factory()->for($expense)->create(['type' => 'estimate', 'amount' => '300.00']);
    $futureProposal = Proposal::factory()->for($company)->for($exercise2027)->create([
        'status' => 'approved', 'approved_by_id' => $actor->id, 'approved_at' => now(),
        'approval_operation_id' => (string) Str::uuid(),
    ]);
    $futureBudget = BudgetSnapshot::factory()->for($futureProposal)->create(['approved_by_id' => $actor->id]);
    $storedFutureBudget = $futureBudget->fresh()->getAttributes();
    $proposal = app(InitializeProposal::class)->execute($actor, $company, $exercise2026, (string) Str::uuid());
    $item = $proposal->items()->where('expense_id', $expense->id)->sole();
    app(PlanExpense::class)->execute($actor, $proposal, $item, ProposalActionType::SetExpenseOwner, [
        'exercise_id' => $exercise2027->id, 'project_id' => null, 'project_item_id' => null,
    ], 'Spostamento al 2027', (string) Str::uuid(), 0);
    $operationId = (string) Str::uuid();

    expect(fn () => app(ApproveProposal::class)->execute(
        $actor,
        $proposal->refresh(),
        $operationId,
        checkpoint: fn (string $stage) => $stage === 'after_budget_rows' ? throw new RuntimeException('failure') : null,
    ))->toThrow(RuntimeException::class)
        ->and($expense->refresh()->exercise_id)->toBe($exercise2026->id)
        ->and($expense->allocation())->toBe('300.00')
        ->and($line->refresh()->expense_id)->toBe($expense->id)
        ->and($proposal->fresh()->status->value)->toBe('draft')
        ->and(BudgetSnapshot::query()->where('exercise_id', $exercise2026->id)->exists())->toBeFalse()
        ->and($futureBudget->fresh()->getAttributes())->toBe($storedFutureBudget)
        ->and(AuditEvent::query()->where('operation_id', $operationId)->where('event_type', 'proposal_approval_failed')->exists())->toBeTrue();
});

it('rejects a Proposal that would rewrite Contract conditions in a Closed year', function (): void {
    $company = Company::factory()->create();
    $open2026 = Exercise::factory()->for($company)->create(['year' => 2026]);
    $closed2027 = Exercise::factory()->for($company)->create(['year' => 2027]);
    $open2028 = Exercise::factory()->for($company)->create(['year' => 2028]);
    $actor = User::factory()->create();
    foreach ([TestPermissions::MANAGE_PROPOSALS, TestPermissions::APPROVE_BUDGET] as $capability) {
        grantTestPermissions(['company_id' => $company->id, 'user' => $actor, 'permissions' => $capability]);
    }
    $contract = Contract::factory()->for($company)->create(['contractual_start_date' => '2026-01-01', 'next_expiry_date' => null, 'renewal_anchor_date' => null]);
    $condition = ContractCondition::factory()->forContract($contract)->create(['cycle' => 'monthly', 'amount' => '100.00', 'valid_from' => '2026-01-01', 'valid_to' => null]);
    $historicalExpense = Expense::factory()->forExercise($closed2027)->for($contract)->create(['origin' => 'system']);
    $historicalLine = ExpenseLine::factory()->for($historicalExpense)->create(['type' => 'estimate', 'amount' => '1200.00']);
    closeExerciseFixture($closed2027, $actor);
    $proposal = app(InitializeProposal::class)->execute($actor, $company, $open2026, (string) Str::uuid());
    $item = $proposal->items()->where('contract_id', $contract->id)->sole();
    app(PlanContract::class)->execute($actor, $proposal, $item, ProposalActionType::ChangeContractEconomics, [
        'condition_id' => $condition->id, 'amount' => '120.00', 'cycle' => 'quarterly', 'attribution_mode' => 'cycle_end',
        'requested_date' => '2026-08-24', 'confirmed_effective_date' => '2026-09-01', 'reason' => 'Nuovo accordo',
    ], 'Nuovo accordo', (string) Str::uuid(), 0);

    $impacts = ProposalImpactPlan::build($proposal->refresh());
    $closedImpact = collect($impacts)->firstWhere('exercise_id', $closed2027->id);

    expect($closedImpact['will_apply'])->toBeFalse()
        ->and($closedImpact['historical_divergence'])->toBeTrue()
        ->and($closedImpact['blocks'])->toBe([]);

    $operationId = (string) Str::uuid();
    expect(fn () => app(ApproveProposal::class)->execute($actor, $proposal->refresh(), $operationId))
        ->toThrow(ValidationException::class)
        ->and($historicalLine->fresh()->amount)->toBe('1200.00')
        ->and($closed2027->fresh()->revision)->toBe(0)
        ->and(Expense::query()->where('contract_id', $contract->id)->where('exercise_id', $open2028->id)->exists())->toBeFalse()
        ->and($condition->refresh()->validTo())->toBeNull()
        ->and($proposal->fresh()->status->value)->toBe('draft');
});

it('rolls back every open-year effect when multi-exercise application fails', function (): void {
    $company = Company::factory()->create();
    $exercise = Exercise::factory()->for($company)->create(['year' => 2026]);
    $actor = User::factory()->create();
    foreach ([TestPermissions::MANAGE_PROPOSALS, TestPermissions::APPROVE_BUDGET] as $capability) {
        grantTestPermissions(['company_id' => $company->id, 'user' => $actor, 'permissions' => $capability]);
    }
    $proposal = app(InitializeProposal::class)->execute($actor, $company, $exercise, (string) Str::uuid());

    expect(fn () => app(ApproveProposal::class)->execute($actor, $proposal, (string) Str::uuid(), [], [], fn (string $point) => $point === 'after_live_apply' ? throw new RuntimeException('failure') : null))
        ->toThrow(RuntimeException::class)
        ->and($proposal->fresh()->status->value)->toBe('draft');
});
