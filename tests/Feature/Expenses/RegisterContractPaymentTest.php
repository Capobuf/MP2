<?php

use App\Filament\Resources\Contracts\Pages\ViewContract;
use App\Filament\Resources\Contracts\RelationManagers\ContractExpensesRelationManager;
use App\Filament\Resources\Expenses\ExpenseResource;
use App\Filament\Resources\Expenses\Pages\ListExpenses;
use App\Models\AuditEvent;
use App\Models\BudgetSnapshot;
use App\Models\Company;
use App\Models\Contract;
use App\Models\ContractCondition;
use App\Models\ContractLifecycleFact;
use App\Models\Exercise;
use App\Models\Expense;
use App\Models\ExpenseLine;
use App\Models\Proposal;
use App\Models\User;
use App\Support\ExerciseContext;
use Carbon\CarbonImmutable;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\Support\TestPermissions;

uses(RefreshDatabase::class);

beforeEach(function () {
    CarbonImmutable::setTestNow('2026-09-15 10:00:00 Europe/Rome');
    $this->user = User::factory()->create();
    $this->company = Company::factory()->create(['timezone' => 'Europe/Rome']);
    grantTestPermissions([
        'company_id' => $this->company->id,
        'user' => $this->user,
        'permissions' => [...TestPermissions::VIEW, ...TestPermissions::MANAGE_OPERATIONS],
    ]);
    $this->exercise = Exercise::factory()->for($this->company)->create(['year' => 2026]);
    $this->contract = Contract::factory()->for($this->company)->create();
    ContractCondition::factory()->forContract($this->contract)->create(['amount' => '100.00']);
    $this->estimate = Expense::factory()->forExercise($this->exercise)->create([
        'contract_id' => $this->contract->id,
        'supplier_id' => $this->contract->supplier_id,
        'origin' => 'system',
    ]);
    ExpenseLine::factory()->for($this->estimate)->create(['type' => 'estimate', 'amount' => '1200.00']);
    $this->actingAs($this->user);
    Filament::setTenant($this->company->tenantCompany);
    app(ExerciseContext::class)->select($this->company, $this->exercise->id);
});

afterEach(fn () => CarbonImmutable::setTestNow());

it('registers the editable annual Estimate as a separate Actual from the Contract', function () {
    $component = Livewire::test(ViewContract::class, ['record' => $this->contract->getRouteKey()])
        ->assertActionVisible('createContractActual')
        ->mountAction('registerContractPayment')
        ->fillForm(['contract_id' => $this->contract->id])
        ->assertActionDataSet(['amount' => '1200.00', 'description' => $this->contract->title.' - Effettivo'])
        ->fillForm(['description' => 'Pagamento servizi', 'amount' => '1150,50'])
        ->callMountedAction()
        ->assertHasNoActionErrors()
        ->assertNotified('Pagamento registrato');

    $expense = Expense::query()->where('origin', 'manual')->sole();
    expect($expense->contract_id)->toBe($this->contract->id)
        ->and($expense->description)->toBe('Pagamento servizi')
        ->and($expense->exercise_id)->toBe($this->exercise->id)
        ->and($expense->supplier_id)->toBe($this->contract->supplier_id)
        ->and($expense->lines()->sole()->type->value)->toBe('actual')
        ->and($expense->actual())->toBe('1150.50')
        ->and($this->estimate->fresh()->allocation())->toBe('1200.00')
        ->and($this->estimate->lines()->count())->toBe(1)
        ->and($this->estimate->actual())->toBe('0.00')
        ->and(AuditEvent::query()->where('subject_id', $expense->id)->where('event_type', 'expense_created')->exists())->toBeTrue();

    $component->assertRedirect(ExpenseResource::getUrl('view', ['record' => $expense]));
});

it('preselects the owner Contract and saves the full annual Estimate from its expense menu', function () {
    Livewire::test(ContractExpensesRelationManager::class, [
        'ownerRecord' => $this->contract, 'pageClass' => ViewContract::class,
    ])
        ->assertTableActionVisible('createContractActual')
        ->mountTableAction('registerContractPayment')
        ->assertActionDataSet([
            'contract_id' => $this->contract->id,
            'amount' => '1200.00',
            'description' => $this->contract->title.' - Effettivo',
        ])
        ->callMountedAction()
        ->assertHasNoActionErrors();

    expect(Expense::query()->where('origin', 'manual')->sole()->actual())->toBe('1200.00')
        ->and(Expense::query()->where('origin', 'manual')->sole()->description)->toBe($this->contract->title.' - Effettivo');
});

it('uses the selected Exercise and never subtracts existing Actuals from the suggested Estimate', function () {
    $previous = Exercise::factory()->for($this->company)->create(['year' => 2025]);
    $previousEstimate = Expense::factory()->forExercise($previous)->create([
        'contract_id' => $this->contract->id, 'origin' => 'system',
    ]);
    ExpenseLine::factory()->for($previousEstimate)->create(['amount' => '900.00', 'type' => 'estimate']);
    $actual = Expense::factory()->forExercise($previous)->create(['contract_id' => $this->contract->id]);
    ExpenseLine::factory()->for($actual)->actual()->create(['amount' => '400.00']);
    app(ExerciseContext::class)->select($this->company, $previous->id);

    Livewire::test(ViewContract::class, ['record' => $this->contract->getRouteKey()])
        ->mountAction('registerContractPayment')
        ->fillForm(['contract_id' => $this->contract->id])
        ->assertActionDataSet(['amount' => '900.00'])
        ->fillForm(['description' => 'Pagamento precedente'])
        ->callMountedAction()
        ->assertHasNoActionErrors();

    expect(Expense::query()->where('description', 'Pagamento precedente')->sole()->exercise_id)->toBe($previous->id);
});

it('registers from the Contract header without duplicating an operation on retry', function () {
    $component = Livewire::test(ViewContract::class, ['record' => $this->contract->getRouteKey()])
        ->mountAction('registerContractPayment')
        ->assertActionDataSet(['contract_id' => $this->contract->id, 'amount' => '1200.00']);
    $operationId = $component->get('mountedActions.0.data.operation_id');
    $component->fillForm(['description' => 'Pagamento annuale'])
        ->callMountedAction()->assertHasNoActionErrors();

    Livewire::test(ViewContract::class, ['record' => $this->contract->getRouteKey()])
        ->mountAction('registerContractPayment')
        ->fillForm(['description' => 'Pagamento annuale', 'operation_id' => $operationId])
        ->callMountedAction()->assertHasNoActionErrors();

    expect(Expense::query()->where('origin', 'manual')->count())->toBe(1)
        ->and($this->contract->fresh()->annualTotals()[$this->exercise->id]['actual'])->toBe('1200.00');
});

it('does not expose payment registration in Expenses', function () {
    Livewire::test(ListExpenses::class)
        ->assertActionVisible('create')
        ->assertActionDoesNotExist('registerContractPayment')
        ->assertDontSee('Registra Pagamento');
});

it('rejects a different Contract submitted to the payment form', function () {
    $foreign = Contract::factory()->create();

    Livewire::test(ViewContract::class, ['record' => $this->contract->getRouteKey()])
        ->mountAction('registerContractPayment')
        ->fillForm(['contract_id' => $foreign->id])
        ->callMountedAction()
        ->assertHasActionErrors(['contract_id']);

    expect(Expense::query()->where('origin', 'manual')->exists())->toBeFalse();
});

it('hides payment registration for an archived Contract', function () {
    $this->contract->update(['archived_at' => now()]);

    Livewire::test(ViewContract::class, ['record' => $this->contract->getRouteKey()])
        ->assertActionHidden('registerContractPayment');
});

it('requires the Budget reason before creating the payment', function () {
    $proposal = Proposal::factory()->for($this->company)->for($this->exercise)->create();
    BudgetSnapshot::factory()->for($proposal)->create();

    Livewire::test(ViewContract::class, ['record' => $this->contract->getRouteKey()])->mountAction('registerContractPayment')
        ->fillForm(['contract_id' => $this->contract->id, 'description' => 'Pagamento'])
        ->callMountedAction()->assertHasActionErrors(['change_reason'])
        ->fillForm(['change_reason' => 'Pagamento registrato dopo approvazione'])
        ->callMountedAction()->assertHasNoActionErrors();

    expect(Expense::query()->where('origin', 'manual')->count())->toBe(1);
});

it('requires a note for zero and negative amounts without creating a partial expense', function (string $amount) {
    $component = Livewire::test(ViewContract::class, ['record' => $this->contract->getRouteKey()])->mountAction('registerContractPayment')
        ->fillForm(['contract_id' => $this->contract->id])
        ->fillForm(['description' => 'Rettifica', 'amount' => $amount])
        ->callMountedAction()->assertHasActionErrors(['note']);

    expect(Expense::query()->where('origin', 'manual')->count())->toBe(0);

    $component->fillForm(['note' => 'Correzione documentata'])
        ->callMountedAction()->assertHasNoActionErrors();
})->with(['0.00', '-50.00']);

it('requires a terminal declaration and note for a ceased Contract', function () {
    ContractLifecycleFact::factory()->forContract($this->contract)->create([
        'type' => 'cessation', 'declared_contractual_date' => '2026-08-31', 'state_change_date' => '2026-08-31', 'reason' => 'Fine servizio',
    ]);
    Livewire::test(ViewContract::class, ['record' => $this->contract->getRouteKey()])->mountAction('registerContractPayment')
        ->fillForm(['contract_id' => $this->contract->id, 'description' => 'Addebito tardivo'])
        ->callMountedAction()->assertHasActionErrors(['actual_kind', 'activity_note'])
        ->fillForm(['actual_kind' => 'late', 'activity_note' => 'Addebito ricevuto dopo la cessazione'])
        ->callMountedAction()->assertHasNoActionErrors();

    expect($this->contract->fresh()->stateAtDate('2026-09-15')->value)->toBe('cessated');
});

it('rejects ordinary Actuals for a planned Contract', function () {
    $this->contract->update(['contractual_start_date' => '2027-01-01', 'next_expiry_date' => null, 'renewal_anchor_date' => null]);
    Livewire::test(ViewContract::class, ['record' => $this->contract->getRouteKey()])->mountAction('registerContractPayment')
        ->fillForm(['contract_id' => $this->contract->id, 'description' => 'Non consentito'])
        ->callMountedAction()->assertHasActionErrors(['actual_kind']);

    expect(Expense::query()->where('origin', 'manual')->count())->toBe(0);
});

it('disables payment registration for closed or future Exercises', function (int $year, string $status) {
    $exercise = Exercise::factory()->for($this->company)->create(['year' => $year, 'status' => $status]);
    app(ExerciseContext::class)->select($this->company, $exercise->id);

    Livewire::test(ViewContract::class, ['record' => $this->contract->getRouteKey()])->assertActionDisabled('registerContractPayment')
        ->callAction('registerContractPayment', ['contract_id' => $this->contract->id, 'description' => 'Non consentito', 'amount' => '10.00']);

    expect(Expense::query()->where('origin', 'manual')->count())->toBe(0);
})->with([[2025, 'closed'], [2027, 'open']]);

it('hides payment registration from viewers', function () {
    $viewer = User::factory()->create();
    grantTestPermissions(['company_id' => $this->company->id, 'user' => $viewer, 'permissions' => TestPermissions::VIEW]);
    $this->actingAs($viewer);

    Livewire::test(ViewContract::class, ['record' => $this->contract->getRouteKey()])->assertActionHidden('registerContractPayment');
    Livewire::test(ContractExpensesRelationManager::class, [
        'ownerRecord' => $this->contract, 'pageClass' => ViewContract::class,
    ])->assertTableActionHidden('registerContractPayment');
});
