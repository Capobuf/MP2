<?php

use App\Actions\Operations\CreateProjectTransition;
use App\Actions\Operations\UpdateProjectClassification;
use App\Actions\Proposals\ApproveProposal;
use App\Actions\Proposals\InitializeProposal;
use App\Actions\Proposals\PlanExpense;
use App\Actions\Proposals\PlanProject;
use App\Actions\Proposals\PlanProposalRelation;
use App\Actions\Proposals\RealignProposalItem;
use App\Actions\Proposals\ReviewProposalReadiness;
use App\Domain\Company\AuditEventType;
use App\Domain\Proposals\ProposalActionType;
use App\Domain\Proposals\ProposalReadinessState;
use App\Domain\Proposals\ProposalRealignmentChoice;
use App\Models\AuditEvent;
use App\Models\BudgetSnapshot;
use App\Models\BudgetSourceRow;
use App\Models\Company;
use App\Models\Contract;
use App\Models\CostCenter;
use App\Models\Exercise;
use App\Models\Expense;
use App\Models\ExpenseLine;
use App\Models\Project;
use App\Models\ProjectContractLink;
use App\Models\ProjectExerciseClassification;
use App\Models\Supplier;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Tests\Support\TestPermissions;

uses(RefreshDatabase::class);

function staleExpenseProposal(): array
{
    $company = Company::factory()->create();
    $exercise = Exercise::factory()->for($company)->create();
    $actor = User::factory()->create();
    grantTestPermissions(['company_id' => $company->id, 'user' => $actor, 'permissions' => TestPermissions::MANAGE_PROPOSALS]);
    $expense = Expense::factory()->forExercise($exercise)->create();
    $line = ExpenseLine::factory()->for($expense)->create(['type' => 'estimate', 'amount' => '5.00']);
    $proposal = app(InitializeProposal::class)->execute($actor, $company, $exercise, (string) Str::uuid());
    $item = $proposal->items->sole();
    $action = app(PlanExpense::class)->execute($actor, $proposal, $item, ProposalActionType::SetExpenseEstimates, ['estimate_lines' => [[
        'proposal_line_id' => (string) Str::uuid(), 'line_id' => $line->id, 'amount' => '8.00', 'note' => null, 'annulled' => false,
    ]]], null, (string) Str::uuid(), 0);
    $line->update(['amount' => '6.00']);
    $proposal = app(ReviewProposalReadiness::class)->execute($actor, $proposal->refresh(), (string) Str::uuid());

    return [$actor, $proposal, $item->fresh(), $action->fresh(), $line];
}

function projectChildProposal(): array
{
    $company = Company::factory()->create();
    $exercise = Exercise::factory()->for($company)->create(['year' => 2026]);
    $actor = User::factory()->create();
    foreach ([TestPermissions::MANAGE_PROPOSALS, TestPermissions::MANAGE_OPERATIONS, TestPermissions::APPROVE_BUDGET] as $capability) {
        grantTestPermissions(['company_id' => $company->id, 'user' => $actor, 'permissions' => $capability]);
    }
    $project = Project::factory()->for($company)->create([
        'initial_state' => 'open',
        'initial_effective_date' => '2025-01-01',
    ]);
    $sourceCostCenter = CostCenter::factory()->for($company)->create();
    $plannedCostCenter = CostCenter::factory()->for($company)->create();
    $liveCostCenter = CostCenter::factory()->for($company)->create();
    ProjectExerciseClassification::factory()->forProjectAndExercise($project, $exercise)->create([
        'cost_center_id' => $sourceCostCenter->id,
    ]);
    $proposal = app(InitializeProposal::class)->execute($actor, $company, $exercise, (string) Str::uuid());
    $projectItem = $proposal->items()->where('project_id', $project->id)->sole();
    $projectAction = app(PlanProject::class)->execute($actor, $proposal, $projectItem, ProposalActionType::SetProjectCostCenter, [
        'exercise_id' => $exercise->id,
        'cost_center_id' => $plannedCostCenter->id,
    ], null, (string) Str::uuid(), 0);
    $projectTransitionAction = app(PlanProject::class)->execute($actor, $proposal->refresh(), $projectItem->fresh(), ProposalActionType::PlanProjectTransition, [
        'from_state' => 'open',
        'to_state' => 'closed',
        'effective_date' => '2026-12-31',
        'reason' => 'Chiusura pianificata',
    ], null, (string) Str::uuid(), 1);
    $expenseAction = app(PlanExpense::class)->create($actor, $proposal->refresh(), [
        'description' => 'Spesa figlia da riallineamento',
        'notes' => null,
        'exercise_id' => $exercise->id,
        'supplier_id' => null,
        'cost_center_id' => null,
        'project_id' => null,
        'project_item_id' => (string) $projectItem->proposal_item_id,
        'estimate_lines' => [[
            'proposal_line_id' => (string) Str::uuid(),
            'line_id' => null,
            'amount' => '300.00',
            'note' => null,
            'annulled' => false,
        ]],
    ], null, (string) Str::uuid(), 2);

    return compact(
        'actor',
        'company',
        'exercise',
        'project',
        'proposal',
        'projectItem',
        'projectAction',
        'projectTransitionAction',
        'expenseAction',
        'sourceCostCenter',
        'plannedCostCenter',
        'liveCostCenter',
    );
}

function staleProjectChildProposal(): array
{
    $fixture = projectChildProposal();
    $preview = app(UpdateProjectClassification::class)->preview(
        $fixture['actor'],
        $fixture['project'],
        $fixture['exercise'],
        $fixture['liveCostCenter']->id,
    );
    app(UpdateProjectClassification::class)->confirm(
        $fixture['actor'],
        $fixture['project'],
        $preview,
        (string) Str::uuid(),
    );
    $fixture['proposal'] = app(ReviewProposalReadiness::class)->execute(
        $fixture['actor'],
        $fixture['proposal']->refresh(),
        (string) Str::uuid(),
    );
    $fixture['projectItem'] = $fixture['projectItem']->fresh();

    expect($fixture['projectItem']->readiness_state)->toBe(ProposalReadinessState::ToRealign);

    return $fixture;
}

it('reloads current reality and withdraws every touching decision idempotently', function (): void {
    [$actor, $proposal, $item, $action, $line] = staleExpenseProposal();
    $operationId = (string) Str::uuid();

    $aligned = app(RealignProposalItem::class)->execute($actor, $proposal, $item, ProposalRealignmentChoice::Reload, null, [], $operationId, $proposal->revision);
    $retry = app(RealignProposalItem::class)->execute($actor, $proposal->fresh(), $item->fresh(), ProposalRealignmentChoice::Reload, null, [], $operationId, $proposal->revision + 1);

    expect($retry->is($aligned))->toBeTrue()
        ->and(data_get($aligned->result, 'estimate_lines.0.amount'))->toBe('6.00')
        ->and($aligned->readiness_state->value)->toBe('aligned')
        ->and($action->fresh()->status->value)->toBe('withdrawn')
        ->and($line->fresh()->amount)->toBe('6.00')
        ->and(AuditEvent::query()->where('operation_id', $operationId)->where('event_sequence', 0)->sole()->eventType())->toBe(AuditEventType::ProposalRealityReloaded);
});

it('keeps and replays the complete proposal decision only with a reason', function (): void {
    [$actor, $proposal, $item, $action] = staleExpenseProposal();

    expect(fn () => app(RealignProposalItem::class)->execute($actor, $proposal, $item, ProposalRealignmentChoice::Keep, null, [], (string) Str::uuid(), $proposal->revision))
        ->toThrow(ValidationException::class);

    $aligned = app(RealignProposalItem::class)->execute($actor, $proposal->fresh(), $item->fresh(), ProposalRealignmentChoice::Keep, 'Confermo il piano', [], (string) Str::uuid(), $proposal->revision);

    expect(data_get($aligned->result, 'estimate_lines.0.amount'))->toBe('8.00')
        ->and(data_get($aligned->baseline, 'plan_baseline.estimate_lines.0.amount'))->toBe('6.00')
        ->and($action->fresh()->status->value)->toBe('active');
});

it('manually retains only selected touching decisions without rewriting either payload', function (): void {
    [$actor, $proposal, $item, $estimateAction] = staleExpenseProposal();
    $supplier = Supplier::factory()->for($proposal->company)->create();
    $supplierAction = app(PlanExpense::class)->execute($actor, $proposal->refresh(), $item->fresh(), ProposalActionType::SetExpenseSupplier, ['supplier_id' => $supplier->id], null, (string) Str::uuid(), $proposal->revision);
    $proposal = app(ReviewProposalReadiness::class)->execute($actor, $proposal->refresh(), (string) Str::uuid());

    $aligned = app(RealignProposalItem::class)->execute($actor, $proposal, $item->fresh(), ProposalRealignmentChoice::Manual, null, [$supplierAction->id], (string) Str::uuid(), $proposal->revision);

    expect(data_get($aligned->result, 'estimate_lines.0.amount'))->toBe('6.00')
        ->and($aligned->result['supplier_id'])->toBe($supplier->id)
        ->and($estimateAction->fresh()->status->value)->toBe('withdrawn')
        ->and($supplierAction->fresh()->status->value)->toBe('active')
        ->and(data_get($estimateAction->payload, 'estimate_lines.0.amount'))->toBe('8.00');
});

it('rolls back invalid replay stale confirmation and injected persistence failure', function (): void {
    [$actor, $proposal, $item, $action] = staleExpenseProposal();
    $before = $item->baseline_fingerprint;

    expect(fn () => app(RealignProposalItem::class)->execute($actor, $proposal, $item, ProposalRealignmentChoice::Keep, 'Piano', [], (string) Str::uuid(), $proposal->revision - 1))
        ->toThrow(ValidationException::class)
        ->and(fn () => app(RealignProposalItem::class)->execute($actor, $proposal->fresh(), $item->fresh(), ProposalRealignmentChoice::Reload, null, [], (string) Str::uuid(), $proposal->revision, fn (): never => throw new RuntimeException('failure')))
        ->toThrow(RuntimeException::class)
        ->and($item->fresh()->baseline_fingerprint)->toBe($before)
        ->and($action->fresh()->status->value)->toBe('active');
});

it('realigns only Project-owned decisions and preserves its planned child expense', function (string $choice): void {
    $fixture = staleProjectChildProposal();
    $expenseItem = $fixture['expenseAction']->item;
    $expenseItemId = (string) $expenseItem->proposal_item_id;
    $expensePayload = $fixture['expenseAction']->payload;
    $operationId = (string) Str::uuid();
    $realignmentChoice = ProposalRealignmentChoice::from($choice);
    $retained = $realignmentChoice === ProposalRealignmentChoice::Manual
        ? [$fixture['projectAction']->id]
        : [];

    $aligned = app(RealignProposalItem::class)->execute(
        $fixture['actor'],
        $fixture['proposal'],
        $fixture['projectItem'],
        $realignmentChoice,
        $realignmentChoice === ProposalRealignmentChoice::Keep ? 'Confermo la riclassificazione proposta' : null,
        $retained,
        $operationId,
        $fixture['proposal']->revision,
    );

    $keepsProjectAction = $realignmentChoice !== ProposalRealignmentChoice::Reload;
    $keepsTransitionAction = $realignmentChoice === ProposalRealignmentChoice::Keep;
    $event = AuditEvent::query()->where('operation_id', $operationId)->where('event_sequence', 0)->sole();
    $resultCostCenterId = $aligned->result['cost_center_id']
        ?? data_get($aligned->result, 'classification.0.cost_center_id');

    expect($aligned->readiness_state)->toBe(ProposalReadinessState::Aligned)
        ->and($resultCostCenterId)->toBe($keepsProjectAction ? $fixture['plannedCostCenter']->id : $fixture['liveCostCenter']->id)
        ->and(data_get($aligned->baseline, 'plan_baseline.classification.0.cost_center_id'))->toBe($fixture['liveCostCenter']->id)
        ->and($fixture['projectAction']->fresh()->status->value)->toBe($keepsProjectAction ? 'active' : 'withdrawn')
        ->and($fixture['projectTransitionAction']->fresh()->status->value)->toBe($keepsTransitionAction ? 'active' : 'withdrawn')
        ->and($fixture['expenseAction']->fresh()->status->value)->toBe('active')
        ->and($fixture['expenseAction']->proposal_item_id)->toBe($expenseItem->id)
        ->and($fixture['expenseAction']->payload)->toBe($expensePayload)
        ->and((string) $expenseItem->fresh()->proposal_item_id)->toBe($expenseItemId)
        ->and(data_get($expenseItem->result, 'estimate_lines.0.amount'))->toBe('300.00')
        ->and($event->new_value['withdrawn_action_ids'])->not->toContain($fixture['expenseAction']->id)
        ->and(ProjectExerciseClassification::query()->where('project_id', $fixture['project']->id)->where('exercise_id', $fixture['exercise']->id)->value('cost_center_id'))->toBe($fixture['liveCostCenter']->id)
        ->and(Expense::query()->where('company_id', $fixture['company']->id)->count())->toBe(0)
        ->and(ExpenseLine::query()->count())->toBe(0);
})->with([
    'Mantieni proposta' => ProposalRealignmentChoice::Keep->value,
    'Ricarica realtà' => ProposalRealignmentChoice::Reload->value,
    'Rivedi manualmente' => ProposalRealignmentChoice::Manual->value,
]);

it('rejects a child action as a manual Project decision without partial changes', function (): void {
    $fixture = staleProjectChildProposal();
    $operationId = (string) Str::uuid();
    $beforeRevision = $fixture['proposal']->revision;
    $beforeFingerprint = $fixture['projectItem']->baseline_fingerprint;

    expect(fn () => app(RealignProposalItem::class)->execute(
        $fixture['actor'],
        $fixture['proposal'],
        $fixture['projectItem'],
        ProposalRealignmentChoice::Manual,
        null,
        [$fixture['expenseAction']->id],
        $operationId,
        $beforeRevision,
    ))->toThrow(ValidationException::class)
        ->and($fixture['proposal']->fresh()->revision)->toBe($beforeRevision)
        ->and($fixture['projectItem']->fresh()->baseline_fingerprint)->toBe($beforeFingerprint)
        ->and($fixture['projectAction']->fresh()->status->value)->toBe('active')
        ->and($fixture['projectTransitionAction']->fresh()->status->value)->toBe('active')
        ->and($fixture['expenseAction']->fresh()->status->value)->toBe('active')
        ->and(AuditEvent::query()->where('operation_id', $operationId)->exists())->toBeFalse();
});

it('approves one preserved child expense after keeping the realigned Project plan', function (): void {
    $fixture = staleProjectChildProposal();
    $expenseItem = $fixture['expenseAction']->item;
    $expenseItemId = (string) $expenseItem->proposal_item_id;
    $expensePayload = $fixture['expenseAction']->payload;

    app(RealignProposalItem::class)->execute(
        $fixture['actor'],
        $fixture['proposal'],
        $fixture['projectItem'],
        ProposalRealignmentChoice::Keep,
        'Confermo la riclassificazione proposta',
        [],
        (string) Str::uuid(),
        $fixture['proposal']->revision,
    );
    $budget = app(ApproveProposal::class)->execute(
        $fixture['actor'],
        $fixture['proposal']->refresh(),
        (string) Str::uuid(),
    );
    $expense = Expense::query()->where('company_id', $fixture['company']->id)->sole();

    expect($expense->project_id)->toBe($fixture['project']->id)
        ->and($expense->exercise_id)->toBe($fixture['exercise']->id)
        ->and($expense->lines()->where('type', 'estimate')->sole()->amount)->toBe('300.00')
        ->and($expense->lines()->where('type', 'actual')->count())->toBe(0)
        ->and(Expense::query()->where('company_id', $fixture['company']->id)->count())->toBe(1)
        ->and($budget->total_approved_allocation)->toBe('300.00')
        ->and(BudgetSourceRow::query()->where('budget_snapshot_id', $budget->id)->where('source_type', 'project')->sole()->approved_allocation)->toBe('300.00')
        ->and((string) $expenseItem->fresh()->proposal_item_id)->toBe($expenseItemId)
        ->and($fixture['expenseAction']->fresh()->payload)->toEqual($expensePayload);
});

it('blocks approval when the preserved child becomes incompatible with the realigned Project', function (): void {
    $fixture = projectChildProposal();
    app(CreateProjectTransition::class)->execute($fixture['actor'], $fixture['project'], [
        'from_state' => 'open',
        'to_state' => 'closed',
        'effective_date' => $fixture['exercise']->year.'-01-01',
        'reason' => 'Chiusura operativa',
    ], (string) Str::uuid());
    $fixture['proposal'] = app(ReviewProposalReadiness::class)->execute(
        $fixture['actor'],
        $fixture['proposal']->refresh(),
        (string) Str::uuid(),
    );
    $projectItem = $fixture['projectItem']->fresh();

    expect($projectItem->readiness_state)->toBe(ProposalReadinessState::ToRealign);

    app(RealignProposalItem::class)->execute(
        $fixture['actor'],
        $fixture['proposal'],
        $projectItem,
        ProposalRealignmentChoice::Reload,
        null,
        [],
        (string) Str::uuid(),
        $fixture['proposal']->revision,
    );
    $reviewed = app(ReviewProposalReadiness::class)->execute(
        $fixture['actor'],
        $fixture['proposal']->refresh(),
        (string) Str::uuid(),
    );
    $expenseItem = $fixture['expenseAction']->item->fresh();
    $approvalOperation = (string) Str::uuid();

    expect($expenseItem->readiness_state)->toBe(ProposalReadinessState::Inconsistent)
        ->and($fixture['expenseAction']->fresh()->status->value)->toBe('active')
        ->and(data_get($expenseItem->result, 'project_item_id'))->toBe((string) $projectItem->proposal_item_id)
        ->and(fn () => app(ApproveProposal::class)->execute($fixture['actor'], $reviewed, $approvalOperation))->toThrow(ValidationException::class)
        ->and(Expense::query()->where('company_id', $fixture['company']->id)->count())->toBe(0)
        ->and(BudgetSnapshot::query()->where('proposal_id', $fixture['proposal']->id)->count())->toBe(0);
});

it('rolls back a failed Project realignment without touching its child decision', function (): void {
    $fixture = staleProjectChildProposal();
    $operationId = (string) Str::uuid();
    $beforeBaseline = $fixture['projectItem']->baseline;
    $beforeResult = $fixture['projectItem']->result;

    expect(fn () => app(RealignProposalItem::class)->execute(
        $fixture['actor'],
        $fixture['proposal'],
        $fixture['projectItem'],
        ProposalRealignmentChoice::Reload,
        null,
        [],
        $operationId,
        $fixture['proposal']->revision,
        fn (): never => throw new RuntimeException('failure'),
    ))->toThrow(RuntimeException::class)
        ->and($fixture['projectItem']->fresh()->baseline)->toBe($beforeBaseline)
        ->and($fixture['projectItem']->result)->toBe($beforeResult)
        ->and($fixture['projectAction']->fresh()->status->value)->toBe('active')
        ->and($fixture['projectTransitionAction']->fresh()->status->value)->toBe('active')
        ->and($fixture['expenseAction']->fresh()->status->value)->toBe('active')
        ->and(AuditEvent::query()->where('operation_id', $operationId)->exists())->toBeFalse();
});

it('keeps and withdraws one shared relation from its two typed endpoints', function (): void {
    $company = Company::factory()->create();
    $exercise = Exercise::factory()->for($company)->create(['year' => 2026]);
    $actor = User::factory()->create();
    grantTestPermissions(['company_id' => $company->id, 'user' => $actor, 'permissions' => TestPermissions::MANAGE_PROPOSALS]);
    $project = Project::factory()->for($company)->create([
        'initial_state' => 'open',
        'initial_effective_date' => '2025-01-01',
    ]);
    $contract = Contract::factory()->for($company)->create([
        'contractual_start_date' => '2025-01-01',
    ]);
    $proposal = app(InitializeProposal::class)->execute($actor, $company, $exercise, (string) Str::uuid());
    $projectItem = $proposal->items()->where('project_id', $project->id)->sole();
    $contractItem = $proposal->items()->where('contract_id', $contract->id)->sole();
    $relation = app(PlanProposalRelation::class)->execute($actor, $proposal, [
        'project_origin_key' => $project->originKey(),
        'contract_origin_key' => $contract->originKey(),
    ], (string) Str::uuid(), 0);

    $project->increment('revision');
    $kept = app(RealignProposalItem::class)->execute(
        $actor,
        $proposal->refresh(),
        $projectItem,
        ProposalRealignmentChoice::Keep,
        'Confermo il collegamento',
        [],
        (string) Str::uuid(),
        1,
    );

    expect($kept->readiness_state)->toBe(ProposalReadinessState::Aligned)
        ->and($relation->fresh()->status->value)->toBe('active')
        ->and(ProjectContractLink::query()->where('company_id', $company->id)->count())->toBe(0);

    $contract->increment('revision');
    app(RealignProposalItem::class)->execute(
        $actor,
        $proposal->refresh(),
        $contractItem,
        ProposalRealignmentChoice::Reload,
        null,
        [],
        (string) Str::uuid(),
        2,
    );

    expect($relation->fresh()->status->value)->toBe('withdrawn')
        ->and(ProjectContractLink::query()->where('company_id', $company->id)->count())->toBe(0);
});
