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
    Filament::setTenant(($company)->tenantCompany);

    Livewire::test(ViewExpense::class, ['record' => $expense->getRouteKey()])
        ->mountAction('moveOrReclassify')
        ->assertSchemaComponentExists('project_id')
        ->assertSchemaComponentExists('impact_confirmed')
        ->assertSchemaComponentExists('contract_id')
        ->assertSchemaComponentDoesNotExist('contract_owner_id')
        ->assertSchemaComponentDoesNotExist('proposal_id')
        ->assertSchemaComponentDoesNotExist('budget_id')
        ->assertSchemaComponentDoesNotExist('carryover')
        ->assertSchemaComponentDoesNotExist('reprogramming')
        ->assertSchemaComponentDoesNotExist('cost_center_percentage')
        ->assertSchemaComponentDoesNotExist('attachment');

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

it('hides unnecessary declarations and allows inline Supplier creation in the move form', function () {
    $manager = User::factory()->create();
    $company = Company::factory()->create();
    foreach ([Capability::View, Capability::ManageOperations, Capability::ManageMasterData] as $capability) {
        CompanyCapability::query()->create(['company_id' => $company->id, 'user_id' => $manager->id, 'capability' => $capability]);
    }
    $exercise = Exercise::factory()->for($company)->create();
    $project = Project::factory()->for($company)->create(['initial_state' => ProjectState::Open]);
    ProjectExerciseClassification::factory()->forProjectAndExercise($project, $exercise)->create();
    $expense = Expense::factory()->forExercise($exercise)->for($project)->create(['direct_cost_center_id' => null]);
    ExpenseLine::factory()->actual()->for($expense)->create();
    $this->actingAs($manager);
    Filament::setTenant(($company)->tenantCompany);

    Livewire::test(ViewExpense::class, ['record' => $expense->getRouteKey()])
        ->mountAction('moveOrReclassify')
        ->assertSchemaComponentExists('reason')
        ->assertSchemaComponentHidden('actual_kind')
        ->assertSchemaComponentHidden('open_project')
        ->assertSchemaComponentHidden('activity_note')
        ->assertSchemaComponentHidden('overspend_note')
        ->assertFormComponentActionVisible('supplier_id', 'createOption', formName: 'mountedActionSchema0');
});

it('reveals only declarations required by the selected Project destination', function () {
    $manager = User::factory()->create();
    $company = Company::factory()->create(['overspend_note_required' => true]);
    foreach ([Capability::View, Capability::ManageOperations] as $capability) {
        CompanyCapability::query()->create(['company_id' => $company->id, 'user_id' => $manager->id, 'capability' => $capability]);
    }
    $exercise = Exercise::factory()->for($company)->create();
    $plannedProject = Project::factory()->for($company)->create(['initial_state' => ProjectState::Planned]);
    $openProject = Project::factory()->for($company)->create(['initial_state' => ProjectState::Open]);
    ProjectExerciseClassification::factory()->forProjectAndExercise($plannedProject, $exercise)->create();
    ProjectExerciseClassification::factory()->forProjectAndExercise($openProject, $exercise)->create();
    $expense = Expense::factory()->forExercise($exercise)->create();
    ExpenseLine::factory()->for($expense)->create(['amount' => '10.00']);
    ExpenseLine::factory()->actual()->for($expense)->create(['amount' => '20.00']);
    $this->actingAs($manager);
    Filament::setTenant(($company)->tenantCompany);

    $component = Livewire::test(ViewExpense::class, ['record' => $expense->getRouteKey()])
        ->mountAction('moveOrReclassify')
        ->fillForm(['project_id' => $plannedProject->id])
        ->assertSchemaComponentVisible('actual_kind')
        ->assertSchemaComponentVisible('open_project')
        ->assertSchemaComponentHidden('activity_note')
        ->assertSchemaComponentHidden('overspend_note');

    $component
        ->fillForm(['project_id' => $plannedProject->id, 'actual_kind' => 'late'])
        ->assertSchemaComponentHidden('open_project')
        ->assertSchemaComponentVisible('activity_note');

    $component
        ->fillForm(['project_id' => $openProject->id, 'actual_kind' => 'ordinary', 'reason' => 'Nuova attribuzione'])
        ->assertSchemaComponentHidden('open_project')
        ->assertSchemaComponentHidden('activity_note')
        ->assertSchemaComponentVisible('overspend_note');
});
