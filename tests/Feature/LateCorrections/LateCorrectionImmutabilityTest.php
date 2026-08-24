<?php

use App\Actions\LateCorrections\RecordLateCorrection;
use App\Domain\Company\Capability;
use App\Models\BudgetSnapshot;
use App\Models\ClosingSnapshot;
use App\Models\Company;
use App\Models\CompanyCapability;
use App\Models\Exercise;
use App\Models\Expense;
use App\Models\ExpenseLine;
use App\Models\Project;
use App\Models\ProjectDeferral;
use App\Models\ProjectExerciseClassification;
use App\Models\Proposal;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

it('keeps Closing, Budget, consolidated Carryover and historical owner facts unchanged', function (): void {
    $company = Company::factory()->create();
    $actor = User::factory()->create();
    CompanyCapability::query()->create(['company_id' => $company->id, 'user_id' => $actor->id, 'capability' => Capability::CorrectClosedExercise]);
    $exercise = Exercise::factory()->for($company)->create(['year' => 2025]);
    $destination = Exercise::factory()->for($company)->create(['year' => 2026]);
    $project = Project::factory()->for($company)->create([
        'initial_state' => 'open',
        'initial_effective_date' => '2025-01-01',
    ]);
    $classification = ProjectExerciseClassification::factory()
        ->forProjectAndExercise($project, $exercise)
        ->create();
    $expense = Expense::factory()->forExercise($exercise)->for($project)->create();
    $original = ExpenseLine::factory()->for($expense)->actual()->create(['amount' => '90.00']);
    $deferral = ProjectDeferral::factory()->create([
        'company_id' => $company->id,
        'project_id' => $project->id,
        'source_exercise_id' => $exercise->id,
        'destination_exercise_id' => $destination->id,
        'mode' => 'carryover',
        'carryover_amount' => '7.50',
        'carryover_state' => 'consolidated',
    ]);
    $proposal = Proposal::factory()->for($company)->for($exercise)->create([
        'status' => 'approved',
        'approved_by_id' => $actor->id,
        'approved_at' => now(),
    ]);
    $budget = BudgetSnapshot::factory()->for($proposal)->create([
        'company_id' => $company->id,
        'exercise_id' => $exercise->id,
        'approved_by_id' => $actor->id,
        'total_approved_allocation' => '250.00',
    ]);
    $snapshot = closeExerciseFixture($exercise, $actor);

    $snapshotValues = $snapshot->only([
        'initial_budget_id',
        'current_budget_id',
        'total_closing_actual',
        'total_consolidated_carryover',
        'total_final_allocation',
    ]);
    $budgetValues = $budget->only(['id', 'exercise_id', 'version', 'total_approved_allocation', 'affected_exercises']);
    $deferralValues = $deferral->only(['project_id', 'source_exercise_id', 'destination_exercise_id', 'mode', 'carryover_amount', 'carryover_state']);
    $classificationValues = $classification->only(['project_id', 'exercise_id', 'cost_center_id']);
    $projectValues = [
        'id' => $project->id,
        'title' => $project->title,
        'initial_state' => $project->initialState()->value,
        'initial_effective_date' => $project->initialEffectiveDate()->toDateString(),
    ];

    app(RecordLateCorrection::class)->execute($actor, $exercise, [
        'source_type' => 'project',
        'source_origin_id' => $project->id,
        'historical_expense_id' => $expense->id,
        'amount' => '12.50',
        'reason' => 'Importo tardivo',
        'belongs_to_closed_exercise' => true,
        'expected_exercise_revision' => $exercise->revision,
        'expected_source_revision' => $project->revision,
        'expected_expense_revision' => $expense->revision,
    ], (string) Str::uuid());

    $projectAfter = Project::query()->findOrFail($project->id);
    expect($exercise->refresh()->isOpen())->toBeFalse()
        ->and($original->refresh()->amount)->toBe('90.00')
        ->and($expense->refresh()->lines()->where('type', 'actual')->count())->toBe(2)
        ->and(ClosingSnapshot::query()->findOrFail($snapshot->id)->only([
            'initial_budget_id',
            'current_budget_id',
            'total_closing_actual',
            'total_consolidated_carryover',
            'total_final_allocation',
        ]))->toBe($snapshotValues)
        ->and(BudgetSnapshot::query()->findOrFail($budget->id)->only(['id', 'exercise_id', 'version', 'total_approved_allocation', 'affected_exercises']))->toBe($budgetValues)
        ->and(ProjectDeferral::query()->findOrFail($deferral->id)->only(['project_id', 'source_exercise_id', 'destination_exercise_id', 'mode', 'carryover_amount', 'carryover_state']))->toBe($deferralValues)
        ->and(ProjectExerciseClassification::query()->findOrFail($classification->id)->only(['project_id', 'exercise_id', 'cost_center_id']))->toBe($classificationValues)
        ->and([
            'id' => $projectAfter->id,
            'title' => $projectAfter->title,
            'initial_state' => $projectAfter->initialState()->value,
            'initial_effective_date' => $projectAfter->initialEffectiveDate()->toDateString(),
        ])->toBe($projectValues);
});
