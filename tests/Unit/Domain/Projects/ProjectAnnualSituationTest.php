<?php

use App\Domain\Expenses\ExpenseLineType;
use App\Domain\Projects\ProjectAnnualSituation;
use App\Domain\Projects\ProjectState;
use App\Models\Company;
use App\Models\CostCenter;
use App\Models\Exercise;
use App\Models\Expense;
use App\Models\ExpenseLine;
use App\Models\Project;
use App\Models\ProjectExerciseClassification;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

it('builds exact annual Project situations from active child Lines once', function () {
    $company = Company::factory()->create();
    $past = Exercise::factory()->for($company)->create(['year' => 2025]);
    $current = Exercise::factory()->for($company)->create(['year' => 2026]);
    $costCenter = CostCenter::factory()->for($company)->create(['name' => 'Ricerca']);
    $project = Project::factory()->for($company)->create([
        'initial_state' => ProjectState::Open,
        'initial_effective_date' => '2025-01-01',
    ]);
    ProjectExerciseClassification::factory()->forProjectAndExercise($project, $current)->create([
        'cost_center_id' => $costCenter->id,
    ]);
    $expense = Expense::factory()->forExercise($current)->create(['project_id' => $project->id]);
    ExpenseLine::factory()->for($expense)->create(['type' => ExpenseLineType::Estimate, 'amount' => '100.10']);
    ExpenseLine::factory()->for($expense)->actual()->create(['amount' => '120.25']);
    ExpenseLine::factory()->for($expense)->actual()->annulled()->create(['amount' => '999.00']);
    $reversed = Expense::factory()->forExercise($current)->reversed()->create(['project_id' => $project->id]);
    ExpenseLine::factory()->for($reversed)->create(['amount' => '999.00']);

    $project->load(['transitions', 'classifications.costCenter']);
    $situations = ProjectAnnualSituation::build($project, [$past, $current], CarbonImmutable::parse('2026-08-17'));

    expect($situations)->toHaveCount(2)
        ->and($situations[0]->year)->toBe(2026)
        ->and($situations[0]->referenceDate)->toBe('2026-08-17')
        ->and($situations[0]->state)->toBe(ProjectState::Open)
        ->and($situations[0]->costCenterLabel)->toBe('Ricerca')
        ->and($situations[0]->allocation)->toBe('100.10')
        ->and($situations[0]->actual)->toBe('120.25')
        ->and($situations[0]->variance)->toBe('20.15')
        ->and($situations[1]->year)->toBe(2025)
        ->and($situations[1]->referenceDate)->toBe('2025-12-31')
        ->and($situations[1]->costCenterLabel)->toBeNull()
        ->and($situations[1]->allocation)->toBe('0.00');
});
