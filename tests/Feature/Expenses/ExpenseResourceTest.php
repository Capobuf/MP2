<?php

use App\Domain\Company\AuditEventType;
use App\Domain\Company\Capability;
use App\Domain\Expenses\ExerciseStatus;
use App\Filament\Resources\Expenses\ExpenseResource;
use App\Filament\Resources\Expenses\Pages\CreateExpense;
use App\Filament\Resources\Expenses\Pages\EditExpense;
use App\Filament\Resources\Expenses\Pages\ListExpenses;
use App\Filament\Resources\Expenses\Pages\ViewExpense;
use App\Models\Attachment;
use App\Models\AuditEvent;
use App\Models\BudgetSnapshot;
use App\Models\Company;
use App\Models\CompanyCapability;
use App\Models\Contract;
use App\Models\ContractLifecycleFact;
use App\Models\CostCenter;
use App\Models\Exercise;
use App\Models\Expense;
use App\Models\ExpenseLine;
use App\Models\Proposal;
use App\Models\Supplier;
use App\Models\User;
use App\Support\ExerciseContext;
use Filament\Facades\Filament;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Select;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
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
    Storage::fake('local');

    $firstAttachment = UploadedFile::fake()->createWithContent('preventivo.pdf', 'primo allegato');
    $secondAttachment = UploadedFile::fake()->createWithContent('dettaglio.txt', 'secondo allegato');

    Livewire::test(CreateExpense::class)
        ->assertFormFieldDoesNotExist('exercise_id')
        ->assertFormFieldExists('container')
        ->assertFormComponentActionHidden('supplier_id', 'createOption')
        ->assertFormComponentActionHidden('direct_cost_center_id', 'createOption')
        ->assertFormFieldExists('project_id')
        ->assertFormFieldExists('contract_id')
        ->assertFormFieldExists('attachments', fn ($field): bool => $field instanceof FileUpload && $field->isMultiple())
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
            'attachments' => [$firstAttachment, $secondAttachment],
            'lines' => [['type' => 'estimate', 'amount' => '100.00']],
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    expect(Expense::query()->sole()->exercise_id)->toBe($exercise->id)
        ->and(ExpenseLine::query()->count())->toBe(1)
        ->and(Attachment::query()->where('expense_id', Expense::query()->sole()->id)->orderBy('original_name')->pluck('original_name')->all())->toBe([
            'dettaglio.txt',
            'preventivo.pdf',
        ])
        ->and(AuditEvent::query()->where('event_type', AuditEventType::AttachmentUploaded)->count())->toBe(2);

    Attachment::query()->each(fn (Attachment $attachment) => Storage::disk('local')->assertExists($attachment->storage_path));
});

it('duplicates a line while creating an Expense', function () {
    $manager = User::factory()->create();
    $company = Company::factory()->create();
    grantExpenseResource($manager, $company);
    $exercise = Exercise::factory()->for($company)->create(['year' => now($company->timezone)->year]);
    $this->actingAs($manager);
    Filament::setTenant($company);
    app(ExerciseContext::class)->select($company, $exercise->id);

    $component = Livewire::test(CreateExpense::class);
    $lineKey = array_key_first((array) $component->get('data.lines'));

    $component
        ->set("data.lines.{$lineKey}.type", 'estimate')
        ->set("data.lines.{$lineKey}.note", 'Canone mensile')
        ->set("data.lines.{$lineKey}.unit_amount", '100')
        ->set("data.lines.{$lineKey}.quantity", '2')
        ->assertFormComponentActionVisible('lines', 'clone', ['item' => $lineKey])
        ->callFormComponentAction('lines', 'clone', arguments: ['item' => $lineKey]);

    $lines = array_values((array) $component->get('data.lines'));

    expect($lines)->toHaveCount(2)
        ->and($lines[1])->toMatchArray([
            'type' => 'estimate',
            'note' => 'Canone mensile',
            'unit_amount' => '100',
            'quantity' => '2',
            'amount' => '200.00',
        ]);
});

it('edits existing Lines and saves a duplicated Line with a new identity', function () {
    $manager = User::factory()->create();
    $company = Company::factory()->create();
    grantExpenseResource($manager, $company);
    $exercise = Exercise::factory()->for($company)->create(['year' => now($company->timezone)->year]);
    $expense = Expense::factory()->forExercise($exercise)->create(['description' => 'Canoni']);
    $estimate = ExpenseLine::factory()->for($expense)->create([
        'type' => 'estimate',
        'amount' => '100.00',
        'note' => 'Stima iniziale',
    ]);
    $actual = ExpenseLine::factory()->actual()->for($expense)->create([
        'amount' => '25.00',
        'note' => 'Prima fattura',
    ]);
    $this->actingAs($manager);
    Filament::setTenant($company);

    $component = Livewire::test(EditExpense::class, ['record' => $expense->getRouteKey()]);
    $lines = (array) $component->get('data.lines');
    $estimateKey = (string) collect($lines)->search(fn (array $line): bool => $line['line_id'] === $estimate->id);
    $actualKey = (string) collect($lines)->search(fn (array $line): bool => $line['line_id'] === $actual->id);

    $component
        ->assertFormComponentActionVisible('lines', 'clone', ['item' => $actualKey])
        ->assertFormComponentActionHidden('lines', 'delete', ['item' => $actualKey])
        ->set("data.lines.{$estimateKey}.amount", '120.00')
        ->callFormComponentAction('lines', 'clone', arguments: ['item' => $actualKey]);

    $clonedKey = array_values(array_diff(
        array_keys((array) $component->get('data.lines')),
        array_keys($lines),
    ))[0];

    $component
        ->assertSet("data.lines.{$clonedKey}.line_id", null)
        ->assertFormComponentActionVisible('lines', 'delete', ['item' => $clonedKey])
        ->set("data.lines.{$clonedKey}.amount", '30.00')
        ->set("data.lines.{$clonedKey}.note", 'Seconda fattura')
        ->call('save')
        ->assertHasNoFormErrors();

    $expense->refresh();
    expect($expense->lines()->count())->toBe(3)
        ->and($expense->lines()->findOrFail($estimate->id)->amount)->toBe('120.00')
        ->and($expense->lines()->findOrFail($actual->id)->amount)->toBe('25.00')
        ->and($expense->lines()->whereNotIn('id', [$estimate->id, $actual->id])->sole()->amount)->toBe('30.00')
        ->and(AuditEvent::query()->where('event_type', AuditEventType::ExpenseLineUpdated)->where('subject_id', $estimate->id)->count())->toBe(1)
        ->and(AuditEvent::query()->where('event_type', AuditEventType::ExpenseLineCreated)->count())->toBe(1);
});

it('rolls back every Line change when one row in the complete edit form is invalid', function () {
    $manager = User::factory()->create();
    $company = Company::factory()->create();
    grantExpenseResource($manager, $company);
    $exercise = Exercise::factory()->for($company)->create(['year' => now($company->timezone)->year]);
    $expense = Expense::factory()->forExercise($exercise)->create();
    $line = ExpenseLine::factory()->for($expense)->create(['amount' => '100.00']);
    $this->actingAs($manager);
    Filament::setTenant($company);

    $component = Livewire::withQueryParams(['addLine' => 1])
        ->test(EditExpense::class, ['record' => $expense->getRouteKey()]);
    $keys = array_keys((array) $component->get('data.lines'));

    $component
        ->set("data.lines.{$keys[0]}.amount", '125.00')
        ->set("data.lines.{$keys[1]}.type", 'actual')
        ->set("data.lines.{$keys[1]}.amount", '0.00')
        ->call('save')
        ->assertHasErrors(['data.lines.1.note']);

    expect($line->fresh()->amount)->toBe('100.00')
        ->and($expense->lines()->count())->toBe(1)
        ->and(AuditEvent::query()->whereIn('event_type', [
            AuditEventType::ExpenseLineUpdated,
            AuditEventType::ExpenseLineCreated,
        ])->count())->toBe(0);
});

it('requires the canonical reason when an Estimate changes after Budget approval', function () {
    $manager = User::factory()->create();
    $company = Company::factory()->create();
    grantExpenseResource($manager, $company);
    $exercise = Exercise::factory()->for($company)->create(['year' => now($company->timezone)->year]);
    $proposal = Proposal::factory()->for($company)->for($exercise)->create(['created_by_id' => $manager->id]);
    BudgetSnapshot::factory()->for($proposal)->create([
        'company_id' => $company->id,
        'exercise_id' => $exercise->id,
        'approved_by_id' => $manager->id,
    ]);
    $expense = Expense::factory()->forExercise($exercise)->create();
    $line = ExpenseLine::factory()->for($expense)->create(['type' => 'estimate', 'amount' => '100.00']);
    $this->actingAs($manager);
    Filament::setTenant($company);

    $component = Livewire::test(EditExpense::class, ['record' => $expense->getRouteKey()]);
    $lineKey = array_key_first((array) $component->get('data.lines'));

    $component
        ->assertFormFieldHidden('change_reason')
        ->set("data.lines.{$lineKey}.amount", '125.00')
        ->assertFormFieldVisible('change_reason')
        ->call('save')
        ->assertHasFormErrors(['change_reason']);

    expect($line->fresh()->amount)->toBe('100.00');

    $component
        ->set('data.change_reason', 'Aggiornamento della Stima')
        ->call('save')
        ->assertHasNoFormErrors();

    expect($line->fresh()->amount)->toBe('125.00');
});

it('shows the creation reason only after a Budget and only for a non-zero Expense', function () {
    $manager = User::factory()->create();
    $company = Company::factory()->create();
    grantExpenseResource($manager, $company);
    $exercise = Exercise::factory()->for($company)->create(['year' => now($company->timezone)->year]);
    $proposal = Proposal::factory()->for($company)->for($exercise)->create(['created_by_id' => $manager->id]);
    BudgetSnapshot::factory()->for($proposal)->create([
        'company_id' => $company->id,
        'exercise_id' => $exercise->id,
        'approved_by_id' => $manager->id,
    ]);
    $this->actingAs($manager);
    Filament::setTenant($company);
    app(ExerciseContext::class)->select($company, $exercise->id);

    $component = Livewire::test(CreateExpense::class)
        ->fillForm(['lines' => [['type' => 'estimate', 'amount' => '0.00', 'note' => 'Voce vuota']]])
        ->assertFormFieldHidden('change_reason');

    $component
        ->fillForm(['lines' => [['type' => 'estimate', 'amount' => '10.00']]])
        ->assertFormFieldVisible('change_reason')
        ->fillForm(['description' => 'Nuova spesa', 'change_reason' => 'Aggiornamento del piano'])
        ->call('create')
        ->assertHasNoFormErrors();
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

it('suggests the authoritative Total from unit amount and quantity without exposing a unit of measure', function () {
    $manager = User::factory()->create();
    $company = Company::factory()->create();
    grantExpenseResource($manager, $company);
    $exercise = Exercise::factory()->for($company)->create(['year' => now($company->timezone)->year]);
    $this->actingAs($manager);
    Filament::setTenant($company);
    app(ExerciseContext::class)->select($company, $exercise->id);

    $component = Livewire::test(CreateExpense::class);
    $lineKey = array_key_first((array) $component->get('data.lines'));

    $component
        ->assertFormFieldDoesNotExist("lines.{$lineKey}.unit_of_measure")
        ->set("data.lines.{$lineKey}.unit_amount", '1200')
        ->set("data.lines.{$lineKey}.quantity", '2')
        ->assertSet("data.lines.{$lineKey}.amount", '2400.00')
        ->assertSet("data.lines.{$lineKey}.suggested_amount", '2400.00')
        ->set("data.lines.{$lineKey}.amount", '2500')
        ->set("data.lines.{$lineKey}.quantity", '3')
        ->assertSet("data.lines.{$lineKey}.amount", '2500')
        ->assertSet("data.lines.{$lineKey}.suggested_amount", '3600.00');
});

it('tolerates incomplete decimal input while editing a suggested Total', function () {
    $manager = User::factory()->create();
    $company = Company::factory()->create();
    grantExpenseResource($manager, $company);
    $exercise = Exercise::factory()->for($company)->create(['year' => now($company->timezone)->year]);
    $this->actingAs($manager);
    Filament::setTenant($company);
    app(ExerciseContext::class)->select($company, $exercise->id);

    $component = Livewire::test(CreateExpense::class);
    $lineKey = array_key_first((array) $component->get('data.lines'));

    $component
        ->set("data.lines.{$lineKey}.unit_amount", '1200')
        ->set("data.lines.{$lineKey}.quantity", '2')
        ->set("data.lines.{$lineKey}.amount", '2500')
        ->set("data.lines.{$lineKey}.unit_amount", '1200,')
        ->assertSet("data.lines.{$lineKey}.amount", '2500')
        ->assertSet("data.lines.{$lineKey}.suggested_amount", null);
});

it('shows persisted descriptive decimals without insignificant trailing zeroes', function () {
    $manager = User::factory()->create();
    $company = Company::factory()->create();
    grantExpenseResource($manager, $company);
    $exercise = Exercise::factory()->for($company)->create(['year' => now($company->timezone)->year]);
    $expense = Expense::factory()->forExercise($exercise)->create();
    ExpenseLine::factory()->for($expense)->create([
        'amount' => '3000.00',
        'quantity' => '1.000000',
        'unit_amount' => '3000.000000',
    ]);
    $this->actingAs($manager);
    Filament::setTenant($company);

    $component = Livewire::test(EditExpense::class, ['record' => $expense->getRouteKey()]);
    $lineKey = array_key_first((array) $component->get('data.lines'));

    $component
        ->assertSet("data.lines.{$lineKey}.quantity", '1')
        ->assertSet("data.lines.{$lineKey}.unit_amount", '3000');
});

it('allows only Actual lines when creating an Expense for a Contract', function () {
    $manager = User::factory()->create();
    $company = Company::factory()->create();
    grantExpenseResource($manager, $company);
    $exercise = Exercise::factory()->for($company)->create(['year' => now($company->timezone)->year]);
    $contract = Contract::factory()->for($company)->create();
    ContractLifecycleFact::factory()->forContract($contract)->create([
        'declared_contractual_date' => "{$exercise->year}-01-01",
        'state_change_date' => "{$exercise->year}-01-01",
    ]);
    $this->actingAs($manager);
    Filament::setTenant($company);
    app(ExerciseContext::class)->select($company, $exercise->id);

    $component = Livewire::withQueryParams(['contract' => $contract->id])->test(CreateExpense::class);
    $lineKey = array_key_first((array) $component->get('data.lines'));

    $component
        ->assertFormSet(['container' => 'contract', 'contract_id' => $contract->id])
        ->assertSet("data.lines.{$lineKey}.type", 'actual')
        ->assertFormFieldExists(
            "lines.{$lineKey}.type",
            fn (Select $field): bool => $field->getOptions() === ['actual' => 'Effettivo'],
        )
        ->fillForm(['description' => 'Giornata di consulenza'])
        ->set("data.lines.{$lineKey}.amount", '1200')
        ->call('create')
        ->assertHasNoFormErrors();

    expect(Expense::query()->sole()->contract_id)->toBe($contract->id)
        ->and(ExpenseLine::query()->sole()->lineType()->value)->toBe('actual');
});

it('changes existing line types to Actual when the Expense container becomes a Contract', function () {
    $manager = User::factory()->create();
    $company = Company::factory()->create();
    grantExpenseResource($manager, $company);
    $exercise = Exercise::factory()->for($company)->create(['year' => now($company->timezone)->year]);
    $contract = Contract::factory()->for($company)->create();
    $this->actingAs($manager);
    Filament::setTenant($company);
    app(ExerciseContext::class)->select($company, $exercise->id);

    $component = Livewire::test(CreateExpense::class);
    $lineKey = array_key_first((array) $component->get('data.lines'));

    $component
        ->assertFormFieldExists(
            "lines.{$lineKey}.type",
            fn (Select $field): bool => $field->getOptions() === ['estimate' => 'Stima', 'actual' => 'Effettivo'],
        )
        ->set("data.lines.{$lineKey}.type", 'estimate')
        ->set('data.container', 'contract')
        ->set('data.contract_id', $contract->id)
        ->assertSet("data.lines.{$lineKey}.type", 'actual')
        ->assertFormFieldExists(
            "lines.{$lineKey}.type",
            fn (Select $field): bool => $field->getOptions() === ['actual' => 'Effettivo'],
        );
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
