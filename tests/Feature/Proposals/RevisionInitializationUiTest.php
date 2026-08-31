<?php

use App\Filament\Resources\Exercises\Pages\ViewExercise;
use App\Filament\Resources\Proposals\Pages\ViewProposal;
use App\Models\BudgetSnapshot;
use App\Models\Company;
use App\Models\Exercise;
use App\Models\Proposal;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Tests\Support\TestPermissions;

uses(RefreshDatabase::class);

function uiApprovedBudget(Company $company, Exercise $exercise, User $approver): BudgetSnapshot
{
    $proposal = Proposal::factory()->for($company)->for($exercise)->for($approver, 'creator')->create();
    $proposal->update(['status' => 'approved', 'approved_by_id' => $approver->id, 'approved_at' => now(), 'approval_operation_id' => (string) Str::uuid()]);

    return BudgetSnapshot::factory()->for($proposal)->create(['company_id' => $company->id, 'exercise_id' => $exercise->id, 'approved_by_id' => $approver->id]);
}

it('creates a revision from an open exercise and shows live versus budget context', function (): void {
    $company = Company::factory()->create();
    $exercise = Exercise::factory()->for($company)->create();
    $user = User::factory()->create();
    foreach ([TestPermissions::VIEW, TestPermissions::MANAGE_PROPOSALS] as $capability) {
        grantTestPermissions(['company_id' => $company->id, 'user' => $user, 'permissions' => $capability]);
    }
    uiApprovedBudget($company, $exercise, $user);
    $this->actingAs($user);
    Filament::setTenant(($company)->tenantCompany);

    Livewire::test(ViewExercise::class, ['record' => $exercise->getRouteKey()])
        ->assertActionExists('initializeProposal')
        ->assertActionEnabled('initializeProposal')
        ->callAction('initializeProposal')
        ->assertHasNoActionErrors();

    $revision = Proposal::query()->where('status', 'draft')->sole();
    Livewire::test(ViewProposal::class, ['record' => $revision->getRouteKey()])
        ->assertSuccessful()
        ->assertSee('Revisione')
        ->assertSee('Budget di riferimento')
        ->assertSee('realtà corrente');
});

it('disables revision creation for a closed exercise or occupied draft', function (): void {
    $company = Company::factory()->create();
    $closed = Exercise::factory()->for($company)->create(['status' => 'closed']);
    $user = User::factory()->create();
    foreach ([TestPermissions::VIEW, TestPermissions::MANAGE_PROPOSALS] as $capability) {
        grantTestPermissions(['company_id' => $company->id, 'user' => $user, 'permissions' => $capability]);
    }
    uiApprovedBudget($company, $closed, $user);
    $this->actingAs($user);
    Filament::setTenant(($company)->tenantCompany);

    Livewire::test(ViewExercise::class, ['record' => $closed->getRouteKey()])
        ->assertActionDisabled('initializeProposal');
});
