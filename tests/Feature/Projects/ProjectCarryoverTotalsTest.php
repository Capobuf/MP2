<?php

use App\Actions\Operations\SetExpenseLineActive;
use App\Actions\Operations\UpdateExpenseLine;
use App\Actions\Proposals\ApproveProposal;
use App\Actions\Proposals\InitializeProposal;
use App\Actions\Proposals\PlanProject;
use App\Domain\Proposals\ProposalActionType;
use App\Models\Company;
use App\Models\Contract;
use App\Models\Exercise;
use App\Models\Expense;
use App\Models\ExpenseLine;
use App\Models\Project;
use App\Models\ProjectDeferral;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\Support\TestPermissions;

uses(RefreshDatabase::class);

it('adds received Carryover once to Project and Exercise allocation only', function (): void {
    $company = Company::factory()->create();
    $source = Exercise::factory()->for($company)->create(['year' => 2026]);
    $destination = Exercise::factory()->for($company)->create(['year' => 2027]);
    $project = Project::factory()->for($company)->create();
    $projectExpense = Expense::factory()->forExercise($destination)->for($project)->create();
    ExpenseLine::factory()->for($projectExpense)->create(['amount' => '100.00']);
    $standalone = Expense::factory()->forExercise($destination)->create();
    ExpenseLine::factory()->for($standalone)->create(['amount' => '20.00']);
    $contract = Contract::factory()->for($company)->create();
    $contractExpense = Expense::factory()->forExercise($destination)->for($contract)->create();
    ExpenseLine::factory()->for($contractExpense)->create(['amount' => '30.00']);
    ProjectDeferral::factory()->carryover('40.00')->create([
        'company_id' => $company->id,
        'project_id' => $project->id,
        'source_exercise_id' => $source->id,
        'destination_exercise_id' => $destination->id,
    ]);

    expect($project->annualTotals()[$destination->id]['allocation'])->toBe('140.00')
        ->and($source->allocation())->toBe('0.00')
        ->and($destination->allocation())->toBe('190.00')
        ->and($contract->annualTotals()[$destination->id]['allocation'])->toBe('30.00')
        ->and($standalone->allocation())->toBe('20.00');
});

it('versions only touched Project Estimate lines and leaves no-ops at the same revision', function (): void {
    $actor = User::factory()->create();
    $company = Company::factory()->create();
    grantTestPermissions(['company_id' => $company->id, 'user' => $actor, 'permissions' => TestPermissions::MANAGE_OPERATIONS]);
    $exercise = Exercise::factory()->for($company)->create(['year' => 2026]);
    $project = Project::factory()->for($company)->create(['initial_state' => 'open', 'initial_effective_date' => '2026-01-01']);
    $expense = Expense::factory()->forExercise($exercise)->for($project)->create();
    $estimate = ExpenseLine::factory()->for($expense)->create(['type' => 'estimate', 'amount' => '10.00']);
    $actual = ExpenseLine::factory()->for($expense)->actual()->create(['amount' => '1.00']);

    expect($estimate->revision)->toBe(0);
    app(UpdateExpenseLine::class)->execute($actor, $estimate, ['type' => 'estimate', 'amount' => '12.00'], (string) Str::uuid());
    app(UpdateExpenseLine::class)->execute($actor, $estimate->refresh(), ['type' => 'estimate', 'amount' => '12.00'], (string) Str::uuid());
    app(SetExpenseLineActive::class)->execute($actor, $estimate->refresh(), false, (string) Str::uuid());
    app(UpdateExpenseLine::class)->execute($actor, $actual, ['type' => 'actual', 'amount' => '2.00'], (string) Str::uuid());

    expect($estimate->refresh()->revision)->toBe(2)
        ->and($actual->refresh()->revision)->toBe(0);
});

it('increments a Project Estimate line revision once when Proposal approval changes it', function (): void {
    $actor = User::factory()->create();
    $company = Company::factory()->create();
    foreach ([TestPermissions::MANAGE_PROPOSALS, TestPermissions::APPROVE_BUDGET] as $capability) {
        grantTestPermissions(['company_id' => $company->id, 'user' => $actor, 'permissions' => $capability]);
    }
    $exercise = Exercise::factory()->for($company)->create(['year' => 2026]);
    $project = Project::factory()->for($company)->create(['initial_state' => 'open', 'initial_effective_date' => '2026-01-01']);
    $expense = Expense::factory()->forExercise($exercise)->for($project)->create();
    $estimate = ExpenseLine::factory()->for($expense)->create(['amount' => '10.00']);
    $proposal = app(InitializeProposal::class)->execute($actor, $company, $exercise, (string) Str::uuid());
    $item = $proposal->items()->where('project_id', $project->id)->sole();

    app(PlanProject::class)->execute($actor, $proposal, $item, ProposalActionType::PlanProjectChildExpenses, [
        'child_item_ids' => [],
        'existing_expenses' => [[
            'expense_id' => $expense->id,
            'estimate_lines' => [[
                'proposal_line_id' => (string) Str::uuid(),
                'line_id' => $estimate->id,
                'amount' => '15.00',
                'note' => null,
                'annulled' => false,
            ]],
        ]],
    ], null, (string) Str::uuid(), 0);
    app(ApproveProposal::class)->execute($actor, $proposal->refresh(), (string) Str::uuid());

    expect($estimate->refresh()->revision)->toBe(1)
        ->and($estimate->amount)->toBe('15.00');
});
