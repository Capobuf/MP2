<?php

use App\Domain\Company\Capability;
use App\Filament\Resources\Expenses\Pages\EditExpense;
use App\Filament\Resources\Expenses\Pages\ViewExpense;
use App\Models\Company;
use App\Models\CompanyCapability;
use App\Models\Exercise;
use App\Models\Expense;
use App\Models\ExpenseLine;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

it('exposes separate descriptive edit and impact-confirmed classification action', function () {
    $manager = User::factory()->create();
    $company = Company::factory()->create();
    foreach ([Capability::View, Capability::ManageOperations] as $capability) {
        CompanyCapability::query()->create(['company_id' => $company->id, 'user_id' => $manager->id, 'capability' => $capability]);
    }
    $exercise = Exercise::factory()->for($company)->create(['year' => now($company->timezone)->year]);
    $expense = Expense::factory()->forExercise($exercise)->create();
    ExpenseLine::factory()->for($expense)->create();
    $this->actingAs($manager);
    Filament::setTenant($company);

    Livewire::test(ViewExpense::class, ['record' => $expense->getRouteKey()])
        ->assertSuccessful()
        ->assertActionExists('timeline')
        ->assertActionExists('moveOrReclassify');

    Livewire::test(EditExpense::class, ['record' => $expense->getRouteKey()])
        ->assertFormFieldExists('description')
        ->assertFormFieldExists('notes')
        ->assertFormFieldDoesNotExist('exercise_id')
        ->assertFormFieldDoesNotExist('supplier_id')
        ->assertFormFieldDoesNotExist('direct_cost_center_id');
});

it('hides every expense mutation from a read-only viewer', function () {
    $viewer = User::factory()->create();
    $company = Company::factory()->create();
    CompanyCapability::query()->create([
        'company_id' => $company->id,
        'user_id' => $viewer->id,
        'capability' => Capability::View,
    ]);
    $exercise = Exercise::factory()->for($company)->create();
    $expense = Expense::factory()->forExercise($exercise)->create();
    ExpenseLine::factory()->for($expense)->create();
    $this->actingAs($viewer);
    Filament::setTenant($company);

    Livewire::test(ViewExpense::class, ['record' => $expense->getRouteKey()])
        ->assertSuccessful()
        ->assertActionVisible('timeline')
        ->assertActionHidden('edit')
        ->assertActionHidden('moveOrReclassify')
        ->assertActionHidden('reverse')
        ->assertActionHidden('restore');
});
