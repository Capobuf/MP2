<?php

use App\Actions\Operations\CreateExercise;
use App\Models\Company;
use App\Models\CostCenter;
use App\Models\Exercise;
use App\Models\Expense;
use App\Models\ExpenseLine;
use App\Models\Project;
use App\Models\ProjectExerciseClassification;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\Support\TestPermissions;

uses(RefreshDatabase::class);

it('initializes every Project from its latest known classification including archived history without economics', function () {
    $actor = User::factory()->create();
    $company = Company::factory()->create();
    foreach ([TestPermissions::VIEW, TestPermissions::MANAGE_OPERATIONS] as $capability) {
        grantTestPermissions(['company_id' => $company->id, 'user' => $actor, 'permissions' => $capability]);
    }
    $oldExercise = Exercise::factory()->for($company)->create(['year' => 2025]);
    $latestExercise = Exercise::factory()->for($company)->create(['year' => 2026]);
    $archived = CostCenter::factory()->for($company)->archived()->create();
    $classified = Project::factory()->for($company)->create();
    $unclassified = Project::factory()->for($company)->create();
    ProjectExerciseClassification::factory()->forProjectAndExercise($classified, $oldExercise)->create();
    ProjectExerciseClassification::factory()->forProjectAndExercise($classified, $latestExercise)->create(['cost_center_id' => $archived->id]);

    $exercise = app(CreateExercise::class)->execute($actor, $company, ['year' => 2027], (string) Str::uuid());
    $rows = ProjectExerciseClassification::query()->where('exercise_id', $exercise->id)->orderBy('project_id')->get();

    expect($rows)->toHaveCount(2)
        ->and($rows->firstWhere('project_id', $classified->id)?->cost_center_id)->toBe($archived->id)
        ->and($rows->firstWhere('project_id', $unclassified->id)?->cost_center_id)->toBeNull()
        ->and(Expense::query()->count())->toBe(0)
        ->and(ExpenseLine::query()->count())->toBe(0)
        ->and($classified->refresh()->revision)->toBe(1)
        ->and($unclassified->refresh()->revision)->toBe(1)
        ->and($exercise->revision)->toBe(1);
});
