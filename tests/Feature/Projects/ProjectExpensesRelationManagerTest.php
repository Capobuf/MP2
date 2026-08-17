<?php

use App\Domain\Company\Capability;
use App\Filament\Resources\Expenses\Pages\CreateExpense;
use App\Filament\Resources\Projects\Pages\ViewProject;
use App\Filament\Resources\Projects\RelationManagers\ProjectExpensesRelationManager;
use App\Models\Company;
use App\Models\CompanyCapability;
use App\Models\Exercise;
use App\Models\Expense;
use App\Models\Project;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

function grantProjectExpenseResource(User $user, Company $company, bool $manage = true): void
{
    foreach ($manage ? [Capability::View, Capability::ManageOperations] : [Capability::View] as $capability) {
        CompanyCapability::query()->create(['company_id' => $company->id, 'user_id' => $user->id, 'capability' => $capability]);
    }
}

it('shows only child Expenses and links to prefilled creation without future ownership fields', function () {
    $manager = User::factory()->create();
    $company = Company::factory()->create();
    grantProjectExpenseResource($manager, $company);
    $exercise = Exercise::factory()->for($company)->create(['year' => now($company->timezone)->year]);
    $project = Project::factory()->for($company)->create();
    $child = Expense::factory()->forExercise($exercise)->for($project)->create(['direct_cost_center_id' => null]);
    $autonomous = Expense::factory()->forExercise($exercise)->create();
    $this->actingAs($manager);
    Filament::setTenant($company);

    Livewire::test(ProjectExpensesRelationManager::class, [
        'ownerRecord' => $project,
        'pageClass' => ViewProject::class,
    ])->assertCanSeeTableRecords([$child])
        ->assertCanNotSeeTableRecords([$autonomous])
        ->assertTableActionVisible('createProjectExpense')
        ->assertTableActionDoesNotExist('delete', record: $child);

    Livewire::withQueryParams(['project' => $project->id])->test(CreateExpense::class)
        ->assertFormSet(['project_id' => $project->id])
        ->assertFormFieldExists('supplier_id')
        ->assertFormFieldExists('actual_kind')
        ->assertFormFieldDoesNotExist('contract_id')
        ->assertFormFieldDoesNotExist('budget_id');
});

it('hides Project Expense creation from read-only viewers', function () {
    $viewer = User::factory()->create();
    $company = Company::factory()->create();
    grantProjectExpenseResource($viewer, $company, false);
    $project = Project::factory()->for($company)->create();
    $this->actingAs($viewer);
    Filament::setTenant($company);

    Livewire::test(ProjectExpensesRelationManager::class, [
        'ownerRecord' => $project,
        'pageClass' => ViewProject::class,
    ])->assertTableActionHidden('createProjectExpense');
});
