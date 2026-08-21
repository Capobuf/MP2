<?php

use App\Domain\Company\Capability;
use App\Domain\Expenses\ExerciseStatus;
use App\Filament\Resources\Expenses\ExpenseResource;
use App\Filament\Resources\Expenses\Pages\CreateExpense;
use App\Filament\Resources\Expenses\Pages\ListExpenses;
use App\Filament\Resources\Expenses\Pages\ViewExpense;
use App\Models\AuditEvent;
use App\Models\Company;
use App\Models\CompanyCapability;
use App\Models\Contract;
use App\Models\CostCenter;
use App\Models\Exercise;
use App\Models\Expense;
use App\Models\ExpenseLine;
use App\Models\Supplier;
use App\Models\User;
use App\Support\ExerciseContext;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

function grantExpenseResource(User $user, Company $company, bool $manage = true): void
{
    foreach ($manage ? [Capability::View, Capability::ManageOperations] : [Capability::View] as $capability) {
        CompanyCapability::query()->create([
            'company_id' => $company->id,
            'user_id' => $user->id,
            'capability' => $capability,
        ]);
    }
}

it('creates an expense in the selected global Exercise without exposing a second Exercise choice', function () {
    $manager = User::factory()->create();
    $company = Company::factory()->create();
    grantExpenseResource($manager, $company);
    $exercise = Exercise::factory()->for($company)->create(['year' => now($company->timezone)->year]);
    $this->actingAs($manager);
    Filament::setTenant($company);
    app(ExerciseContext::class)->select($company, $exercise->id);

    Livewire::test(CreateExpense::class)
        ->assertFormFieldDoesNotExist('exercise_id')
        ->assertFormFieldExists('container')
        ->assertFormComponentActionHidden('supplier_id', 'createOption')
        ->assertFormComponentActionHidden('direct_cost_center_id', 'createOption')
        ->assertFormFieldExists('project_id')
        ->assertFormFieldExists('contract_id')
        ->assertFormFieldDoesNotExist('actual_kind')
        ->assertFormFieldDoesNotExist('contract_owner_id')
        ->assertFormFieldDoesNotExist('budget_id')
        ->assertFormFieldDoesNotExist('proposal_id')
        ->assertFormFieldDoesNotExist('closing')
        ->assertFormFieldDoesNotExist('carryover')
        ->assertFormFieldDoesNotExist('reprogramming')
        ->assertFormFieldDoesNotExist('forecast')
        ->assertFormFieldDoesNotExist('preventivo')
        ->assertFormFieldDoesNotExist('plafond')
        ->assertFormFieldDoesNotExist('attachment')
        ->assertFormFieldDoesNotExist('cost_center_percentage')
        ->assertFormFieldDoesNotExist('report')
        ->assertFormFieldDoesNotExist('currency')
        ->assertFormFieldDoesNotExist('vat')
        ->fillForm([
            'description' => 'Licenze',
            'lines' => [['type' => 'estimate', 'amount' => '100.00']],
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    expect(Expense::query()->sole()->exercise_id)->toBe($exercise->id)
        ->and(ExpenseLine::query()->count())->toBe(1);
});

it('accepts an amount entered with the Italian decimal separator', function () {
    $manager = User::factory()->create();
    $company = Company::factory()->create();
    grantExpenseResource($manager, $company);
    $exercise = Exercise::factory()->for($company)->create(['year' => now($company->timezone)->year]);
    $this->actingAs($manager);
    Filament::setTenant($company);
    app(ExerciseContext::class)->select($company, $exercise->id);

    Livewire::test(CreateExpense::class)
        ->fillForm([
            'description' => 'Importo con virgola',
            'lines' => [[
                'type' => 'estimate',
                'amount' => '100,50',
                'quantity' => '2,5',
                'unit_amount' => '40,2',
            ]],
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    $line = ExpenseLine::query()->sole();
    expect($line->amount)->toBe('100.50')
        ->and($line->quantity)->toBe('2.500000')
        ->and($line->unit_amount)->toBe('40.200000');
});

it('overrides any browser Exercise state with the selected global Exercise', function () {
    $manager = User::factory()->create();
    $company = Company::factory()->create();
    grantExpenseResource($manager, $company);
    $selected = Exercise::factory()->for($company)->create(['year' => 2026]);
    $injected = Exercise::factory()->for($company)->create(['year' => 2027]);
    $this->actingAs($manager);
    Filament::setTenant($company);
    app(ExerciseContext::class)->select($company, $selected->id);

    Livewire::test(CreateExpense::class)
        ->set('data.exercise_id', $injected->id)
        ->fillForm([
            'description' => 'Contesto autoritativo',
            'lines' => [['type' => 'estimate', 'amount' => '100.00']],
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    expect(Expense::query()->sole()->exercise_id)->toBe($selected->id);
});

it('does not create an expense when the selected global Exercise is closed', function () {
    $manager = User::factory()->create();
    $company = Company::factory()->create();
    grantExpenseResource($manager, $company);
    $closed = Exercise::factory()->for($company)->create([
        'year' => 2025,
        'status' => ExerciseStatus::Closed,
    ]);
    $this->actingAs($manager);
    Filament::setTenant($company);
    app(ExerciseContext::class)->select($company, $closed->id);

    Livewire::test(ListExpenses::class)
        ->assertActionDisabled('create');

    Livewire::test(CreateExpense::class)
        ->fillForm([
            'description' => 'Non consentita',
            'lines' => [['type' => 'estimate', 'amount' => '100.00']],
        ])
        ->call('create')
        ->assertHasErrors(['exercise_id']);

    expect(Expense::query()->count())->toBe(0);
});

it('creates and selects Supplier and Cost Center inline with distinct operations', function () {
    $manager = User::factory()->create();
    $company = Company::factory()->create();
    grantExpenseResource($manager, $company);
    CompanyCapability::query()->create([
        'company_id' => $company->id,
        'user_id' => $manager->id,
        'capability' => Capability::ManageMasterData,
    ]);
    $exercise = Exercise::factory()->for($company)->create();
    $this->actingAs($manager);
    Filament::setTenant($company);
    app(ExerciseContext::class)->select($company, $exercise->id);

    $component = Livewire::test(CreateExpense::class)
        ->assertFormComponentActionVisible('supplier_id', 'createOption')
        ->assertFormComponentActionVisible('direct_cost_center_id', 'createOption')
        ->callFormComponentAction('supplier_id', 'createOption', [
            'legal_name' => 'Fornitore inline',
            'vat_number' => 'IT12345678901',
            'notes' => 'Creato dalla Spesa',
        ]);

    $supplier = Supplier::query()->sole();
    $component
        ->assertFormSet(['supplier_id' => $supplier->id])
        ->callFormComponentAction('direct_cost_center_id', 'createOption', [
            'name' => 'Centro inline',
        ]);

    $costCenter = CostCenter::query()->sole();
    $component->assertFormSet(['direct_cost_center_id' => $costCenter->id]);

    expect(AuditEvent::query()
        ->whereIn('subject_type', [Supplier::class, CostCenter::class])
        ->pluck('operation_id')
        ->unique()
        ->count())->toBe(2);
});

it('creates a distinct expense after save and create another', function () {
    $manager = User::factory()->create();
    $company = Company::factory()->create();
    grantExpenseResource($manager, $company);
    $exercise = Exercise::factory()->for($company)->create(['year' => now($company->timezone)->year]);
    $this->actingAs($manager);
    Filament::setTenant($company);
    app(ExerciseContext::class)->select($company, $exercise->id);

    Livewire::test(CreateExpense::class)
        ->fillForm([
            'description' => 'Prima spesa',
            'lines' => [['type' => 'estimate', 'amount' => '100.00']],
        ])
        ->call('create', true)
        ->assertHasNoFormErrors()
        ->fillForm([
            'description' => 'Seconda spesa',
            'lines' => [['type' => 'estimate', 'amount' => '200.00']],
        ])
        ->call('create', true)
        ->assertHasNoFormErrors();

    expect(Expense::query()->orderBy('id')->pluck('description')->all())
        ->toBe(['Prima spesa', 'Seconda spesa'])
        ->and(ExpenseLine::query()->count())->toBe(2);
});

it('lists and resolves expenses only inside the current tenant', function () {
    $viewer = User::factory()->create();
    $companyA = Company::factory()->create();
    $companyB = Company::factory()->create();
    grantExpenseResource($viewer, $companyA, false);
    $expenseA = Expense::factory()->forExercise(Exercise::factory()->for($companyA)->create())->create();
    $expenseB = Expense::factory()->forExercise(Exercise::factory()->for($companyB)->create())->create();
    ExpenseLine::factory()->for($expenseA)->create();
    ExpenseLine::factory()->for($expenseB)->create();
    $this->actingAs($viewer);
    Filament::setTenant($companyA);

    Livewire::test(ListExpenses::class)
        ->assertCanSeeTableRecords([$expenseA])
        ->assertCanNotSeeTableRecords([$expenseB])
        ->assertTableActionDoesNotExist('delete', record: $expenseA);
    Livewire::test(ViewExpense::class, ['record' => $expenseA->getRouteKey()])->assertSuccessful();
    $this->get(ExpenseResource::getUrl('view', ['record' => $expenseB], tenant: $companyA))->assertNotFound();
});

it('shows a confirmed owner preview for Contract movement without future-slice controls', function () {
    $manager = User::factory()->create();
    $company = Company::factory()->create();
    grantExpenseResource($manager, $company);
    $exercise = Exercise::factory()->for($company)->create();
    $expense = Expense::factory()->forExercise($exercise)->create();
    ExpenseLine::factory()->actual()->for($expense)->create();
    Contract::factory()->for($company)->create();
    $this->actingAs($manager);
    Filament::setTenant($company);

    Livewire::test(ViewExpense::class, ['record' => $expense->getRouteKey()])
        ->mountAction('moveOrReclassify')
        ->assertSchemaComponentExists('contract_id')
        ->assertSchemaComponentExists('supplier_replacement_acknowledged')
        ->assertSchemaComponentExists('impact_preview')
        ->assertSchemaComponentExists('impact_confirmed')
        ->assertSchemaComponentDoesNotExist('contract_cycle_id')
        ->assertSchemaComponentDoesNotExist('invoice_id')
        ->assertSchemaComponentDoesNotExist('payment_id');
});
