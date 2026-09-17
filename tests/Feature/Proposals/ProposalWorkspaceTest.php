<?php

use App\Actions\Proposals\ApproveProposal;
use App\Actions\Proposals\DiscardProposal;
use App\Actions\Proposals\InitializeProposal;
use App\Actions\Proposals\PlanExpense;
use App\Actions\Proposals\ReviewProposalReadiness;
use App\Domain\Proposals\ProposalActionType;
use App\Filament\Resources\Proposals\Pages\ViewProposal;
use App\Models\Contract;
use App\Models\ContractCondition;
use App\Models\Exercise;
use App\Models\Expense;
use App\Models\ExpenseLine;
use App\Models\Project;
use App\Models\ProposalItem;
use App\Models\User;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Tests\Support\TestPermissions;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->exercise = Exercise::factory()->create(['year' => 2027]);
    $this->actor = User::factory()->create();
    grantTestPermissions(['company_id' => $this->exercise->company_id, 'user' => $this->actor, 'permissions' => [...TestPermissions::VIEW, ...TestPermissions::MANAGE_PROPOSALS, ...TestPermissions::APPROVE_BUDGET]]);
    $expense = Expense::factory()->forExercise($this->exercise)->create(['description' => 'Licenze workspace']);
    ExpenseLine::factory()->for($expense)->create(['type' => 'estimate', 'amount' => '80.00']);
    $project = Project::factory()->create(['company_id' => $this->exercise->company_id, 'title' => 'Migrazione workspace', 'initial_state' => 'planned', 'initial_effective_date' => '2027-01-01']);
    $contract = Contract::factory()->create(['company_id' => $this->exercise->company_id, 'title' => 'Assistenza workspace', 'contractual_start_date' => '2027-01-01', 'next_expiry_date' => null, 'renewal_anchor_date' => null]);
    ContractCondition::factory()->forContract($contract)->create(['valid_from' => '2027-01-01', 'valid_to' => null, 'cycle' => 'annual', 'amount' => '100.00']);
    $this->proposal = app(InitializeProposal::class)->execute($this->actor, $this->exercise->company, $this->exercise, (string) Str::uuid());
    $this->expenseItem = $this->proposal->items()->where('expense_id', $expense->id)->sole();
    $this->projectItem = $this->proposal->items()->where('project_id', $project->id)->sole();
    $this->contractItem = $this->proposal->items()->where('contract_id', $contract->id)->sole();
    $this->actingAs($this->actor);
    Filament::setTenant($this->exercise->company->tenantCompany);
});

it('places add controls in the workspace and shows only actions belonging to the row type', function (): void {
    $page = Livewire::test(ViewProposal::class, ['record' => $this->proposal->id])
        ->assertSee('Aggiungi')->assertDontSee('Azioni di Piano')
        ->assertCanSeeTableRecords([$this->expenseItem, $this->projectItem, $this->contractItem]);
    foreach (['createPlannedExpense', 'createPlannedProject', 'createPlannedContract', 'copyExpense', 'includeClosedProject', 'includeTerminatedContract'] as $action) {
        $page->assertActionVisible(TestAction::make($action)->table());
    }
    foreach ([
        [$this->expenseItem, ['planExpenseEstimates', 'planExpenseOwner', 'planExpenseSupplier', 'planExpenseCostCenter', 'reversePlannedExpense'], ['restorePlannedExpense', 'planProjectTransition', 'addContractCondition', 'linkProjectContract', 'excludePlannedExpense']],
        [$this->projectItem, ['planProjectTransition', 'createProjectAllocation', 'planProjectChildExpenses', 'planProjectExpenseEstimates', 'planProjectCostCenter', 'linkProjectContract'], ['planExpenseEstimates', 'changeContractEconomics', 'excludePlannedExpense']],
        [$this->contractItem, ['changeContractEconomics', 'addContractCondition', 'planContractLifecycle', 'planContractRenewal', 'planContractCostCenter', 'linkProjectContract'], ['planExpenseEstimates', 'planProjectTransition', 'excludePlannedExpense']],
    ] as [$item, $visible, $hidden]) {
        foreach ($visible as $name) {
            $page->assertActionVisible(TestAction::make($name)->table($item));
        }
        foreach ($hidden as $name) {
            $page->assertActionHidden(TestAction::make($name)->table($item));
        }
        $page->assertActionHidden(TestAction::make('reloadReality')->table($item))
            ->assertActionHidden(TestAction::make('acknowledgeSource')->table($item));
    }
    $page->filterTable('source_type', ['value' => 'expense'])->assertCanSeeTableRecords([$this->expenseItem])->assertCanNotSeeTableRecords([$this->projectItem, $this->contractItem]);
    $page->filterTable('source_type', ['value' => 'all'])->assertCanSeeTableRecords([$this->expenseItem, $this->projectItem, $this->contractItem]);
});

it('prefills the selected expense estimates and ignores a forged item id in form data', function (): void {
    $other = app(PlanExpense::class)->create($this->actor, $this->proposal, ['description' => 'Altra spesa', 'exercise_id' => $this->exercise->id, 'estimate_lines' => []], null, (string) Str::uuid(), 0)->item;
    $page = Livewire::test(ViewProposal::class, ['record' => $this->proposal->id])
        ->mountAction(TestAction::make('planExpenseEstimates')->table($this->expenseItem))
        ->assertSchemaComponentDoesNotExist('item_id')
        ->assertSchemaStateSet(function (array $state): void {
            expect($state['estimate_lines'])->toHaveCount(1);
            expect((float) array_values($state['estimate_lines'])[0]['amount'])->toBe(80.0);
        });
    $page->fillForm(['item_id' => $other->id, 'estimate_lines' => [['proposal_line_id' => (string) Str::uuid(), 'line_id' => null, 'amount' => '95,00', 'annulled' => false]]])
        ->callMountedAction()->assertHasNoActionErrors();
    expect($this->expenseItem->fresh()->result['estimate_lines'][0]['amount'])->toBe('95.00')->and($other->fresh()->result['estimate_lines'])->toBe([])
        ->and($this->expenseItem->expense->lines()->sole()->amount)->toBe('80.00');
});

it('creates the allocation for the row project without a project selector', function (): void {
    Livewire::test(ViewProposal::class, ['record' => $this->proposal->id])
        ->mountAction(TestAction::make('createProjectAllocation')->table($this->projectItem))
        ->assertSchemaComponentDoesNotExist('item_id')->assertSchemaComponentDoesNotExist('project_id')
        ->fillForm(['description' => 'Nuova allocazione contestuale', 'reason' => 'Piano autonomo', 'estimate_lines' => [['proposal_line_id' => (string) Str::uuid(), 'amount' => '20', 'annulled' => false]]])
        ->callMountedAction()->assertHasNoActionErrors();
    $created = $this->proposal->actions()->where('action_type', ProposalActionType::CreateProjectAllocation)->sole();
    expect($created->item->result['project_id'])->toBe($this->projectItem->project_id);
});

it('acknowledges and realigns directly on their source while retaining mandatory confirmations and reasons', function (): void {
    $new = Expense::factory()->forExercise($this->exercise)->create();
    ExpenseLine::factory()->for($new)->create(['type' => 'actual', 'amount' => '10.00']);
    $this->expenseItem->expense->increment('revision');
    app(ReviewProposalReadiness::class)->execute($this->actor, $this->proposal, (string) Str::uuid());
    $reviewItem = $this->proposal->items()->where('expense_id', $new->id)->sole();
    $page = Livewire::test(ViewProposal::class, ['record' => $this->proposal->id])
        ->assertActionVisible(TestAction::make('acknowledgeSource')->table($reviewItem))
        ->assertActionVisible(TestAction::make('keepProposal')->table($this->expenseItem))
        ->assertActionHidden(TestAction::make('planExpenseOwner')->table($reviewItem))
        ->assertSee('Nuova fonte da prendere in visione')
        ->assertSee('Effettivo (Sola Lettura)');
    $page->mountAction(TestAction::make('acknowledgeSource')->table($reviewItem))->assertSchemaComponentDoesNotExist('item_id')->callMountedAction()->assertHasNoActionErrors();
    $page->mountAction(TestAction::make('keepProposal')->table($this->expenseItem))->assertSchemaComponentDoesNotExist('item_id')
        ->callMountedAction()->assertHasActionErrors(['reason'])
        ->fillForm(['reason' => 'Confermo il piano'])->callMountedAction()->assertHasNoActionErrors();
    expect($reviewItem->fresh()->readiness_state->value)->toBe('aligned')->and($this->expenseItem->fresh()->readiness_state->value)->toBe('aligned');
});

it('keeps terminal proposals readable and rejects direct mutations', function (string $terminal): void {
    if ($terminal === 'approved') {
        app(ApproveProposal::class)->execute($this->actor, $this->proposal, (string) Str::uuid());
    } else {
        app(DiscardProposal::class)->execute($this->actor, $this->proposal, 'Scartata', (string) Str::uuid());
    }
    $page = Livewire::test(ViewProposal::class, ['record' => $this->proposal->id])
        ->assertSee('Licenze workspace')->assertSee('Storico Decisioni')->assertSee('Esercizi Interessati')
        ->assertDontSee('Aggiungi')->assertDontSee('Gestisci')
        ->assertActionVisible('timeline');
    $revision = $this->proposal->fresh()->revision;
    foreach (['planExpenseEstimates', 'reversePlannedExpense', 'reloadReality', 'acknowledgeSource'] as $name) {
        $page->assertActionHidden(TestAction::make($name)->table($this->expenseItem))
            ->mountAction(TestAction::make($name)->table($this->expenseItem))->assertActionNotMounted();
    }
    expect($this->proposal->fresh()->revision)->toBe($revision);
})->with(['approved', 'discarded']);

it('rejects direct table actions by a viewer and foreign proposal records', function (): void {
    $viewer = User::factory()->create();
    grantTestPermissions(['company_id' => $this->exercise->company_id, 'user' => $viewer, 'permissions' => TestPermissions::VIEW]);
    $this->actingAs($viewer);
    $page = Livewire::test(ViewProposal::class, ['record' => $this->proposal->id])->assertDontSee('Aggiungi');
    foreach (['planExpenseEstimates', 'excludePlannedExpense', 'reversePlannedExpense'] as $name) {
        $page->mountAction(TestAction::make($name)->table($this->expenseItem))->assertActionNotMounted();
    }
    $this->actingAs($this->actor);
    $foreign = ProposalItem::factory()->create();
    Livewire::test(ViewProposal::class, ['record' => $this->proposal->id])
        ->mountAction(TestAction::make('planExpenseEstimates')->table($foreign))->assertActionNotMounted();
    expect($this->proposal->fresh()->revision)->toBe(0);
});

it('excludes a new expense from its row without hard deletion and keeps its history visible', function (): void {
    $created = app(PlanExpense::class)->create($this->actor, $this->proposal, ['description' => 'Nuova da escludere', 'exercise_id' => $this->exercise->id, 'estimate_lines' => []], null, (string) Str::uuid(), 0);
    Livewire::test(ViewProposal::class, ['record' => $this->proposal->id])
        ->mountAction(TestAction::make('excludePlannedExpense')->table($created->item))
        ->assertSchemaComponentDoesNotExist('item_id')
        ->callMountedAction()->assertHasNoActionErrors()
        ->assertSee('Esclusa dalla Proposta')->assertSee('Esclusione dalla Proposta')
        ->assertActionHidden(TestAction::make('planExpenseEstimates')->table($created->item));
    expect($created->item->fresh()->isExcludedFromPlan())->toBeTrue()->and($this->proposal->items()->count())->toBe(4)->and($this->proposal->actionHistory()->count())->toBe(2);
});
