<?php

use App\Actions\Proposals\InitializeProposal;
use App\Actions\Proposals\PlanExpense;
use App\Actions\Proposals\ReviewProposalReadiness;
use App\Domain\Proposals\ProposalActionType;
use App\Filament\Resources\Proposals\Pages\ViewProposal;
use App\Models\Company;
use App\Models\Exercise;
use App\Models\Expense;
use App\Models\ExpenseLine;
use App\Models\User;
use Filament\Actions\Testing\TestAction;
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
    $item = $proposal->items()->sole();
    app(PlanExpense::class)->execute($actor, $proposal, $item, ProposalActionType::SetExpenseEstimates, ['estimate_lines' => [[
        'proposal_line_id' => (string) Str::uuid(), 'line_id' => $line->id, 'amount' => '8.00', 'note' => null, 'annulled' => false,
    ]]], null, (string) Str::uuid(), 0);
    $line->update(['amount' => '6.00']);
    $proposal = app(ReviewProposalReadiness::class)->execute($actor, $proposal->refresh(), (string) Str::uuid());
    grantTestPermissions(['company_id' => $proposal->company_id, 'user' => $actor, 'permissions' => TestPermissions::VIEW]);
    $this->actingAs($actor);
    Filament::setTenant(($proposal->company)->tenantCompany);

    $item = $proposal->items()->sole();
    $revisionComparison = 'Base '.$item->baseline_revision.' → corrente '.$expense->fresh()->revision;
    $page = Livewire::test(ViewProposal::class, ['record' => $proposal->getRouteKey()])
        ->assertActionVisible(TestAction::make('reloadReality')->table($item))
        ->assertActionVisible(TestAction::make('keepProposal')->table($item))
        ->assertActionVisible(TestAction::make('manualRealignment')->table($item))
        ->assertSee('Da Riallineare')
        ->assertSee('Storico Decisioni');

    $page->mountAction(TestAction::make('reloadReality')->table($item))
        ->assertMountedActionModalSee($revisionComparison)
        ->assertMountedActionModalSee('Decisioni da ritirare')
        ->assertMountedActionModalSeeHtml('<div class="mp2-proposal-realignment-summary">')
        ->assertMountedActionModalSee('#1 · Set Expense Estimates')
        ->unmountAction();
    $page->mountAction(TestAction::make('keepProposal')->table($item))
        ->assertMountedActionModalSee($revisionComparison)
        ->assertMountedActionModalSee('Decisioni da mantenere')
        ->unmountAction();
    $page->mountAction(TestAction::make('manualRealignment')->table($item))
        ->assertMountedActionModalSee($revisionComparison)
        ->assertMountedActionModalSee('Decisioni da rivedere')
        ->assertSchemaComponentExists('retained_action_ids');
});

it('applies the selected realignment choice to multiple stale sources', function (string $bulkAction, array $formData, array $expectedAmounts, string $expectedStatus): void {
    $company = Company::factory()->create();
    $exercise = Exercise::factory()->for($company)->create();
    $actor = User::factory()->create();
    grantTestPermissions([
        'company_id' => $company->id,
        'user' => $actor,
        'permissions' => [...TestPermissions::VIEW, ...TestPermissions::MANAGE_PROPOSALS],
    ]);

    $expenses = collect([5, 10, 15])->map(function (int $amount) use ($exercise): array {
        $expense = Expense::factory()->forExercise($exercise)->create();
        $line = ExpenseLine::factory()->for($expense)->create(['type' => 'estimate', 'amount' => $amount.'.00']);

        return [$expense, $line];
    });
    $proposal = app(InitializeProposal::class)->execute($actor, $company, $exercise, (string) Str::uuid());

    foreach ([8, 12] as $index => $plannedAmount) {
        [$expense, $line] = $expenses[$index];
        $item = $proposal->items()->where('expense_id', $expense->id)->sole();
        app(PlanExpense::class)->execute($actor, $proposal, $item, ProposalActionType::SetExpenseEstimates, ['estimate_lines' => [[
            'proposal_line_id' => (string) Str::uuid(), 'line_id' => $line->id, 'amount' => $plannedAmount.'.00', 'note' => null, 'annulled' => false,
        ]]], null, (string) Str::uuid(), (int) $proposal->revision);
        $proposal->refresh();
    }

    $expenses[0][1]->update(['amount' => '6.00']);
    $expenses[1][1]->update(['amount' => '11.00']);
    $proposal = app(ReviewProposalReadiness::class)->execute($actor, $proposal->refresh(), (string) Str::uuid());
    $staleItems = $proposal->items()->whereIn('expense_id', [$expenses[0][0]->id, $expenses[1][0]->id])->orderBy('id')->get();
    $alignedItem = $proposal->items()->where('expense_id', $expenses[2][0]->id)->sole();

    $this->actingAs($actor);
    Filament::setTenant(($proposal->company)->tenantCompany);

    $page = Livewire::test(ViewProposal::class, ['record' => $proposal->getRouteKey()])
        ->assertTableBulkActionsExistInOrder(['bulkReloadReality', 'bulkKeepProposal'])
        ->assertTableBulkActionVisible($bulkAction);

    expect($page->instance()->getTable()->isRecordSelectable($staleItems[0]))->toBeTrue()
        ->and($page->instance()->getTable()->isRecordSelectable($alignedItem))->toBeFalse();

    $page->callTableBulkAction($bulkAction, $staleItems, $formData)
        ->assertHasNoTableBulkActionErrors();

    $staleItems->each->refresh();
    expect($staleItems->pluck('readiness_state')->map->value->all())->toBe(['aligned', 'aligned'])
        ->and($staleItems->map(fn ($item): string => data_get($item->result, 'estimate_lines.0.amount'))->all())->toBe($expectedAmounts)
        ->and($proposal->actionHistory()->orderBy('id')->get()->pluck('status')->map->value->all())->toBe([$expectedStatus, $expectedStatus]);
})->with([
    'ricarica realtà' => ['bulkReloadReality', [], ['6.00', '11.00'], 'withdrawn'],
    'mantieni proposta' => ['bulkKeepProposal', ['reason' => 'Decisioni confermate in blocco'], ['8.00', '12.00'], 'active'],
]);
