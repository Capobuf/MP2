<?php

use App\Domain\Projects\ProjectClassificationImpactPlan;
use App\Models\Company;
use App\Models\Exercise;
use App\Models\Expense;
use App\Models\ExpenseLine;
use App\Models\Project;
use App\Models\ProjectExerciseClassification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

it('captures immutable exact annual classification impact and identity', function () {
    $company = Company::factory()->create();
    $exercise = Exercise::factory()->for($company)->create(['revision' => 3]);
    $project = Project::factory()->for($company)->create(['revision' => 4]);
    $classification = ProjectExerciseClassification::factory()->forProjectAndExercise($project, $exercise)->create();
    $expense = Expense::factory()->forExercise($exercise)->for($project)->create(['direct_cost_center_id' => null]);
    ExpenseLine::factory()->for($expense)->create(['type' => 'estimate', 'amount' => '12.30']);
    ExpenseLine::factory()->for($expense)->actual()->create(['amount' => '4.20']);

    $plan = ProjectClassificationImpactPlan::build($project, $exercise, 99);

    expect($plan->classificationId)->toBe($classification->id)
        ->and($plan->expenseIds)->toBe([$expense->id])
        ->and($plan->allocation)->toBe('12.30')
        ->and($plan->actual)->toBe('4.20')
        ->and($plan->projectRevision)->toBe(4)
        ->and($plan->exerciseRevision)->toBe(3)
        ->and($plan->fingerprint())->toHaveLength(64);
});
