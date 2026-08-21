<?php

use App\Actions\Proposals\ApproveProposal;
use App\Actions\Proposals\InitializeProposal;
use App\Actions\Proposals\PlanExpense;
use App\Domain\Company\AuditEventType;
use App\Domain\Company\Capability;
use App\Domain\Proposals\ProposalActionType;
use App\Models\AuditEvent;
use App\Models\BudgetSnapshot;
use App\Models\Company;
use App\Models\CompanyCapability;
use App\Models\Exercise;
use App\Models\Expense;
use App\Models\ExpenseLine;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

function approvalFixture(): array
{
    $company = Company::factory()->create();
    $exercise = Exercise::factory()->for($company)->create();
    $user = User::factory()->create();
    foreach ([Capability::ManageProposals, Capability::ApproveBudget] as $capability) {
        CompanyCapability::query()->create(['company_id' => $company->id, 'user_id' => $user->id, 'capability' => $capability]);
    }
    $expense = Expense::factory()->forExercise($exercise)->create();
    $line = ExpenseLine::factory()->for($expense)->create(['type' => 'estimate', 'amount' => '5.00']);
    $proposal = app(InitializeProposal::class)->execute($user, $company, $exercise, (string) Str::uuid());
    $payload = ['estimate_lines' => [['proposal_line_id' => (string) Str::uuid(), 'line_id' => $line->id, 'amount' => '8.00', 'note' => null, 'annulled' => false]]];
    app(PlanExpense::class)->execute($user, $proposal, $proposal->items->sole(), ProposalActionType::SetExpenseEstimates, $payload, null, (string) Str::uuid(), 0);

    return compact('company', 'exercise', 'user', 'expense', 'proposal');
}

it('applies and retries one complete approval exactly once', function (): void {
    ['user' => $user, 'expense' => $expense, 'proposal' => $proposal] = approvalFixture();
    $operation = (string) Str::uuid();
    $budget = app(ApproveProposal::class)->execute($user, $proposal->refresh(), $operation);
    $retry = app(ApproveProposal::class)->execute($user, $proposal->refresh(), $operation);
    expect($retry->is($budget))->toBeTrue()->and($expense->refresh()->allocation())->toBe('8.00')->and(BudgetSnapshot::query()->count())->toBe(1);
});

it('preserves an unchanged existing Estimate Line identity during approval', function (): void {
    $company = Company::factory()->create();
    $exercise = Exercise::factory()->for($company)->create();
    $user = User::factory()->create();
    foreach ([Capability::ManageProposals, Capability::ApproveBudget] as $capability) {
        CompanyCapability::query()->create(['company_id' => $company->id, 'user_id' => $user->id, 'capability' => $capability]);
    }
    $expense = Expense::factory()->forExercise($exercise)->create();
    $line = ExpenseLine::factory()->for($expense)->create(['type' => 'estimate', 'amount' => '5.00']);
    $proposal = app(InitializeProposal::class)->execute($user, $company, $exercise, (string) Str::uuid());

    app(ApproveProposal::class)->execute($user, $proposal, (string) Str::uuid());

    expect($line->refresh()->amount)->toBe('5.00')
        ->and($line->annulled_at)->toBeNull()
        ->and($expense->lines()->where('type', 'estimate')->count())->toBe(1);
});

it('rolls back every live effect when approval fails after application', function (): void {
    ['user' => $user, 'expense' => $expense, 'proposal' => $proposal] = approvalFixture();
    $operation = (string) Str::uuid();
    try {
        app(ApproveProposal::class)->execute($user, $proposal->refresh(), $operation, checkpoint: fn (string $stage) => $stage === 'after_live_apply' ? throw new RuntimeException('failure') : null);
    } catch (RuntimeException) {
    }
    $failure = AuditEvent::query()->where('operation_id', $operation)->sole();
    expect($expense->refresh()->allocation())->toBe('5.00')->and(BudgetSnapshot::query()->count())->toBe(0)->and($proposal->refresh()->status->value)->toBe('draft')
        ->and($failure->eventType())->toBe(AuditEventType::ProposalApprovalFailed)
        ->and($failure->new_value['rolled_back'])->toBeTrue()
        ->and($failure->reason)->not->toContain('failure');
});

it('retries a failed approval operation with one deterministic event set', function (): void {
    ['user' => $user, 'proposal' => $proposal] = approvalFixture();
    $operation = (string) Str::uuid();
    try {
        app(ApproveProposal::class)->execute($user, $proposal->refresh(), $operation, checkpoint: fn (string $stage) => $stage === 'after_live_apply' ? throw new RuntimeException('sensitive detail') : null);
    } catch (RuntimeException) {
    }

    $budget = app(ApproveProposal::class)->execute($user, $proposal->refresh(), $operation);
    $events = AuditEvent::query()->where('operation_id', $operation)->orderBy('event_sequence')->get();

    expect($budget->proposal_id)->toBe($proposal->id)
        ->and(BudgetSnapshot::query()->where('operation_id', $operation)->count())->toBe(1)
        ->and($events->pluck('event_sequence')->all())->toBe([0, 1, 2, 4294967295])
        ->and($events->where('event_type', AuditEventType::ProposalApprovalFailed)->count())->toBe(1)
        ->and($events->first()->eventType())->toBe(AuditEventType::ExpenseLineUpdated)
        ->and($events->first()->previous_value)->toHaveKeys(['proposal_id', 'proposal_item_id', 'baseline_revision', 'plan'])
        ->and($events->first()->new_value)->toHaveKeys(['action_type', 'action_payload', 'approved_plan', 'budget_id'])
        ->and($events->first()->allocated_impact_by_exercise)->toContain('3.00');
});
