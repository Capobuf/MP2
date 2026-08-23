<?php

use App\Actions\Proposals\InitializeProposal;
use App\Actions\Proposals\PlanExpense;
use App\Actions\Proposals\RealignProposalItem;
use App\Actions\Proposals\ReviewProposalReadiness;
use App\Domain\Company\AuditEventType;
use App\Domain\Company\Capability;
use App\Domain\Proposals\ProposalActionType;
use App\Domain\Proposals\ProposalRealignmentChoice;
use App\Models\AuditEvent;
use App\Models\Company;
use App\Models\CompanyCapability;
use App\Models\Exercise;
use App\Models\Expense;
use App\Models\ExpenseLine;
use App\Models\Supplier;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

uses(RefreshDatabase::class);

function staleExpenseProposal(): array
{
    $company = Company::factory()->create();
    $exercise = Exercise::factory()->for($company)->create();
    $actor = User::factory()->create();
    CompanyCapability::query()->create(['company_id' => $company->id, 'user_id' => $actor->id, 'capability' => Capability::ManageProposals]);
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
