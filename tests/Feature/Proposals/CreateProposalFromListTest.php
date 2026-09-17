<?php

use App\Domain\Proposals\ProposalPurpose;
use App\Filament\Resources\Proposals\Pages\ListProposals;
use App\Filament\Resources\Proposals\ProposalResource;
use App\Models\BudgetSnapshot;
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

beforeEach(function (): void {
    $this->company = Company::factory()->create();
    $this->user = User::factory()->create();
    foreach ([TestPermissions::VIEW, TestPermissions::MANAGE_PROPOSALS] as $capability) {
        grantTestPermissions(['company_id' => $this->company->id, 'user' => $this->user, 'permissions' => $capability]);
    }
    $this->actingAs($this->user);
    Filament::setTenant($this->company->tenantCompany);
});

it('creates an initial budget or revision from the list using canonical initialization', function (bool $withBudget): void {
    $exercise = Exercise::factory()->for($this->company)->create();
    $expense = Expense::factory()->forExercise($exercise)->create();
    $actual = ExpenseLine::factory()->for($expense)->create(['type' => 'actual', 'amount' => '7.00']);
    $budget = null;
    if ($withBudget) {
        $approved = Proposal::factory()->for($this->company)->for($exercise)->create([
            'status' => 'approved', 'created_by_id' => $this->user->id,
            'approved_by_id' => $this->user->id, 'approved_at' => now(),
        ]);
        $budget = BudgetSnapshot::factory()->for($approved)->create(['approved_by_id' => $this->user->id]);
    }

    $page = Livewire::test(ListProposals::class)
        ->assertActionVisible('initializeProposal')
        ->callAction('initializeProposal', ['exercise_id' => $exercise->id])
        ->assertHasNoActionErrors();

    $proposal = Proposal::query()->where('status', 'draft')->sole();
    expect($proposal->exercise_id)->toBe($exercise->id)
        ->and($proposal->company_id)->toBe($this->company->id)
        ->and($proposal->purpose)->toBe($withBudget ? ProposalPurpose::Revision : ProposalPurpose::InitialBudget)
        ->and($proposal->reference_budget_id)->toBe($budget?->id)
        ->and($proposal->items()->where('expense_id', $expense->id)->exists())->toBeTrue()
        ->and($actual->fresh()->amount)->toBe('7.00');
    $page->assertRedirect(ProposalResource::getUrl('view', ['record' => $proposal], tenant: $this->company->tenantCompany));
})->with(['initial budget' => false, 'revision' => true]);

it('rejects closed, foreign and already occupied exercises from the list', function (string $case): void {
    $exercise = Exercise::factory()->create([
        'company_id' => $case === 'foreign' ? Company::factory()->create()->id : $this->company->id,
        'status' => $case === 'closed' ? 'closed' : 'open',
    ]);
    if ($case === 'draft') {
        Proposal::factory()->for($this->company)->for($exercise)->create(['created_by_id' => $this->user->id]);
    }
    $count = Proposal::query()->count();

    Livewire::test(ListProposals::class)
        ->callAction('initializeProposal', ['exercise_id' => $exercise->id])
        ->assertHasActionErrors(['exercise_id']);

    expect(Proposal::query()->count())->toBe($count);
})->with(['closed', 'foreign', 'draft']);

it('requires an exercise when none is available', function (): void {
    Livewire::test(ListProposals::class)
        ->callAction('initializeProposal')
        ->assertHasActionErrors(['exercise_id' => 'required']);

    expect(Proposal::query()->exists())->toBeFalse();
});

it('does not allow a viewer to create a proposal from the list', function (): void {
    $viewer = User::factory()->create();
    grantTestPermissions(['company_id' => $this->company->id, 'user' => $viewer, 'permissions' => TestPermissions::VIEW]);
    $this->actingAs($viewer);

    Livewire::test(ListProposals::class)
        ->assertActionHidden('initializeProposal')
        ->call('mountAction', 'initializeProposal')
        ->assertSet('mountedActions', []);

    expect(Proposal::query()->exists())->toBeFalse();
});
