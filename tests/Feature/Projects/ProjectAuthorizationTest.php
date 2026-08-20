<?php

use App\Domain\Company\Capability;
use App\Models\Company;
use App\Models\CompanyCapability;
use App\Models\Exercise;
use App\Models\Project;
use App\Models\ProjectExerciseClassification;
use App\Models\ProjectTransition;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('maps S4 reads and mutations to the exact company capabilities', function () {
    $user = User::factory()->create();
    $companyA = Company::factory()->create();
    $companyB = Company::factory()->create();
    foreach ([Capability::View, Capability::ManageOperations] as $capability) {
        CompanyCapability::query()->create([
            'company_id' => $companyA->id,
            'user_id' => $user->id,
            'capability' => $capability,
        ]);
    }
    $projectA = Project::factory()->for($companyA)->create();
    $transitionA = ProjectTransition::factory()->forProject($projectA)->create([
        'created_by_id' => $user->id,
    ]);
    $exerciseA = Exercise::factory()->for($companyA)->create();
    $classificationA = ProjectExerciseClassification::factory()
        ->forProjectAndExercise($projectA, $exerciseA)
        ->create();
    $projectB = Project::factory()->for($companyB)->create();

    expect($user->can('view', $projectA))->toBeTrue()
        ->and($user->can('update', $projectA))->toBeTrue()
        ->and($user->can('update', $transitionA))->toBeTrue()
        ->and($user->can('update', $classificationA))->toBeTrue()
        ->and($user->can('view', $projectB))->toBeFalse()
        ->and($user->can('update', $projectB))->toBeFalse()
        ->and($user->can('delete', $projectA))->toBeFalse()
        ->and($user->can('delete', $transitionA))->toBeFalse()
        ->and($user->can('delete', $classificationA))->toBeFalse();
});
