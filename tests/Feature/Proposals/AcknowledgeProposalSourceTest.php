<?php

use App\Actions\Proposals\AcknowledgeProposalSource;
use App\Actions\Proposals\InitializeProposal;
use App\Actions\Proposals\PlanExpense;
use App\Actions\Proposals\ReviewProposalReadiness;
use App\Domain\Company\AuditEventType;
use App\Domain\Proposals\ProposalActionType;
use App\Models\AuditEvent;
use App\Models\Company;
use App\Models\Exercise;
use App\Models\Expense;
use App\Models\ExpenseLine;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Tests\Support\TestPermissions;

uses(RefreshDatabase::class);

function proposalWithNewExpense(bool $withActual = false): array
{
    $company = Company::factory()->create();
    $exercise = Exercise::factory()->for($company)->create();
    $actor = User::factory()->create();
    grantTestPermissions(['company_id' => $company->id, 'user' => $actor, 'permissions' => TestPermissions::MANAGE_PROPOSALS]);
    $proposal = app(InitializeProposal::class)->execute($actor, $company, $exercise, (string) Str::uuid());
    $expense = Expense::factory()->forExercise($exercise)->create();
    $estimate = ExpenseLine::factory()->for($expense)->create(['type' => 'estimate', 'amount' => '5.00']);
    $actual = $withActual ? ExpenseLine::factory()->for($expense)->create(['type' => 'actual', 'amount' => '2.00']) : null;
    $proposal = app(ReviewProposalReadiness::class)->execute($actor, $proposal->refresh(), (string) Str::uuid());

    return [$actor, $proposal, $proposal->items()->where('expense_id', $expense->id)->sole(), $estimate, $actual];
}

it('acknowledges a new source without creating an economic action and retries once', function (): void {
    [$actor, $proposal, $item, $estimate] = proposalWithNewExpense();
    $operationId = (string) Str::uuid();

    $acknowledged = app(AcknowledgeProposalSource::class)->execute($actor, $proposal, $item, $operationId, $proposal->revision);
    $retry = app(AcknowledgeProposalSource::class)->execute($actor, $proposal->fresh(), $item->fresh(), $operationId, $proposal->revision + 1);

    expect($retry->is($acknowledged))->toBeTrue()
        ->and($acknowledged->readiness_state->value)->toBe('aligned')
        ->and($acknowledged->actions()->count())->toBe(0)
        ->and($estimate->fresh()->amount)->toBe('5.00')
        ->and(AuditEvent::query()->where('operation_id', $operationId)->sole()->eventType())->toBe(AuditEventType::ProposalSourceAcknowledged);
});

it('replays an already prepared estimate plan while leaving actuals unchanged', function (): void {
    [$actor, $proposal, $item, $estimate, $actual] = proposalWithNewExpense(true);
    $action = app(PlanExpense::class)->execute($actor, $proposal, $item, ProposalActionType::SetExpenseEstimates, ['estimate_lines' => [[
        'proposal_line_id' => (string) Str::uuid(), 'line_id' => $estimate->id, 'amount' => '7.00', 'note' => null, 'annulled' => false,
    ]]], null, (string) Str::uuid(), $proposal->revision);

    $current = $proposal->refresh();
    $acknowledged = app(AcknowledgeProposalSource::class)->execute($actor, $current, $item->fresh(), (string) Str::uuid(), $current->revision);

    expect(data_get($acknowledged->result, 'estimate_lines.0.amount'))->toBe('7.00')
        ->and($action->fresh()->status->value)->toBe('active')
        ->and($estimate->fresh()->amount)->toBe('5.00')
        ->and($actual->fresh()->amount)->toBe('2.00');
});

it('rejects stale acknowledgement and rolls back an injected failure', function (): void {
    [$actor, $proposal, $item] = proposalWithNewExpense();

    expect(fn () => app(AcknowledgeProposalSource::class)->execute($actor, $proposal, $item, (string) Str::uuid(), $proposal->revision - 1))
        ->toThrow(ValidationException::class)
        ->and(fn () => app(AcknowledgeProposalSource::class)->execute($actor, $proposal->fresh(), $item->fresh(), (string) Str::uuid(), $proposal->revision, fn (): never => throw new RuntimeException('failure')))
        ->toThrow(RuntimeException::class)
        ->and($item->fresh()->readiness_state->value)->toBe('to_review');
});
