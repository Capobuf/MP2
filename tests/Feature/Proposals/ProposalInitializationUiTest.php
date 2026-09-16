<?php

use App\Filament\Resources\Exercises\Pages\ViewExercise;
use App\Filament\Resources\Proposals\Pages\ListProposals;
use App\Filament\Resources\Proposals\Pages\ViewProposal;
use App\Filament\Resources\Proposals\ProposalResource;
use App\Models\Company;
use App\Models\Exercise;
use App\Models\Expense;
use App\Models\ExpenseLine;
use App\Models\Proposal;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\Support\TestPermissions;

uses(RefreshDatabase::class);

it('initializes from the exercise and keeps proposal lists tenant scoped', function (): void {
    $user = User::factory()->create();
    $company = Company::factory()->create();
    foreach ([TestPermissions::VIEW, TestPermissions::MANAGE_PROPOSALS] as $capability) {
        grantTestPermissions(['company_id' => $company->id, 'user' => $user, 'permissions' => $capability]);
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
        ->assertSee('Piano Risultante')
        ->assertSee('Realtà effettiva in sola lettura')
        ->assertSee('7,00')
        ->assertDontSee('estimate_lines')
        ->assertDontSee('actual_context');
});

it('opens proposals with global platform authors through tenant HTTP middleware', function (string $status): void {
    $company = Company::factory()->create();
    $creator = User::factory()->platformAdmin()->create(['name' => 'Global proposal creator']);
    $terminalActor = User::factory()->platformAdmin()->create(['name' => 'Global terminal actor']);
    $viewer = User::factory()->create();
    grantTestPermissions(['company_id' => $company->id, 'user' => $viewer, 'permissions' => TestPermissions::VIEW]);
    $proposal = Proposal::factory()->for($company)->create([
        'status' => $status,
        'created_by_id' => $creator->id,
        'approved_by_id' => $status === 'approved' ? $terminalActor->id : null,
        'approved_at' => $status === 'approved' ? now() : null,
        'discarded_by_id' => $status === 'discarded' ? $terminalActor->id : null,
        'discarded_at' => $status === 'discarded' ? now() : null,
    ]);
    $otherCompany = Company::factory()->create();
    $otherProposal = Proposal::factory()->for($otherCompany)->create(['created_by_id' => $creator->id]);

    $this->actingAs($viewer);
    $response = $this->get(ProposalResource::getUrl('view', ['record' => $proposal], tenant: $company->tenantCompany))
        ->assertSuccessful()
        ->assertSee('Global proposal creator');
    if ($status !== 'draft') {
        $response->assertSee('Global terminal actor');
    }

    $this->get(ProposalResource::getUrl('view', ['record' => $otherProposal], tenant: $company->tenantCompany))
        ->assertNotFound();
})->with(['draft', 'approved', 'discarded']);
