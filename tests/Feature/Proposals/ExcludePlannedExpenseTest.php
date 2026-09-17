<?php

use App\Actions\Proposals\ApproveProposal;
use App\Actions\Proposals\CopyExpenseIntoProposal;
use App\Actions\Proposals\PlanExpense;
use App\Actions\Proposals\PlanProject;
use App\Domain\Company\AuditEventType;
use App\Domain\Proposals\ProposalActionType;
use App\Domain\Proposals\ProposalImpactPlan;
use App\Domain\Proposals\ProposalReadiness;
use App\Domain\Proposals\ProposalSourceSnapshot;
use App\Models\AuditEvent;
use App\Models\Exercise;
use App\Models\Expense;
use App\Models\ExpenseLine;
use App\Models\Proposal;
use App\Models\ProposalItem;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Tests\Support\TestPermissions;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->proposal = Proposal::factory()->create();
    $this->actor = User::factory()->create();
    grantTestPermissions(['company_id' => $this->proposal->company_id, 'user' => $this->actor, 'permissions' => [...TestPermissions::MANAGE_PROPOSALS, ...TestPermissions::APPROVE_BUDGET]]);
    $this->created = app(PlanExpense::class)->create($this->actor, $this->proposal, [
        'description' => 'Spesa da escludere', 'exercise_id' => $this->proposal->exercise_id,
        'estimate_lines' => [['proposal_line_id' => (string) Str::uuid(), 'line_id' => null, 'amount' => '125.00', 'note' => null, 'annulled' => false]],
    ], null, (string) Str::uuid(), 0);
    $this->item = $this->created->item;
});

it('excludes only the proposed expense preserving decisions and audit and creates no live identity or budget row', function (): void {
    $original = $this->created->fresh()->getAttributes();
    $operation = (string) Str::uuid();
    expect(ProposalImpactPlan::build($this->proposal->fresh())[0]['allocation_after'])->toBe('125.00');
    $excluded = app(PlanExpense::class)->execute($this->actor, $this->proposal, $this->item, ProposalActionType::ExcludeExpense, ['reason' => 'Non necessaria'], 'Non necessaria', $operation, 1);
    $retry = app(PlanExpense::class)->execute($this->actor, $this->proposal, $this->item, ProposalActionType::ExcludeExpense, ['reason' => 'Non necessaria'], 'Non necessaria', $operation, 1);
    expect($retry->id)->toBe($excluded->id)
        ->and($this->proposal->fresh()->revision)->toBe(2)
        ->and($this->created->fresh()->getAttributes())->toBe($original)
        ->and($this->proposal->items()->count())->toBe(1)
        ->and($this->proposal->actionHistory()->count())->toBe(2)
        ->and($this->item->fresh()->isExcludedFromPlan())->toBeTrue();
    $event = AuditEvent::query()->where('operation_id', $operation)->sole();
    expect($event->eventType())->toBe(AuditEventType::ProposalActionPlanned)
        ->and($event->new_value['action_type'])->toBe('exclude_expense')
        ->and($event->reason)->toBe('Non necessaria')
        ->and(ProposalImpactPlan::build($this->proposal->fresh())[0]['allocation_after'])->toBe('0.00')
        ->and(ProposalImpactPlan::build($this->proposal->fresh())[0]['sources'])->toBe([])
        ->and(app(ProposalReadiness::class)->assessProposal($this->proposal->fresh())['ready'])->toBeTrue();
    $approval = (string) Str::uuid();
    $budget = app(ApproveProposal::class)->execute($this->actor, $this->proposal->fresh(), $approval);
    expect($budget->total_approved_allocation)->toBe('0.00')
        ->and($budget->rows()->count())->toBe(0)
        ->and(Expense::query()->count())->toBe(0)
        ->and($this->proposal->items()->count())->toBe(1)
        ->and($this->proposal->actionHistory()->count())->toBe(2)
        ->and(AuditEvent::query()->where('operation_id', $approval)->where('event_type', AuditEventType::ExpenseCreated)->exists())->toBeFalse();
});

it('rejects exclusion of a live expense and other source types without changing history', function (string $type): void {
    if ($type === 'expense') {
        $expense = Expense::factory()->forExercise($this->proposal->exercise)->create();
        $item = ProposalItem::factory()->for($this->proposal)->create(['expense_id' => $expense->id, 'baseline_revision' => $expense->revision, 'baseline_fingerprint' => ProposalSourceSnapshot::fingerprint(ProposalSourceSnapshot::expense($expense)), 'baseline' => ProposalSourceSnapshot::expense($expense)]);
    } else {
        $item = ProposalItem::factory()->for($this->proposal)->create(['source_type' => $type]);
    }
    expect(fn () => app(PlanExpense::class)->execute($this->actor, $this->proposal, $item, ProposalActionType::ExcludeExpense, ['reason' => 'No'], 'No', (string) Str::uuid(), 1))->toThrow(ValidationException::class);
    expect($item->fresh()->isExcludedFromPlan())->toBeFalse()->and($this->proposal->fresh()->revision)->toBe(1)->and($this->proposal->actions()->count())->toBe(1);
})->with(['expense', 'project', 'contract']);

it('preserves the copied source and its actuals when excluding a copy whose source later changes', function (): void {
    $sourceExercise = Exercise::factory()->create(['company_id' => $this->proposal->company_id, 'year' => $this->proposal->exercise->year - 1]);
    $source = Expense::factory()->forExercise($sourceExercise)->create();
    ExpenseLine::factory()->for($source)->create(['type' => 'estimate', 'amount' => '40.00']);
    $actual = ExpenseLine::factory()->for($source)->create(['type' => 'actual', 'amount' => '9.00']);
    $copy = app(CopyExpenseIntoProposal::class)->execute($this->actor, $this->proposal, $source, (string) Str::uuid(), 1);
    app(PlanExpense::class)->execute($this->actor, $this->proposal, $copy->item, ProposalActionType::ExcludeExpense, ['reason' => 'Non serve'], 'Non serve', (string) Str::uuid(), 2);
    $source->increment('revision');
    $budget = app(ApproveProposal::class)->execute($this->actor, $this->proposal->fresh(), (string) Str::uuid());
    expect($actual->fresh()->amount)->toBe('9.00')->and($copy->item->fresh()->copied_from_origin_key)->toBe($source->originKey())
        ->and($budget->rows()->count())->toBe(1)->and(Expense::query()->count())->toBe(2);
});

it('rejects stale revisions unauthorized callers and further mutations after exclusion', function (): void {
    $other = User::factory()->create(['company_id' => $this->proposal->company_id]);
    expect(fn () => app(PlanExpense::class)->execute($other, $this->proposal, $this->item, ProposalActionType::ExcludeExpense, [], 'No', (string) Str::uuid(), 1))->toThrow(AuthorizationException::class);
    expect(fn () => app(PlanExpense::class)->execute($this->actor, $this->proposal, $this->item, ProposalActionType::ExcludeExpense, [], 'No', (string) Str::uuid(), 0))->toThrow(ValidationException::class);
    app(PlanExpense::class)->execute($this->actor, $this->proposal, $this->item, ProposalActionType::ExcludeExpense, [], 'No', (string) Str::uuid(), 1);
    expect(fn () => app(PlanExpense::class)->execute($this->actor, $this->proposal, $this->item, ProposalActionType::SetExpenseEstimates, ['estimate_lines' => []], null, (string) Str::uuid(), 2))->toThrow(ValidationException::class);
    expect($this->proposal->fresh()->revision)->toBe(2)->and($this->proposal->actionHistory()->count())->toBe(2);
});

it('rolls back exclusion when audit persistence fails', function (): void {
    $dispatcher = AuditEvent::getEventDispatcher();
    AuditEvent::setEventDispatcher(clone $dispatcher);
    AuditEvent::creating(function (AuditEvent $event): void {
        if (($event->new_value['action_type'] ?? null) === 'exclude_expense') {
            throw new RuntimeException('Audit unavailable');
        }
    });
    try {
        expect(fn () => app(PlanExpense::class)->execute($this->actor, $this->proposal, $this->item, ProposalActionType::ExcludeExpense, [], 'No', (string) Str::uuid(), 1))->toThrow(RuntimeException::class, 'Audit unavailable');
        expect($this->item->fresh()->isExcludedFromPlan())->toBeFalse()->and($this->proposal->fresh()->revision)->toBe(1)->and($this->proposal->actions()->count())->toBe(1);
    } finally {
        AuditEvent::setEventDispatcher($dispatcher);
    }
});

it('excludes a new child expense without removing its planned project or historical association', function (): void {
    $project = app(PlanProject::class)->create($this->actor, $this->proposal, [
        'title' => 'Nuovo progetto', 'initial_state' => 'planned',
        'initial_effective_date' => $this->proposal->exercise->year.'-01-01', 'exercise_id' => $this->proposal->exercise_id,
    ], (string) Str::uuid(), 1)->item;
    $child = app(PlanExpense::class)->create($this->actor, $this->proposal, [
        'description' => 'Spesa figlia', 'exercise_id' => $this->proposal->exercise_id,
        'project_item_id' => $project->proposal_item_id,
        'estimate_lines' => [['proposal_line_id' => (string) Str::uuid(), 'line_id' => null, 'amount' => '50.00', 'annulled' => false]],
    ], null, (string) Str::uuid(), 2)->item;
    app(PlanProject::class)->execute($this->actor, $this->proposal, $project, ProposalActionType::PlanProjectChildExpenses, [
        'child_item_ids' => [$child->proposal_item_id], 'existing_expenses' => [],
    ], null, (string) Str::uuid(), 3);
    app(PlanExpense::class)->execute($this->actor, $this->proposal, $child, ProposalActionType::ExcludeExpense, [], null, (string) Str::uuid(), 4);
    $budget = app(ApproveProposal::class)->execute($this->actor, $this->proposal->fresh(), (string) Str::uuid());
    expect($budget->total_approved_allocation)->toBe('125.00')
        ->and($budget->rows()->count())->toBe(2)
        ->and(Expense::query()->count())->toBe(1)
        ->and($project->fresh()->result['child_item_ids'])->toBe([$child->proposal_item_id])
        ->and($this->proposal->actionHistory()->count())->toBe(5);
});
