<?php

use App\Actions\Proposals\ApproveProposal;
use App\Actions\Proposals\InitializeProposal;
use App\Actions\Proposals\PlanExpense;
use App\Domain\Company\Capability;
use App\Domain\Proposals\BudgetPayloadGuard;
use App\Domain\Proposals\ProposalActionType;
use App\Models\BudgetEvidence;
use App\Models\BudgetSnapshot;
use App\Models\BudgetSourceRow;
use App\Models\Company;
use App\Models\CompanyCapability;
use App\Models\Exercise;
use App\Models\Expense;
use App\Models\ExpenseLine;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

uses(RefreshDatabase::class);

function s6PrimaryInvariantFixture(): array
{
    $company = Company::factory()->create();
    $exercise = Exercise::factory()->for($company)->create();
    $actor = User::factory()->create();
    foreach ([Capability::ManageProposals, Capability::ApproveBudget] as $capability) {
        CompanyCapability::query()->create([
            'company_id' => $company->id,
            'user_id' => $actor->id,
            'capability' => $capability,
        ]);
    }
    $expense = Expense::factory()->forExercise($exercise)->create(['description' => 'Piano originario']);
    $estimate = ExpenseLine::factory()->for($expense)->create(['type' => 'estimate', 'amount' => '5.00']);
    $actual = ExpenseLine::factory()->for($expense)->create(['type' => 'actual', 'amount' => '3.00']);
    $proposal = app(InitializeProposal::class)->execute($actor, $company, $exercise, (string) Str::uuid());
    app(PlanExpense::class)->execute(
        $actor,
        $proposal,
        $proposal->items->sole(),
        ProposalActionType::SetExpenseEstimates,
        ['estimate_lines' => [[
            'proposal_line_id' => (string) Str::uuid(),
            'line_id' => $estimate->id,
            'amount' => '8.00',
            'note' => null,
            'annulled' => false,
        ]]],
        null,
        (string) Str::uuid(),
        0,
    );

    return compact('company', 'exercise', 'actor', 'expense', 'estimate', 'actual', 'proposal');
}

it('maps invariant 28.17: Budget v1 and its aggregate are immutable and never deleted', function (): void {
    $budget = BudgetSnapshot::factory()->create();
    $row = BudgetSourceRow::factory()->for($budget, 'budget')->create(['company_id' => $budget->company_id]);
    $evidence = BudgetEvidence::factory()->for($budget, 'budget')->create(['company_id' => $budget->company_id]);

    expect(fn () => $budget->update(['total_approved_allocation' => '1.00']))->toThrow(LogicException::class)
        ->and(fn () => $budget->delete())->toThrow(LogicException::class)
        ->and(fn () => $row->update(['label' => 'Riscritta']))->toThrow(LogicException::class)
        ->and(fn () => $evidence->delete())->toThrow(LogicException::class);
});

it('maps invariant 28.19: a Draft changes only the isolated plan', function (): void {
    ['expense' => $expense, 'estimate' => $estimate, 'actual' => $actual, 'proposal' => $proposal] = s6PrimaryInvariantFixture();

    expect($proposal->refresh()->status->value)->toBe('draft')
        ->and($proposal->items()->sole()->result['estimate_lines'][0]['amount'])->toBe('8.00')
        ->and($expense->refresh()->allocation())->toBe('5.00')
        ->and($estimate->refresh()->amount)->toBe('5.00')
        ->and($actual->refresh()->amount)->toBe('3.00');
});

it('maps invariant 28.20: Proposal payloads cannot represent Actual movement or reclassification', function (): void {
    foreach (['actual_lines', 'actual_total', 'actual_exercise_id', 'actual_cost_center_id'] as $key) {
        expect(fn () => BudgetPayloadGuard::assertPlanOnly([$key => []]))
            ->toThrow(ValidationException::class);
    }
});

it('maps invariant 28.21: a new object remains only a ProposalItem until approval', function (): void {
    $company = Company::factory()->create();
    $exercise = Exercise::factory()->for($company)->create();
    $actor = User::factory()->create();
    CompanyCapability::query()->create([
        'company_id' => $company->id,
        'user_id' => $actor->id,
        'capability' => Capability::ManageProposals,
    ]);
    $proposal = app(InitializeProposal::class)->execute($actor, $company, $exercise, (string) Str::uuid());

    app(PlanExpense::class)->create($actor, $proposal, [
        'description' => 'Nuova Spesa proposta',
        'exercise_id' => $exercise->id,
        'supplier_id' => null,
        'cost_center_id' => null,
        'project_id' => null,
        'project_item_id' => null,
        'estimate_lines' => [[
            'proposal_line_id' => (string) Str::uuid(),
            'line_id' => null,
            'amount' => '10.00',
            'note' => null,
            'annulled' => false,
        ]],
    ], null, (string) Str::uuid(), 0);

    expect(Expense::query()->where('company_id', $company->id)->count())->toBe(0)
        ->and($proposal->items()->sole()->expense_id)->toBeNull();
});

it('maps invariant 28.23: any approval failure rolls back every effect', function (): void {
    ['expense' => $expense, 'proposal' => $proposal] = s6PrimaryInvariantFixture();

    try {
        app(ApproveProposal::class)->execute(
            $proposal->creator,
            $proposal->refresh(),
            (string) Str::uuid(),
            checkpoint: fn (string $stage) => $stage === 'after_budget_rows'
                ? throw new RuntimeException('injected failure')
                : null,
        );
    } catch (RuntimeException $exception) {
        expect($exception->getMessage())->toBe('injected failure');
    }

    expect($expense->refresh()->allocation())->toBe('5.00')
        ->and($proposal->refresh()->status->value)->toBe('draft')
        ->and(BudgetSnapshot::query()->where('proposal_id', $proposal->id)->exists())->toBeFalse();
});

it('maps invariant 28.47: snapshot reading does not depend on the live source', function (): void {
    ['actor' => $actor, 'expense' => $expense, 'proposal' => $proposal] = s6PrimaryInvariantFixture();
    $budget = app(ApproveProposal::class)->execute($actor, $proposal->refresh(), (string) Str::uuid());
    $stored = $budget->fresh(['rows'])->toArray();

    $expense->update(['description' => 'Sorgente non più selezionabile', 'reversed_at' => now(), 'revision' => 2]);

    expect($budget->fresh(['rows'])->toArray())->toBe($stored);
});

it('maps invariant 28.48: Budget baseline excludes Actual Residual and Variance recursively', function (): void {
    ['actor' => $actor, 'actual' => $actual, 'proposal' => $proposal] = s6PrimaryInvariantFixture();
    $budget = app(ApproveProposal::class)->execute($actor, $proposal->refresh(), (string) Str::uuid());
    $serializedDetail = strtolower(json_encode($budget->rows()->sole()->detail, JSON_THROW_ON_ERROR));

    expect($actual->refresh()->amount)->toBe('3.00')
        ->and($serializedDetail)->not->toContain('actual')
        ->and($serializedDetail)->not->toContain('residual')
        ->and($serializedDetail)->not->toContain('variance')
        ->and($serializedDetail)->not->toContain('closing')
        ->and($serializedDetail)->not->toContain('forecast');
});
