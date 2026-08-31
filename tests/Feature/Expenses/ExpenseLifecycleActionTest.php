<?php

use App\Filament\Resources\Expenses\Pages\ListExpenses;
use App\Filament\Resources\Expenses\Pages\ViewExpense;
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

it('shows mutually exclusive lifecycle actions and disables storno with actuals', function () {
    $manager = User::factory()->create();
    $company = Company::factory()->create();
    foreach ([TestPermissions::VIEW, TestPermissions::MANAGE_OPERATIONS] as $capability) {
        grantTestPermissions(['company_id' => $company->id, 'user' => $manager, 'permissions' => $capability]);
    }
    $exercise = Exercise::factory()->for($company)->create();
    $active = Expense::factory()->forExercise($exercise)->create();
    $reversed = Expense::factory()->forExercise($exercise)->reversed()->create();
    $withActual = Expense::factory()->forExercise($exercise)->create();
    ExpenseLine::factory()->for($active)->create();
    ExpenseLine::factory()->for($reversed)->create();
    ExpenseLine::factory()->for($withActual)->actual()->create();
    $this->actingAs($manager);
    Filament::setTenant(($company)->tenantCompany);

    Livewire::test(ListExpenses::class)
        ->assertTableActionVisible('reverse', record: $active)
        ->assertTableActionHidden('restore', record: $active)
        ->assertTableActionHidden('reverse', record: $reversed)
        ->assertTableActionVisible('restore', record: $reversed)
        ->assertTableActionDisabled('reverse', record: $withActual)
        ->assertTableActionDoesNotExist('delete', record: $active);

    Livewire::test(ViewExpense::class, ['record' => $active->getRouteKey()])
        ->assertActionExists('reverse')
        ->assertActionExists('restore');
});
