<?php

use App\Actions\Operations\ChangeProjectDeferral;
use App\Actions\Proposals\InitializeProposal;
use App\Actions\Proposals\PlanProjectDeferral;
use App\Domain\Expenses\ExpenseLineType;
use App\Domain\Proposals\ProposalActionPayload;
use App\Domain\Proposals\ProposalActionType;
use App\Models\BudgetSnapshot;
use App\Models\Company;
use App\Models\Exercise;
use App\Models\Expense;
use App\Models\ExpenseLine;
use App\Models\Project;
use App\Models\ProjectDeferral;
use App\Models\Proposal;
use App\Models\ProposalItem;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Tests\Support\TestPermissions;

uses(RefreshDatabase::class);

it('rejects split modes, zero transfers and a Project deferral action on a non-Project item', function (): void {
    $context = ['source_exercise_id' => 1, 'project_revision' => 0, 'project_fingerprint' => str_repeat('a', 64), 'allocation' => '10.00', 'actual' => '0.00', 'maximum_transferable' => '10.00', 'referenced_estimates' => []];
    expect(fn () => ProposalActionPayload::validate(ProposalActionType::PlanProjectDeferral, [
        'source_exercise_id' => 1, 'destination_exercise_id' => 2, 'mode' => 'carryover',
        'carryover_amount' => '1.00', 'reprogrammed_amount' => '1.00',
        'source_estimate_reductions' => [], 'destination_plans' => [], 'source_context' => $context,
    ]))->toThrow(ValidationException::class)
        ->and(fn () => ProposalActionPayload::validate(ProposalActionType::PlanProjectDeferral, [
            'source_exercise_id' => 1, 'destination_exercise_id' => 2, 'mode' => 'carryover',
            'carryover_amount' => '0.00', 'reprogrammed_amount' => '0.00',
            'source_estimate_reductions' => [], 'destination_plans' => [], 'source_context' => $context,
        ]))->toThrow(ValidationException::class);

    $actor = User::factory()->create();
    $company = Company::factory()->create();
    grantTestPermissions(['company_id' => $company->id, 'user' => $actor, 'permissions' => TestPermissions::MANAGE_PROPOSALS]);
    $source = Exercise::factory()->for($company)->create(['year' => 2026]);
    $destination = Exercise::factory()->for($company)->create(['year' => 2027]);
    $proposal = app(InitializeProposal::class)->execute($actor, $company, $destination, (string) Str::uuid());
    $expenseItem = ProposalItem::factory()->for($proposal)->create(['company_id' => $company->id, 'source_type' => 'expense']);

    expect(fn () => app(PlanProjectDeferral::class)->execute($actor, $proposal, $expenseItem, [
        'source_exercise_id' => $source->id, 'destination_exercise_id' => $destination->id,
        'mode' => 'carryover', 'carryover_amount' => '1.00',
    ], 'Non Progetto', (string) Str::uuid(), 0))->toThrow(ValidationException::class);
});

it('does not infer or automatically prefill the maximum when a Proposal is initialized', function (): void {
    $actor = User::factory()->create();
    $company = Company::factory()->create();
    grantTestPermissions(['company_id' => $company->id, 'user' => $actor, 'permissions' => TestPermissions::MANAGE_PROPOSALS]);
    $source = Exercise::factory()->for($company)->create(['year' => 2026]);
    $destination = Exercise::factory()->for($company)->create(['year' => 2027]);
    $project = Project::factory()->for($company)->create(['initial_state' => 'open', 'initial_effective_date' => '2026-01-01']);
    $expense = Expense::factory()->forExercise($source)->for($project)->create();
    ExpenseLine::factory()->for($expense)->create(['amount' => '100.00']);

    $proposal = app(InitializeProposal::class)->execute($actor, $company, $destination, (string) Str::uuid());
    $incoming = data_get($proposal->items()->where('project_id', $project->id)->sole()->result, 'incoming_deferral');
    expect($incoming['mode'])->toBe('none')
        ->and($incoming['carryover_amount'])->toBe('0.00')
        ->and($incoming['reprogrammed_amount'])->toBe('0.00');
});

it('blocks Closed-year and unauthorized direct mutation without changing Budgets or Actuals', function (): void {
    $manager = User::factory()->create();
    $company = Company::factory()->create();
    grantTestPermissions(['company_id' => $company->id, 'user' => $manager, 'permissions' => TestPermissions::MANAGE_OPERATIONS]);
    $source = Exercise::factory()->for($company)->create(['year' => 2026]);
    $destination = Exercise::factory()->for($company)->create(['year' => 2027]);
    $project = Project::factory()->for($company)->create();
    $expense = Expense::factory()->forExercise($source)->for($project)->create();
    $actual = ExpenseLine::factory()->for($expense)->actual()->create(['amount' => '7.00']);
    $deferral = ProjectDeferral::factory()->carryover('5.00')->create([
        'company_id' => $company->id, 'project_id' => $project->id,
        'source_exercise_id' => $source->id, 'destination_exercise_id' => $destination->id,
        'carryover_state' => 'consolidated',
    ]);
    closeExerciseFixture($source, $manager);
    $budgetProposal = Proposal::factory()->for($company)->for($destination)->create();
    $budget = BudgetSnapshot::factory()->for($budgetProposal)->create();
    $budgetUpdatedAt = $budget->updated_at?->toISOString();
    $budgetOperationId = (string) $budget->operation_id;

    expect(fn () => app(ChangeProjectDeferral::class)->preview($manager, $project, $source, $destination, ['mode' => 'none']))
        ->toThrow(ValidationException::class);
    $outsider = User::factory()->create();
    expect(fn () => app(ChangeProjectDeferral::class)->preview($outsider, $project, $source, $destination, ['mode' => 'none']))
        ->toThrow(AuthorizationException::class)
        ->and($deferral->refresh()->mode->value)->toBe('carryover')
        ->and($actual->refresh()->lineType())->toBe(ExpenseLineType::Actual)
        ->and($actual->amount)->toBe('7.00')
        ->and($budget->refresh()->total_approved_allocation)->toBe('0.00')
        ->and((string) $budget->operation_id)->toBe($budgetOperationId)
        ->and($budget->updated_at?->toISOString())->toBe($budgetUpdatedAt);
});
