<?php

use App\Filament\Resources\Projects\Pages\ViewProject;
use App\Models\Company;
use App\Models\CostCenter;
use App\Models\Exercise;
use App\Models\Expense;
use App\Models\ExpenseLine;
use App\Models\Project;
use App\Models\ProjectExerciseClassification;
use App\Models\User;
use Filament\Facades\Filament;
use Filament\Forms\Components\Placeholder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\Support\TestPermissions;

uses(RefreshDatabase::class);

it('confirms an annual classification through the Project preview action', function () {
    $manager = User::factory()->create();
    $company = Company::factory()->create();
    foreach ([TestPermissions::VIEW, TestPermissions::MANAGE_OPERATIONS] as $capability) {
        grantTestPermissions(['company_id' => $company->id, 'user' => $manager, 'permissions' => $capability]);
    }
    $exercise = Exercise::factory()->for($company)->create();
    $project = Project::factory()->for($company)->create();
    $classification = ProjectExerciseClassification::factory()->forProjectAndExercise($project, $exercise)->create();
    $target = CostCenter::factory()->for($company)->create();
    $this->actingAs($manager);
    Filament::setTenant(($company)->tenantCompany);

    Livewire::test(ViewProject::class, ['record' => $project->getRouteKey()])
        ->assertActionVisible('reclassify')
        ->callAction('reclassify', data: ['exercise_id' => $exercise->id, 'cost_center_id' => $target->id, 'impact_confirmed' => true])
        ->assertHasNoActionErrors();

    expect($classification->refresh()->cost_center_id)->toBe($target->id);
});

it('shows the reclassification note only when Actuals are affected', function () {
    $manager = User::factory()->create();
    $company = Company::factory()->create();
    foreach ([TestPermissions::VIEW, TestPermissions::MANAGE_OPERATIONS] as $capability) {
        grantTestPermissions(['company_id' => $company->id, 'user' => $manager, 'permissions' => $capability]);
    }
    $exercise = Exercise::factory()->for($company)->create();
    $project = Project::factory()->for($company)->create();
    ProjectExerciseClassification::factory()->forProjectAndExercise($project, $exercise)->create();
    $this->actingAs($manager);
    Filament::setTenant(($company)->tenantCompany);

    $component = Livewire::test(ViewProject::class, ['record' => $project->getRouteKey()])
        ->mountAction('reclassify')
        ->fillForm(['exercise_id' => $exercise->id])
        ->assertSchemaComponentHidden('reason');

    $expense = Expense::factory()->forExercise($exercise)->for($project)->create(['direct_cost_center_id' => null]);
    ExpenseLine::factory()->actual()->for($expense)->create(['amount' => '10.00']);

    Livewire::test(ViewProject::class, ['record' => $project->getRouteKey()])
        ->mountAction('reclassify')
        ->fillForm(['exercise_id' => $exercise->id])
        ->assertSchemaComponentVisible('reason');
});

it('shows a structured human-readable impact and resets confirmation when preview parameters change', function () {
    $manager = User::factory()->create();
    $company = Company::factory()->create();
    foreach ([TestPermissions::VIEW, TestPermissions::MANAGE_OPERATIONS] as $capability) {
        grantTestPermissions(['company_id' => $company->id, 'user' => $manager, 'permissions' => $capability]);
    }
    $exercise = Exercise::factory()->for($company)->create();
    $otherExercise = Exercise::factory()->for($company)->create();
    $project = Project::factory()->for($company)->create();
    $source = CostCenter::factory()->for($company)->create(['name' => 'Software']);
    $target = CostCenter::factory()->for($company)->create(['name' => 'Hardware']);
    ProjectExerciseClassification::factory()->forProjectAndExercise($project, $exercise)->create(['cost_center_id' => $source->id]);
    $expense = Expense::factory()->forExercise($exercise)->for($project)->create(['direct_cost_center_id' => null]);
    ExpenseLine::factory()->for($expense)->create(['amount' => '1500.00']);
    ExpenseLine::factory()->actual()->for($expense)->create(['amount' => '400.00']);
    $this->actingAs($manager);
    Filament::setTenant(($company)->tenantCompany);

    $component = Livewire::test(ViewProject::class, ['record' => $project->getRouteKey()])
        ->mountAction('reclassify')
        ->fillForm([
            'exercise_id' => $exercise->id,
            'cost_center_id' => $target->id,
            'impact_confirmed' => true,
        ])
        ->assertSchemaComponentExists('impact_preview', checkComponentUsing: function (Placeholder $component) use ($expense): bool {
            $html = $component->getContent()->render();

            expect($html)
                ->toContain('Riclassificazione')
                ->toContain('Centro di Costo · Prima')
                ->toContain('Software')
                ->toContain('Centro di Costo · Dopo')
                ->toContain('Hardware')
                ->toContain('Spese Coinvolte')
                ->toContain('1.500,00')
                ->toContain('400,00')
                ->not->toContain('expense:'.$expense->id);

            return true;
        })
        ->assertActionDataSet(['impact_confirmed' => true]);

    $component
        ->fillForm(['cost_center_id' => $source->id])
        ->assertActionDataSet(['impact_confirmed' => false])
        ->fillForm(['impact_confirmed' => true])
        ->fillForm(['exercise_id' => $otherExercise->id])
        ->assertActionDataSet(['impact_confirmed' => false]);
});
