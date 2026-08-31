<?php

use App\Filament\Pages\CompanyAudit;
use App\Filament\Resources\Expenses\Pages\EditExpense;
use App\Filament\Resources\Expenses\Pages\ViewExpense;
use App\Livewire\ExpenseDetail;
use App\Models\Company;
use App\Models\Exercise;
use App\Models\Expense;
use App\Models\ExpenseLine;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\Support\TestPermissions;

uses(RefreshDatabase::class);

it('exposes the complete Line edit form separately from impact-confirmed classification', function () {
    $manager = User::factory()->create();
    $company = Company::factory()->create();
    foreach ([TestPermissions::VIEW, TestPermissions::MANAGE_OPERATIONS] as $capability) {
        grantTestPermissions(['company_id' => $company->id, 'user' => $manager, 'permissions' => $capability]);
    }
    $exercise = Exercise::factory()->for($company)->create(['year' => now($company->timezone)->year]);
    $expense = Expense::factory()->forExercise($exercise)->create();
    ExpenseLine::factory()->for($expense)->create();
    $this->actingAs($manager);
    Filament::setTenant(($company)->tenantCompany);

    Livewire::test(ViewExpense::class, ['record' => $expense->getRouteKey()])
        ->assertSuccessful()
        ->assertActionExists('moveOrReclassify');

    $detail = Livewire::test(ExpenseDetail::class, ['expenseId' => $expense->id, 'compact' => false])
        ->assertSee('Vedi Timeline completa');

    expect($detail->instance()->timelineUrl())->toBe(CompanyAudit::getUrl([
        'tenant' => $company,
        'expense' => $expense->id,
    ]));

    Livewire::test(EditExpense::class, ['record' => $expense->getRouteKey()])
        ->assertFormFieldExists('description')
        ->assertFormFieldExists('notes')
        ->assertFormFieldExists('container')
        ->assertFormFieldExists('supplier_id')
        ->assertFormFieldExists('direct_cost_center_id')
        ->assertFormFieldExists('lines')
        ->assertFormFieldDoesNotExist('exercise_id')
        ->assertSee('Sposta o riclassifica');
});

it('hides every expense mutation from a read-only viewer', function () {
    $viewer = User::factory()->create();
    $company = Company::factory()->create();
    grantTestPermissions([
        'company_id' => $company->id,
        'user' => $viewer,
        'permissions' => TestPermissions::VIEW,
    ]);
    $exercise = Exercise::factory()->for($company)->create();
    $expense = Expense::factory()->forExercise($exercise)->create();
    ExpenseLine::factory()->for($expense)->create();
    $this->actingAs($viewer);
    Filament::setTenant(($company)->tenantCompany);

    Livewire::test(ViewExpense::class, ['record' => $expense->getRouteKey()])
        ->assertSuccessful()
        ->assertActionHidden('edit')
        ->assertActionHidden('moveOrReclassify')
        ->assertActionHidden('reverse')
        ->assertActionHidden('restore');

    Livewire::test(ExpenseDetail::class, ['expenseId' => $expense->id, 'compact' => false])
        ->assertSee('Vedi Timeline completa');
});
