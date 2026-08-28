<?php

use App\Domain\Company\Capability;
use App\Filament\Resources\Exercises\Pages\ViewExercise;
use App\Filament\Resources\Proposals\Pages\ListProposals;
use App\Filament\Resources\Proposals\Pages\ViewProposal;
use App\Models\Company;
use App\Models\CompanyCapability;
use App\Models\Exercise;
use App\Models\Expense;
use App\Models\ExpenseLine;
use App\Models\Proposal;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

it('initializes from the exercise and keeps proposal lists tenant scoped', function (): void {
    $user = User::factory()->create();
    $company = Company::factory()->create();
    foreach ([Capability::View, Capability::ManageProposals] as $capability) {
        CompanyCapability::query()->create(['company_id' => $company->id, 'user_id' => $user->id, 'capability' => $capability]);
    }
    $exercise = Exercise::factory()->for($company)->create();
    $expense = Expense::factory()->forExercise($exercise)->create();
    ExpenseLine::factory()->for($expense)->create(['type' => 'actual', 'amount' => '7.00']);
    $this->actingAs($user);
    Filament::setTenant(($company)->tenantCompany);

    Livewire::test(ViewExercise::class, ['record' => $exercise->getRouteKey()])
        ->assertActionExists('initializeProposal')
        ->callAction('initializeProposal')
        ->assertHasNoActionErrors();

    $proposal = Proposal::query()->sole();
    Livewire::test(ListProposals::class)->assertCanSeeTableRecords([$proposal]);
    Livewire::test(ViewProposal::class, ['record' => $proposal->getRouteKey()])
        ->assertSuccessful()
        ->assertSee('Confronto dell’Allocato')
        ->assertSee('Piano risultante')
        ->assertSee('Realtà effettiva in sola lettura')
        ->assertSee('7,00')
        ->assertDontSee('estimate_lines')
        ->assertDontSee('actual_context');
});
