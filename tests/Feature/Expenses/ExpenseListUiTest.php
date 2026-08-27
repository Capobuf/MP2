<?php

use App\Domain\Company\Capability;
use App\Domain\Projects\ProjectState;
use App\Filament\Resources\Contracts\ContractResource;
use App\Filament\Resources\Expenses\ExpenseResource;
use App\Filament\Resources\Expenses\Pages\EditExpense;
use App\Filament\Resources\Expenses\Pages\ListExpenses;
use App\Filament\Resources\Expenses\Pages\ViewExpense;
use App\Filament\Resources\Expenses\Widgets\ExpenseOverview;
use App\Filament\Resources\Projects\ProjectResource;
use App\Livewire\ExpenseDetail;
use App\Models\AuditEvent;
use App\Models\Company;
use App\Models\CompanyCapability;
use App\Models\Contract;
use App\Models\Exercise;
use App\Models\Expense;
use App\Models\ExpenseLine;
use App\Models\Project;
use App\Models\Supplier;
use App\Models\User;
use App\Support\ExerciseContext;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->company = Company::factory()->create();
    CompanyCapability::query()->create([
        'company_id' => $this->company->id,
        'user_id' => $this->user->id,
        'capability' => Capability::View,
    ]);
    $this->exercise = Exercise::factory()->for($this->company)->create(['year' => 2026]);
    $this->actingAs($this->user);
    Filament::setTenant(($this->company)->tenantCompany);
    app(ExerciseContext::class)->select($this->company, $this->exercise->id);
});

it('shows real expense KPIs for the globally selected Exercise', function () {
    $supplier = Supplier::factory()->for($this->company)->create();
    $active = Expense::factory()->forExercise($this->exercise)->create(['supplier_id' => null]);
    $reversed = Expense::factory()->forExercise($this->exercise)->reversed()->create(['supplier_id' => $supplier->id]);
    $otherExercise = Exercise::factory()->for($this->company)->create(['year' => 2027]);
    $outsideContext = Expense::factory()->forExercise($otherExercise)->create();

    ExpenseLine::factory()->for($active)->create(['type' => 'estimate', 'amount' => '100.00']);
    ExpenseLine::factory()->for($active)->actual()->create(['amount' => '40.00']);
    ExpenseLine::factory()->for($active)->annulled()->create(['type' => 'estimate', 'amount' => '900.00']);
    ExpenseLine::factory()->for($reversed)->create(['type' => 'estimate', 'amount' => '200.00']);
    ExpenseLine::factory()->for($outsideContext)->create(['type' => 'estimate', 'amount' => '300.00']);

    Livewire::test(ExpenseOverview::class)
        ->assertSee('Spese rappresentate')
        ->assertSee('Totale Stime')
        ->assertSee('100,00')
        ->assertSee('Totale Effettivi')
        ->assertSee('40,00')
        ->assertSee('Senza Fornitore');
});

it('toggles the sibling expense detail from the row without exposing a detail button or fake bulk actions', function () {
    $expense = Expense::factory()->forExercise($this->exercise)->create([
        'description' => 'Licenze infrastruttura',
        'notes' => 'Rinnovo apparati',
    ]);
    $line = ExpenseLine::factory()->for($expense)->create([
        'type' => 'estimate',
        'amount' => '1250.00',
        'quantity' => '5.000000',
        'unit_amount' => '250.000000',
        'unit_of_measure' => 'licenze',
    ]);
    AuditEvent::withoutEvents(fn () => AuditEvent::query()->create([
        'operation_id' => (string) Str::uuid(),
        'company_id' => $this->company->id,
        'actor_id' => $this->user->id,
        'event_type' => 'expense_line_created',
        'subject_type' => ExpenseLine::class,
        'subject_id' => $line->id,
        'affected_exercise_ids' => [$this->exercise->id],
        'effective_from' => now()->toDateString(),
        'allocated_impact_by_exercise' => [],
        'actual_impact_by_exercise' => [],
        'reference_type' => Project::class,
        'reference_id' => 999,
    ]));

    $list = Livewire::test(ListExpenses::class)
        ->assertSeeLivewire(ExpenseOverview::class)
        ->assertSeeHtml('href="'.ExpenseResource::getUrl('view', ['record' => $expense], tenant: $this->company).'"')
        ->assertTableActionExists('selectExpense', record: $expense)
        ->assertTableActionDoesNotExist('view', record: $expense)
        ->assertTableActionDoesNotExist('delete', record: $expense)
        ->callTableAction('selectExpense', record: $expense)
        ->assertSet('selectedExpenseId', $expense->id)
        ->assertDispatched('show-expense-detail', expenseId: $expense->id);

    expect($list->instance()->getTable()->getRecordAction($expense))->toBe('selectExpense')
        ->and($list->instance()->getTable()->isSelectionEnabled())->toBeTrue();

    $list->set('tableSearch', 'nessun-risultato-corrispondente')
        ->assertSet('selectedExpenseId', null)
        ->set('tableSearch', '')
        ->callTableAction('selectExpense', record: $expense)
        ->assertSet('selectedExpenseId', $expense->id)
        ->callTableAction('selectExpense', record: $expense)
        ->assertSet('selectedExpenseId', null);

    Livewire::test(ExpenseDetail::class, ['expenseId' => $expense->id, 'compact' => true])
        ->assertSee('Licenze infrastruttura')
        ->assertSee('Righe della Spesa')
        ->assertSee('5')
        ->assertDontSee('5.000000')
        ->assertSee('Timeline recente')
        ->assertSee('Riga creata')
        ->assertSee('Apri dettaglio completo');

    Livewire::test(ViewExpense::class, ['record' => $expense->getRouteKey()])
        ->assertSeeLivewire(ExpenseDetail::class)
        ->assertSeeHtml('class="mp2-object-header')
        ->assertSeeHtml('data-object-icon="expense"')
        ->assertDontSee('Panoramica')
        ->assertDontSee('Timeline della Spesa');
});

it('shows only an existing Project or Contract reference in the expense header', function () {
    $project = Project::factory()->for($this->company)->create(['title' => 'Migrazione ERP']);
    $contract = Contract::factory()->for($this->company)->create(['title' => 'Servizi cloud']);
    $projectExpense = Expense::factory()->forExercise($this->exercise)->for($project)->create();
    $contractExpense = Expense::factory()->forExercise($this->exercise)->for($contract)->create();
    $standaloneExpense = Expense::factory()->forExercise($this->exercise)->create();

    Livewire::test(ViewExpense::class, ['record' => $projectExpense->getRouteKey()])
        ->assertSee('Progetto di riferimento')
        ->assertSee('Migrazione ERP')
        ->assertSeeHtml('href="'.ProjectResource::getUrl('view', ['record' => $project], tenant: $this->company).'"')
        ->assertDontSee('Contratto di riferimento');

    Livewire::test(ViewExpense::class, ['record' => $contractExpense->getRouteKey()])
        ->assertSee('Contratto di riferimento')
        ->assertSee('Servizi cloud')
        ->assertSeeHtml('href="'.ContractResource::getUrl('view', ['record' => $contract], tenant: $this->company).'"')
        ->assertDontSee('Progetto di riferimento');

    Livewire::test(ViewExpense::class, ['record' => $standaloneExpense->getRouteKey()])
        ->assertDontSee('Progetto di riferimento')
        ->assertDontSee('Contratto di riferimento');
});

it('opens the complete edit page from the add Line action', function () {
    CompanyCapability::query()->create([
        'company_id' => $this->company->id,
        'user_id' => $this->user->id,
        'capability' => Capability::ManageOperations,
    ]);
    $expense = Expense::factory()->forExercise($this->exercise)->create();
    ExpenseLine::factory()->for($expense)->create();

    $editUrl = ExpenseResource::getUrl('edit', [
        'record' => $expense,
        'addLine' => 1,
    ]);

    Livewire::test(ExpenseDetail::class, ['expenseId' => $expense->id, 'compact' => true])
        ->assertActionVisible('addLine')
        ->assertActionHasUrl('addLine', $editUrl);

    $component = Livewire::withQueryParams(['addLine' => 1])
        ->test(EditExpense::class, ['record' => $expense->getRouteKey()]);
    $lineKeys = array_keys((array) $component->get('data.lines'));

    $component
        ->assertFormComponentActionHidden('lines', 'delete', ['item' => $lineKeys[0]])
        ->assertFormComponentActionVisible('lines', 'delete', ['item' => $lineKeys[1]])
        ->callFormComponentAction('lines', 'delete', arguments: ['item' => $lineKeys[1]]);

    expect((array) $component->get('data.lines'))->toHaveCount(1)
        ->and($expense->lines()->count())->toBe(1);
});

it('shows the overspend note only when the entered Line creates or increases overspend', function () {
    CompanyCapability::query()->create([
        'company_id' => $this->company->id,
        'user_id' => $this->user->id,
        'capability' => Capability::ManageOperations,
    ]);
    $this->company->update(['overspend_note_required' => true]);
    $project = Project::factory()->for($this->company)->create(['initial_state' => ProjectState::Open]);
    $expense = Expense::factory()->forExercise($this->exercise)->for($project)->create(['direct_cost_center_id' => null]);
    ExpenseLine::factory()->for($expense)->create(['type' => 'estimate', 'amount' => '100.00']);

    $component = Livewire::withQueryParams(['addLine' => 1])
        ->test(EditExpense::class, ['record' => $expense->getRouteKey()]);
    $lineKey = array_key_last((array) $component->get('data.lines'));

    $component
        ->set("data.lines.{$lineKey}.type", 'actual')
        ->set("data.lines.{$lineKey}.amount", '50.00')
        ->assertFormFieldHidden('overspend_note');

    $component
        ->set("data.lines.{$lineKey}.amount", '150.00')
        ->assertFormFieldVisible('overspend_note');
});
