<?php

use App\Domain\Company\Capability;
use App\Domain\Projects\ProjectState;
use App\Filament\Resources\Expenses\Pages\ViewExpense;
use App\Models\Company;
use App\Models\CompanyCapability;
use App\Models\Exercise;
use App\Models\Expense;
use App\Models\ExpenseLine;
use App\Models\Project;
use App\Models\ProjectExerciseClassification;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

it('shows the S4 owner preview and moves the whole Expense without Contract controls', function () {
    $manager = User::factory()->create();
    $company = Company::factory()->create();
    foreach ([Capability::View, Capability::ManageOperations] as $capability) {
        CompanyCapability::query()->create(['company_id' => $company->id, 'user_id' => $manager->id, 'capability' => $capability]);
    }
    $exercise = Exercise::factory()->for($company)->create(['year' => now($company->timezone)->year]);
    $project = Project::factory()->for($company)->create(['initial_state' => ProjectState::Open]);
    ProjectExerciseClassification::factory()->forProjectAndExercise($project, $exercise)->create();
    $expense = Expense::factory()->forExercise($exercise)->create();
    $line = ExpenseLine::factory()->for($expense)->create(['amount' => '10.00']);
    $this->actingAs($manager);
    Filament::setTenant($company);

    Livewire::test(ViewExpense::class, ['record' => $expense->getRouteKey()])
        ->mountAction('moveOrReclassify')
        ->assertSchemaComponentExists('project_id')
        ->assertSchemaComponentExists('impact_confirmed')
        ->assertSchemaComponentDoesNotExist('contract_id');

    Livewire::test(ViewExpense::class, ['record' => $expense->getRouteKey()])
        ->callAction('moveOrReclassify', data: [
            'exercise_id' => $exercise->id,
            'project_id' => $project->id,
            'impact_confirmed' => true,
        ])
        ->assertHasNoActionErrors();

    expect($expense->refresh()->project_id)->toBe($project->id)
        ->and($line->refresh()->expense_id)->toBe($expense->id);
});

it('exposes declaration opening reason and overspend inputs in the move form', function () {
    $manager = User::factory()->create();
    $company = Company::factory()->create();
    foreach ([Capability::View, Capability::ManageOperations] as $capability) {
        CompanyCapability::query()->create(['company_id' => $company->id, 'user_id' => $manager->id, 'capability' => $capability]);
    }
    $exercise = Exercise::factory()->for($company)->create();
    $project = Project::factory()->for($company)->create(['initial_state' => ProjectState::Open]);
    ProjectExerciseClassification::factory()->forProjectAndExercise($project, $exercise)->create();
    $expense = Expense::factory()->forExercise($exercise)->for($project)->create(['direct_cost_center_id' => null]);
    $this->actingAs($manager);
    Filament::setTenant($company);

    Livewire::test(ViewExpense::class, ['record' => $expense->getRouteKey()])
        ->mountAction('moveOrReclassify')
        ->assertSchemaComponentExists('reason')
        ->assertSchemaComponentExists('actual_kind')
        ->assertSchemaComponentExists('open_project')
        ->assertSchemaComponentExists('overspend_note')
        ->assertSchemaComponentDoesNotExist('contract_id');
});
