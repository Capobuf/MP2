<?php

use App\Domain\Company\Capability;
use App\Filament\Resources\Expenses\ExpenseResource;
use App\Filament\Resources\Expenses\Pages\CreateExpense;
use App\Filament\Resources\Expenses\Pages\ListExpenses;
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

function grantExpenseResource(User $user, Company $company, bool $manage = true): void
{
    foreach ($manage ? [Capability::View, Capability::ManageOperations] : [Capability::View] as $capability) {
        CompanyCapability::query()->create([
            'company_id' => $company->id,
            'user_id' => $user->id,
            'capability' => $capability,
        ]);
    }
}

it('creates an expense with an initial line and no future fields', function () {
    $manager = User::factory()->create();
    $company = Company::factory()->create();
    grantExpenseResource($manager, $company);
    $exercise = Exercise::factory()->for($company)->create(['year' => now($company->timezone)->year]);
    $this->actingAs($manager);
    Filament::setTenant($company);

    Livewire::test(CreateExpense::class)
        ->assertFormFieldDoesNotExist('project_id')
        ->assertFormFieldDoesNotExist('contract_id')
        ->assertFormFieldDoesNotExist('budget_id')
        ->assertFormFieldDoesNotExist('proposal_id')
        ->assertFormFieldDoesNotExist('forecast')
        ->assertFormFieldDoesNotExist('preventivo')
        ->assertFormFieldDoesNotExist('plafond')
        ->assertFormFieldDoesNotExist('attachment')
        ->assertFormFieldDoesNotExist('currency')
        ->assertFormFieldDoesNotExist('vat')
        ->fillForm([
            'exercise_id' => $exercise->id,
            'description' => 'Licenze',
            'lines' => [['type' => 'estimate', 'amount' => '100.00']],
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    expect(Expense::query()->count())->toBe(1)
        ->and(ExpenseLine::query()->count())->toBe(1);
});

it('lists and resolves expenses only inside the current tenant', function () {
    $viewer = User::factory()->create();
    $companyA = Company::factory()->create();
    $companyB = Company::factory()->create();
    grantExpenseResource($viewer, $companyA, false);
    $expenseA = Expense::factory()->forExercise(Exercise::factory()->for($companyA)->create())->create();
    $expenseB = Expense::factory()->forExercise(Exercise::factory()->for($companyB)->create())->create();
    ExpenseLine::factory()->for($expenseA)->create();
    ExpenseLine::factory()->for($expenseB)->create();
    $this->actingAs($viewer);
    Filament::setTenant($companyA);

    Livewire::test(ListExpenses::class)
        ->assertCanSeeTableRecords([$expenseA])
        ->assertCanNotSeeTableRecords([$expenseB])
        ->assertTableActionDoesNotExist('delete', record: $expenseA);
    Livewire::test(ViewExpense::class, ['record' => $expenseA->getRouteKey()])->assertSuccessful();
    $this->get(ExpenseResource::getUrl('view', ['record' => $expenseB], tenant: $companyA))->assertNotFound();
});
