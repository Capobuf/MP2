<?php

use App\Domain\Company\Capability;
use App\Filament\Resources\Contracts\ContractResource;
use App\Filament\Resources\Contracts\Pages\ViewContract;
use App\Filament\Resources\Contracts\RelationManagers\ContractExpensesRelationManager;
use App\Filament\Resources\Expenses\Pages\CreateExpense;
use App\Filament\Resources\Expenses\Pages\ViewExpense;
use App\Filament\Resources\Expenses\RelationManagers\ExpenseLinesRelationManager;
use App\Models\Company;
use App\Models\CompanyCapability;
use App\Models\Contract;
use App\Models\Exercise;
use App\Models\Expense;
use App\Models\ExpenseLine;
use App\Models\User;
use App\Support\ExerciseContext;
use Filament\Facades\Filament;
use Filament\Resources\RelationManagers\RelationGroup;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

it('shows Contract Actuals and creation only to operators while system Estimates remain read only', function () {
    $manager = User::factory()->create();
    $company = Company::factory()->create();
    foreach ([Capability::View, Capability::ManageOperations] as $capability) {
        CompanyCapability::query()->create(['company_id' => $company->id, 'user_id' => $manager->id, 'capability' => $capability]);
    }
    $exercise = Exercise::factory()->for($company)->create();
    $contract = Contract::factory()->for($company)->create();
    $actual = Expense::factory()->forExercise($exercise)->create(['contract_id' => $contract->id, 'supplier_id' => $contract->supplier_id]);
    ExpenseLine::factory()->actual()->for($actual)->create();
    $estimate = Expense::factory()->forExercise($exercise)->create(['contract_id' => $contract->id, 'supplier_id' => $contract->supplier_id, 'origin' => 'system']);
    ExpenseLine::factory()->for($estimate)->create();
    $this->actingAs($manager);
    Filament::setTenant($company);

    Livewire::test(ContractExpensesRelationManager::class, ['ownerRecord' => $contract, 'pageClass' => ViewContract::class])
        ->assertCanSeeTableRecords([$actual, $estimate])
        ->assertTableActionVisible('createContractActual')
        ->assertTableActionDoesNotExist('edit', record: $estimate)
        ->assertTableActionDoesNotExist('delete', record: $actual);

    expect(collect(ContractResource::getRelations())->contains(
        fn (mixed $relation): bool => $relation === ContractExpensesRelationManager::class
            || ($relation instanceof RelationGroup && in_array(ContractExpensesRelationManager::class, $relation->getManagers(), true)),
    ))->toBeTrue();

    app(ExerciseContext::class)->select($company, $exercise->id);

    Livewire::withQueryParams(['contract' => $contract->id])->test(CreateExpense::class)
        ->assertFormSet(['container' => 'contract', 'contract_id' => $contract->id])
        ->assertFormFieldDoesNotExist('exercise_id')
        ->assertFormFieldDoesNotExist('supplier_id')
        ->assertFormFieldDoesNotExist('direct_cost_center_id');
});

it('exposes Contract declarations on manual Lines and no mutation on generated Estimate Lines', function () {
    $manager = User::factory()->create();
    $company = Company::factory()->create();
    foreach ([Capability::View, Capability::ManageOperations] as $capability) {
        CompanyCapability::query()->create(['company_id' => $company->id, 'user_id' => $manager->id, 'capability' => $capability]);
    }
    $exercise = Exercise::factory()->for($company)->create();
    $contract = Contract::factory()->for($company)->create();
    $actual = Expense::factory()->forExercise($exercise)->create(['contract_id' => $contract->id, 'supplier_id' => $contract->supplier_id]);
    $actualLine = ExpenseLine::factory()->actual()->for($actual)->create();
    $estimate = Expense::factory()->forExercise($exercise)->create(['contract_id' => $contract->id, 'supplier_id' => $contract->supplier_id, 'origin' => 'system']);
    $estimateLine = ExpenseLine::factory()->for($estimate)->create();
    $this->actingAs($manager);
    Filament::setTenant($company);

    Livewire::test(ExpenseLinesRelationManager::class, ['ownerRecord' => $actual, 'pageClass' => ViewExpense::class])
        ->mountTableAction('create')
        ->assertSchemaComponentExists('actual_kind')
        ->assertSchemaComponentExists('activity_note')
        ->assertTableActionDoesNotExist('delete', record: $actualLine);

    Livewire::test(ExpenseLinesRelationManager::class, ['ownerRecord' => $estimate, 'pageClass' => ViewExpense::class])
        ->assertTableActionHidden('create')
        ->assertTableActionHidden('edit', record: $estimateLine)
        ->assertTableActionDoesNotExist('delete', record: $estimateLine);
});

it('hides Contract Actual creation from a read-only viewer', function () {
    $viewer = User::factory()->create();
    $company = Company::factory()->create();
    CompanyCapability::query()->create(['company_id' => $company->id, 'user_id' => $viewer->id, 'capability' => Capability::View]);
    $contract = Contract::factory()->for($company)->create();
    $this->actingAs($viewer);
    Filament::setTenant($company);

    Livewire::test(ContractExpensesRelationManager::class, ['ownerRecord' => $contract, 'pageClass' => ViewContract::class])
        ->assertTableActionHidden('createContractActual');
});
