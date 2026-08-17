<?php

use App\Domain\Expenses\ExpenseImpactPlan;
use App\Models\Company;
use App\Models\CostCenter;
use App\Models\Exercise;
use App\Models\Expense;
use App\Models\ExpenseLine;
use App\Models\Project;
use App\Models\ProjectExerciseClassification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

it('captures a whole same-year Project ownership move with exact deltas and stable identities', function () {
    $company = Company::factory()->create();
    $exercise = Exercise::factory()->for($company)->create(['revision' => 7]);
    $source = Project::factory()->for($company)->create(['revision' => 2]);
    $target = Project::factory()->for($company)->create(['revision' => 4]);
    $sourceCenter = CostCenter::factory()->for($company)->create();
    $targetCenter = CostCenter::factory()->for($company)->create();
    ProjectExerciseClassification::factory()->forProjectAndExercise($source, $exercise)->create(['cost_center_id' => $sourceCenter->id]);
    ProjectExerciseClassification::factory()->forProjectAndExercise($target, $exercise)->create(['cost_center_id' => $targetCenter->id]);
    $expense = Expense::factory()->forExercise($exercise)->for($source)->create(['direct_cost_center_id' => null, 'revision' => 3]);
    $estimate = ExpenseLine::factory()->for($expense)->create(['amount' => '100.00']);
    $actual = ExpenseLine::factory()->for($expense)->actual()->create(['amount' => '30.00']);

    $plan = ExpenseImpactPlan::build($expense, $exercise, $exercise, null, null, 'Correzione', $source, $target, 'ordinary');

    expect($plan->sourceProjectId)->toBe($source->id)
        ->and($plan->targetProjectId)->toBe($target->id)
        ->and($plan->projectRevisions)->toBe([(string) $source->id => 2, (string) $target->id => 4])
        ->and($plan->sourceCostCenterId)->toBe($sourceCenter->id)
        ->and($plan->targetCostCenterId)->toBe($targetCenter->id)
        ->and($plan->lineIds)->toBe([$estimate->id, $actual->id])
        ->and($plan->projectImpacts[(string) $source->id]['allocation_delta'])->toBe('-100.00')
        ->and($plan->projectImpacts[(string) $source->id]['actual_delta'])->toBe('-30.00')
        ->and($plan->projectImpacts[(string) $target->id]['allocation_delta'])->toBe('100.00')
        ->and($plan->projectImpacts[(string) $target->id]['actual_delta'])->toBe('30.00')
        ->and($plan->exerciseImpacts[(string) $exercise->id]['allocation_delta'])->toBe('0.00')
        ->and($plan->fingerprint())->toHaveLength(64);
});

it('captures entering and leaving a Project with direct and inherited classification', function () {
    $company = Company::factory()->create();
    $exercise = Exercise::factory()->for($company)->create();
    $direct = CostCenter::factory()->for($company)->create();
    $inherited = CostCenter::factory()->for($company)->create();
    $project = Project::factory()->for($company)->create();
    ProjectExerciseClassification::factory()->forProjectAndExercise($project, $exercise)->create(['cost_center_id' => $inherited->id]);
    $expense = Expense::factory()->forExercise($exercise)->create(['direct_cost_center_id' => $direct->id]);
    ExpenseLine::factory()->for($expense)->create(['amount' => '10.00']);

    $enter = ExpenseImpactPlan::build($expense, $exercise, $exercise, null, null, null, null, $project);
    $expense->update(['project_id' => $project->id, 'direct_cost_center_id' => null]);
    $leave = ExpenseImpactPlan::build($expense->refresh(), $exercise, $exercise, null, $direct->id, null, $project, null);

    expect($enter->sourceCostCenterId)->toBe($direct->id)
        ->and($enter->targetCostCenterId)->toBe($inherited->id)
        ->and($leave->sourceCostCenterId)->toBe($inherited->id)
        ->and($leave->targetCostCenterId)->toBe($direct->id);
});
