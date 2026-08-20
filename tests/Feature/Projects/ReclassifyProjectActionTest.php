<?php

use App\Domain\Company\Capability;
use App\Filament\Resources\Projects\Pages\ViewProject;
use App\Models\Company;
use App\Models\CompanyCapability;
use App\Models\CostCenter;
use App\Models\Exercise;
use App\Models\Project;
use App\Models\ProjectExerciseClassification;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

it('confirms an annual classification through the Project preview action', function () {
    $manager = User::factory()->create();
    $company = Company::factory()->create();
    foreach ([Capability::View, Capability::ManageOperations] as $capability) {
        CompanyCapability::query()->create(['company_id' => $company->id, 'user_id' => $manager->id, 'capability' => $capability]);
    }
    $exercise = Exercise::factory()->for($company)->create();
    $project = Project::factory()->for($company)->create();
    $classification = ProjectExerciseClassification::factory()->forProjectAndExercise($project, $exercise)->create();
    $target = CostCenter::factory()->for($company)->create();
    $this->actingAs($manager);
    Filament::setTenant($company);

    Livewire::test(ViewProject::class, ['record' => $project->getRouteKey()])
        ->assertActionVisible('reclassify')
        ->callAction('reclassify', data: ['exercise_id' => $exercise->id, 'cost_center_id' => $target->id, 'impact_confirmed' => true])
        ->assertHasNoActionErrors();

    expect($classification->refresh()->cost_center_id)->toBe($target->id);
});
