<?php

use App\Actions\Proposals\ApproveProposal;
use App\Actions\Proposals\InitializeProposal;
use App\Actions\Proposals\PlanProject;
use App\Domain\Proposals\ProposalActionType;
use App\Domain\Proposals\ProposalReadiness;
use App\Models\BudgetSourceRow;
use App\Models\Company;
use App\Models\CostCenter;
use App\Models\Exercise;
use App\Models\Expense;
use App\Models\ExpenseLine;
use App\Models\Project;
use App\Models\ProjectExerciseClassification;
use App\Models\Proposal;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Tests\Support\TestPermissions;

uses(RefreshDatabase::class);

it('creates a project only as a proposal item and records a typed transition', function (): void {
    $proposal = Proposal::factory()->create();
    $user = User::factory()->create();
    grantTestPermissions(['company_id' => $proposal->company_id, 'user' => $user, 'permissions' => TestPermissions::MANAGE_PROPOSALS]);
    $action = app(PlanProject::class)->create($user, $proposal, ['title' => 'Nuovo progetto', 'description' => null, 'notes' => null, 'initial_state' => 'planned', 'initial_effective_date' => '2026-01-01', 'exercise_id' => $proposal->exercise_id, 'cost_center_id' => null], (string) Str::uuid(), 0);
    $item = $action->item;
    app(PlanProject::class)->execute($user, $proposal->refresh(), $item, ProposalActionType::PlanProjectTransition, ['from_state' => 'planned', 'to_state' => 'open', 'effective_date' => '2026-02-01', 'reason' => null], null, (string) Str::uuid(), 1);
    expect($item->refresh()->project_id)->toBeNull()->and($item->result['planned_transitions'])->toHaveCount(1)->and(Project::query()->count())->toBe(0);
});

it('plans existing child Estimates and annual classification without live writes', function (): void {
    $company = Company::factory()->create();
    $exercise = Exercise::factory()->for($company)->create();
    $actor = User::factory()->create();
    foreach ([TestPermissions::MANAGE_PROPOSALS, TestPermissions::APPROVE_BUDGET] as $capability) {
        grantTestPermissions(['company_id' => $company->id, 'user' => $actor, 'permissions' => $capability]);
    }
    $project = Project::factory()->for($company)->create(['initial_state' => 'open', 'initial_effective_date' => $exercise->year.'-01-01']);
    $expense = Expense::factory()->forExercise($exercise)->for($project)->create();
    $estimate = ExpenseLine::factory()->for($expense)->create(['type' => 'estimate', 'amount' => '10.00']);
    $costCenter = CostCenter::factory()->for($company)->create();
    $proposal = app(InitializeProposal::class)->execute($actor, $company, $exercise, (string) Str::uuid());
    $item = $proposal->items()->where('project_id', $project->id)->sole();

    app(PlanProject::class)->execute($actor, $proposal, $item, ProposalActionType::PlanProjectChildExpenses, [
        'child_item_ids' => [],
        'existing_expenses' => [[
            'expense_id' => $expense->id,
            'estimate_lines' => [[
                'proposal_line_id' => (string) Str::uuid(), 'line_id' => $estimate->id, 'amount' => '12.00', 'note' => null, 'annulled' => false,
            ]],
        ]],
    ], null, (string) Str::uuid(), 0);
    app(PlanProject::class)->execute($actor, $proposal->refresh(), $item->refresh(), ProposalActionType::SetProjectCostCenter, [
        'exercise_id' => $exercise->id, 'cost_center_id' => $costCenter->id,
    ], null, (string) Str::uuid(), 1);

    expect(data_get($item->refresh()->result, 'expense_plan.0.estimate_lines.0.amount'))->toBe('12.00')
        ->and($item->result['cost_center_id'])->toBe($costCenter->id)
        ->and($estimate->refresh()->amount)->toBe('10.00');

    app(ApproveProposal::class)->execute($actor, $proposal->refresh(), (string) Str::uuid());
    $row = BudgetSourceRow::query()->where('source_type', 'project')->sole();

    expect($estimate->refresh()->amount)->toBe('12.00')
        ->and(ProjectExerciseClassification::query()->where('project_id', $project->id)->where('exercise_id', $exercise->id)->value('cost_center_id'))->toBe($costCenter->id)
        ->and($row->detail['project']['approved_estimate_total'])->toBe('12.00')
        ->and($row->detail['project']['expenses'][0]['active_estimate_lines'][0]['amount'])->toBe('12.00')
        ->and($row->approved_allocation)->toBe('12.00');
});

it('rejects a Project classification that would reclassify Actuals and a non-Planned new Project', function (): void {
    $company = Company::factory()->create();
    $exercise = Exercise::factory()->for($company)->create();
    $actor = User::factory()->create();
    grantTestPermissions(['company_id' => $company->id, 'user' => $actor, 'permissions' => TestPermissions::MANAGE_PROPOSALS]);
    $project = Project::factory()->for($company)->create(['initial_state' => 'open']);
    $expense = Expense::factory()->forExercise($exercise)->for($project)->create();
    ExpenseLine::factory()->for($expense)->create(['type' => 'actual', 'amount' => '1.00']);
    $proposal = app(InitializeProposal::class)->execute($actor, $company, $exercise, (string) Str::uuid());
    $item = $proposal->items()->where('project_id', $project->id)->sole();

    expect(fn () => app(PlanProject::class)->execute($actor, $proposal, $item, ProposalActionType::SetProjectCostCenter, ['exercise_id' => $exercise->id, 'cost_center_id' => null], null, (string) Str::uuid(), 0))->toThrow(ValidationException::class)
        ->and(fn () => app(PlanProject::class)->create($actor, $proposal->refresh(), ['title' => 'Non valido', 'initial_state' => 'open', 'initial_effective_date' => '2026-01-01', 'exercise_id' => $exercise->id, 'cost_center_id' => null], (string) Str::uuid(), 0))->toThrow(ValidationException::class);
});

it('keeps an unchanged classified Project with Actuals approvable', function (): void {
    $company = Company::factory()->create();
    $exercise = Exercise::factory()->for($company)->create();
    $actor = User::factory()->create();
    grantTestPermissions(['company_id' => $company->id, 'user' => $actor, 'permissions' => TestPermissions::MANAGE_PROPOSALS]);
    $project = Project::factory()->for($company)->create(['initial_state' => 'open']);
    $costCenter = CostCenter::factory()->for($company)->create();
    ProjectExerciseClassification::factory()->for($company)->for($project)->for($exercise)->for($costCenter)->create();
    $expense = Expense::factory()->forExercise($exercise)->for($project)->create();
    ExpenseLine::factory()->for($expense)->create(['type' => 'actual', 'amount' => '1.00']);
    $proposal = app(InitializeProposal::class)->execute($actor, $company, $exercise, (string) Str::uuid());

    $review = app(ProposalReadiness::class)->assessProposal($proposal);

    expect($review['ready'])->toBeTrue()
        ->and($review['blocks'])->toBe([]);
});
