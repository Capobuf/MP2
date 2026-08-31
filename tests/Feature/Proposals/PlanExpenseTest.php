<?php

use App\Actions\Proposals\InitializeProposal;
use App\Actions\Proposals\PlanExpense;
use App\Actions\Proposals\PlanProject;
use App\Domain\Proposals\ProposalActionType;
use App\Models\AuditEvent;
use App\Models\Company;
use App\Models\CostCenter;
use App\Models\Exercise;
use App\Models\Expense;
use App\Models\ExpenseLine;
use App\Models\Project;
use App\Models\Supplier;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Tests\Support\TestPermissions;

uses(RefreshDatabase::class);

it('plans expense estimates idempotently without touching live lines', function (): void {
    $company = Company::factory()->create();
    $exercise = Exercise::factory()->for($company)->create();
    $actor = User::factory()->create();
    grantTestPermissions(['company_id' => $company->id, 'user' => $actor, 'permissions' => TestPermissions::MANAGE_PROPOSALS]);
    $expense = Expense::factory()->forExercise($exercise)->create();
    $line = ExpenseLine::factory()->for($expense)->create(['type' => 'estimate', 'amount' => '5.00']);
    $proposal = app(InitializeProposal::class)->execute($actor, $company, $exercise, (string) Str::uuid());
    $item = $proposal->items->sole();
    $payload = ['estimate_lines' => [['proposal_line_id' => (string) Str::uuid(), 'line_id' => $line->id, 'amount' => '8.00', 'note' => null, 'annulled' => false]]];
    $operation = (string) Str::uuid();

    $action = app(PlanExpense::class)->execute($actor, $proposal, $item, ProposalActionType::SetExpenseEstimates, $payload, null, $operation, 0);
    $retry = app(PlanExpense::class)->execute($actor, $proposal->refresh(), $item->refresh(), ProposalActionType::SetExpenseEstimates, $payload, null, $operation, 1);
    $event = AuditEvent::query()->where('operation_id', $operation)->sole();

    expect($retry->is($action))->toBeTrue()->and($item->refresh()->result['estimate_lines'][0]['amount'])->toBe('8.00')->and($line->refresh()->amount)->toBe('5.00')
        ->and($event->previous_value['plan']['estimate_lines'][0]['amount'])->toBe('5.00')
        ->and($event->new_value['result']['estimate_lines'][0]['amount'])->toBe('8.00')
        ->and($event->allocated_impact_by_exercise[(string) $exercise->id])->toBe('3.00');
});

it('validates the complete no-Actual ownership supplier classification and lifecycle plan', function (): void {
    $company = Company::factory()->create();
    $exercise = Exercise::factory()->for($company)->create();
    $otherExercise = Exercise::factory()->for($company)->create();
    $actor = User::factory()->create();
    grantTestPermissions(['company_id' => $company->id, 'user' => $actor, 'permissions' => TestPermissions::MANAGE_PROPOSALS]);
    $expense = Expense::factory()->forExercise($exercise)->create();
    ExpenseLine::factory()->for($expense)->create(['type' => 'estimate', 'amount' => '5.00']);
    $project = Project::factory()->for($company)->create(['initial_state' => 'planned', 'initial_effective_date' => $exercise->year.'-01-01']);
    $supplier = Supplier::factory()->for($company)->create();
    $costCenter = CostCenter::factory()->for($company)->create();
    $proposal = app(InitializeProposal::class)->execute($actor, $company, $exercise, (string) Str::uuid());
    $item = $proposal->items()->where('expense_id', $expense->id)->sole();

    app(PlanExpense::class)->execute($actor, $proposal, $item, ProposalActionType::SetExpenseOwner, ['exercise_id' => $exercise->id, 'project_id' => $project->id], 'Verso progetto', (string) Str::uuid(), 0);
    app(PlanExpense::class)->execute($actor, $proposal->refresh(), $item->refresh(), ProposalActionType::SetExpenseSupplier, ['supplier_id' => $supplier->id], null, (string) Str::uuid(), 1);
    expect(fn () => app(PlanExpense::class)->execute($actor, $proposal->refresh(), $item->refresh(), ProposalActionType::SetExpenseCostCenter, ['cost_center_id' => $costCenter->id], null, (string) Str::uuid(), 2))->toThrow(ValidationException::class);

    app(PlanExpense::class)->execute($actor, $proposal->refresh(), $item->refresh(), ProposalActionType::SetExpenseOwner, ['exercise_id' => $exercise->id, 'project_id' => null, 'project_item_id' => null], 'Autonoma', (string) Str::uuid(), 2);
    app(PlanExpense::class)->execute($actor, $proposal->refresh(), $item->refresh(), ProposalActionType::SetExpenseCostCenter, ['cost_center_id' => $costCenter->id], null, (string) Str::uuid(), 3);
    app(PlanExpense::class)->execute($actor, $proposal->refresh(), $item->refresh(), ProposalActionType::SetExpenseOwner, ['exercise_id' => $otherExercise->id, 'project_id' => null, 'project_item_id' => null], 'Altro anno aperto', (string) Str::uuid(), 4);
    app(PlanExpense::class)->execute($actor, $proposal->refresh(), $item->refresh(), ProposalActionType::ReverseExpense, ['reason' => 'Non più prevista'], 'Non più prevista', (string) Str::uuid(), 5);
    app(PlanExpense::class)->execute($actor, $proposal->refresh(), $item->refresh(), ProposalActionType::RestoreExpense, ['reason' => 'Ripristinata'], 'Ripristinata', (string) Str::uuid(), 6);

    expect($item->refresh()->result['exercise_id'])->toBe($otherExercise->id)
        ->and($item->result['project_id'])->toBeNull()
        ->and($item->result['supplier_id'])->toBe($supplier->id)
        ->and($item->result['cost_center_id'])->toBe($costCenter->id)
        ->and($item->result['reversed'])->toBeFalse()
        ->and($expense->refresh()->exercise_id)->toBe($exercise->id);
});

it('represents Estimate annul restore zero and residual repositioning without touching Actuals', function (): void {
    $company = Company::factory()->create();
    $exercise = Exercise::factory()->for($company)->create();
    $actor = User::factory()->create();
    grantTestPermissions(['company_id' => $company->id, 'user' => $actor, 'permissions' => TestPermissions::MANAGE_PROPOSALS]);
    $expense = Expense::factory()->forExercise($exercise)->create();
    $estimate = ExpenseLine::factory()->for($expense)->create(['type' => 'estimate', 'amount' => '9.00']);
    $actual = ExpenseLine::factory()->for($expense)->create(['type' => 'actual', 'amount' => '4.00']);
    $proposal = app(InitializeProposal::class)->execute($actor, $company, $exercise, (string) Str::uuid());
    $item = $proposal->items()->where('expense_id', $expense->id)->sole();

    app(PlanExpense::class)->execute($actor, $proposal, $item, ProposalActionType::SetExpenseEstimates, ['estimate_lines' => [[
        'proposal_line_id' => (string) Str::uuid(), 'line_id' => $estimate->id, 'amount' => '0.00', 'note' => 'Residuo azzerato', 'annulled' => true,
    ]]], 'Riposizionamento piano', (string) Str::uuid(), 0);
    app(PlanExpense::class)->create($actor, $proposal->refresh(), [
        'description' => 'Piano residuo distinto', 'exercise_id' => $exercise->id, 'supplier_id' => null, 'cost_center_id' => null, 'project_id' => null, 'project_item_id' => null,
        'estimate_lines' => [['proposal_line_id' => (string) Str::uuid(), 'line_id' => null, 'amount' => '5.00', 'note' => null, 'annulled' => false]],
    ], 'Nessun matching con Effettivi', (string) Str::uuid(), 1);

    expect($proposal->items()->count())->toBe(2)
        ->and($estimate->refresh()->amount)->toBe('9.00')
        ->and($estimate->annulled_at)->toBeNull()
        ->and($actual->refresh()->amount)->toBe('4.00')
        ->and(Expense::query()->where('company_id', $company->id)->count())->toBe(1);
});

it('rejects moving or reversing an expense containing actuals', function (): void {
    $company = Company::factory()->create();
    $exercise = Exercise::factory()->for($company)->create();
    $actor = User::factory()->create();
    grantTestPermissions(['company_id' => $company->id, 'user' => $actor, 'permissions' => TestPermissions::MANAGE_PROPOSALS]);
    $expense = Expense::factory()->forExercise($exercise)->create();
    ExpenseLine::factory()->for($expense)->create(['type' => 'actual', 'amount' => '1.00']);
    $proposal = app(InitializeProposal::class)->execute($actor, $company, $exercise, (string) Str::uuid());
    expect(fn () => app(PlanExpense::class)->execute($actor, $proposal, $proposal->items->sole(), ProposalActionType::ReverseExpense, ['reason' => 'x'], 'x', (string) Str::uuid(), 0))->toThrow(ValidationException::class);
});

it('rejects an expense assigned to a same-Proposal Project not yet effective in its Exercise', function (): void {
    $company = Company::factory()->create();
    $exercise = Exercise::factory()->for($company)->create(['year' => 2026]);
    $actor = User::factory()->create();
    grantTestPermissions(['company_id' => $company->id, 'user' => $actor, 'permissions' => TestPermissions::MANAGE_PROPOSALS]);
    $proposal = app(InitializeProposal::class)->execute($actor, $company, $exercise, (string) Str::uuid());
    $projectAction = app(PlanProject::class)->create($actor, $proposal, [
        'title' => 'Progetto futuro', 'description' => null, 'notes' => null, 'initial_state' => 'planned',
        'initial_effective_date' => '2027-01-01', 'exercise_id' => $exercise->id, 'cost_center_id' => null,
    ], (string) Str::uuid(), 0);

    expect(fn () => app(PlanExpense::class)->create($actor, $proposal->refresh(), [
        'description' => 'Spesa anticipata', 'exercise_id' => $exercise->id, 'supplier_id' => null, 'cost_center_id' => null,
        'project_id' => null, 'project_item_id' => $projectAction->item->proposal_item_id,
        'estimate_lines' => [['proposal_line_id' => (string) Str::uuid(), 'line_id' => null, 'amount' => '1.00', 'note' => null, 'annulled' => false]],
    ], null, (string) Str::uuid(), 1))->toThrow(ValidationException::class);
});
