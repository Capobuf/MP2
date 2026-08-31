<?php

use App\Actions\Proposals\InitializeProposal;
use App\Actions\Proposals\ReviewProposalReadiness;
use App\Filament\Resources\Proposals\Pages\ViewProposal;
use App\Models\Company;
use App\Models\Exercise;
use App\Models\Expense;
use App\Models\ExpenseLine;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Tests\Support\TestPermissions;

uses(RefreshDatabase::class);

it('shows exactly the three realignment controls and action history for a stale source', function (): void {
    $company = Company::factory()->create();
    $exercise = Exercise::factory()->for($company)->create();
    $actor = User::factory()->create();
    grantTestPermissions(['company_id' => $company->id, 'user' => $actor, 'permissions' => TestPermissions::MANAGE_PROPOSALS]);
    $expense = Expense::factory()->forExercise($exercise)->create();
    $line = ExpenseLine::factory()->for($expense)->create(['type' => 'estimate', 'amount' => '5.00']);
    $proposal = app(InitializeProposal::class)->execute($actor, $company, $exercise, (string) Str::uuid());
    $line->update(['amount' => '6.00']);
    $proposal = app(ReviewProposalReadiness::class)->execute($actor, $proposal->refresh(), (string) Str::uuid());
    grantTestPermissions(['company_id' => $proposal->company_id, 'user' => $actor, 'permissions' => TestPermissions::VIEW]);
    $this->actingAs($actor);
    Filament::setTenant(($proposal->company)->tenantCompany);

    Livewire::test(ViewProposal::class, ['record' => $proposal->getRouteKey()])
        ->assertActionExists('reloadReality')
        ->assertActionExists('keepProposal')
        ->assertActionExists('manualRealignment')
        ->assertSee('Da riallineare')
        ->assertSee('Storico decisioni');
});
