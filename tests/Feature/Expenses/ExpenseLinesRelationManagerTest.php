<?php

use App\Filament\Resources\Expenses\Pages\ViewExpense;
use App\Filament\Resources\Expenses\RelationManagers\ExpenseLinesRelationManager;
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

it('manages lines explicitly without delete or destructive bulk actions', function () {
    $manager = User::factory()->create();
    $company = Company::factory()->create();
    foreach ([TestPermissions::VIEW, TestPermissions::MANAGE_OPERATIONS] as $capability) {
        grantTestPermissions(['company_id' => $company->id, 'user' => $manager, 'permissions' => $capability]);
    }
    $exercise = Exercise::factory()->for($company)->create(['year' => now($company->timezone)->year]);
    $expense = Expense::factory()->forExercise($exercise)->create();
    $this->actingAs($manager);
    Filament::setTenant(($company)->tenantCompany);

    $component = Livewire::test(ExpenseLinesRelationManager::class, [
        'ownerRecord' => $expense,
        'pageClass' => ViewExpense::class,
    ])->callTableAction('create', data: [
        'type' => 'estimate',
        'amount' => '100.00',
        'quantity' => null,
        'unit_amount' => null,
        'unit_of_measure' => null,
        'note' => null,
        'amount_warning_acknowledged' => false,
    ])->assertHasNoTableActionErrors();

    $line = ExpenseLine::query()->sole();
    $component->assertTableActionDoesNotExist('delete', record: $line)
        ->callTableAction('annul', record: $line)
        ->assertHasNoTableActionErrors();
    expect($line->refresh()->isAnnulled())->toBeTrue();
    $component->callTableAction('restore', record: $line)->assertHasNoTableActionErrors();
    expect($line->refresh()->isAnnulled())->toBeFalse();
});

it('hides all line mutations for a reversed expense', function () {
    $manager = User::factory()->create();
    $company = Company::factory()->create();
    foreach ([TestPermissions::VIEW, TestPermissions::MANAGE_OPERATIONS] as $capability) {
        grantTestPermissions(['company_id' => $company->id, 'user' => $manager, 'permissions' => $capability]);
    }
    $exercise = Exercise::factory()->for($company)->create();
    $expense = Expense::factory()->forExercise($exercise)->reversed()->create();
    $line = ExpenseLine::factory()->for($expense)->create();
    $this->actingAs($manager);
    Filament::setTenant(($company)->tenantCompany);

    Livewire::test(ExpenseLinesRelationManager::class, ['ownerRecord' => $expense, 'pageClass' => ViewExpense::class])
        ->assertTableActionHidden('create')
        ->assertTableActionHidden('edit', record: $line)
        ->assertTableActionHidden('annul', record: $line)
        ->assertTableActionDoesNotExist('delete', record: $line);
});
