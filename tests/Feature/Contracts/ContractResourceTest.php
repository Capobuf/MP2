<?php

use App\Domain\Company\AuditEventType;
use App\Filament\Forms\AttachmentUpload;
use App\Filament\Resources\Contracts\ContractResource;
use App\Filament\Resources\Contracts\Pages\CreateContract;
use App\Filament\Resources\Contracts\Pages\EditContract;
use App\Filament\Resources\Contracts\Pages\ListContracts;
use App\Filament\Resources\Contracts\Pages\ViewContract;
use App\Filament\Resources\Contracts\RelationManagers\ContractAttachmentsRelationManager;
use App\Filament\Resources\Contracts\RelationManagers\ContractClassificationsRelationManager;
use App\Filament\Resources\Contracts\RelationManagers\ContractConditionsRelationManager;
use App\Filament\Resources\Contracts\RelationManagers\ContractExpensesRelationManager;
use App\Filament\Resources\Contracts\RelationManagers\ContractLifecycleRelationManager;
use App\Filament\Resources\Contracts\RelationManagers\ContractRenewalsRelationManager;
use App\Filament\Resources\Contracts\RelationManagers\ProjectContractLinksRelationManager;
use App\Models\Attachment;
use App\Models\AuditEvent;
use App\Models\Company;
use App\Models\Contract;
use App\Models\ContractCondition;
use App\Models\ContractExerciseClassification;
use App\Models\ContractLifecycleFact;
use App\Models\CostCenter;
use App\Models\Exercise;
use App\Models\Expense;
use App\Models\ExpenseLine;
use App\Models\Supplier;
use App\Models\User;
use App\Support\ExerciseContext;
use Carbon\CarbonImmutable;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Filament\Forms\Components\TextInput;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\Support\TestPermissions;

uses(RefreshDatabase::class);

beforeEach(function () {
    CarbonImmutable::setTestNow('2026-08-20 10:00:00 Europe/Rome');
});

afterEach(function () {
    CarbonImmutable::setTestNow();
});

function grantContractResource(User $user, Company $company, bool $manage = true): void
{
    foreach ($manage ? [TestPermissions::VIEW, TestPermissions::MANAGE_OPERATIONS] : [TestPermissions::VIEW] as $capability) {
        grantTestPermissions([
            'company_id' => $company->id,
            'user' => $user,
            'permissions' => $capability,
        ]);
    }
}

it('creates a Contract through the tenant form and exposes only S5 inputs', function () {
    $manager = User::factory()->create();
    $company = Company::factory()->create(['timezone' => 'Europe/Rome']);
    grantContractResource($manager, $company);
    $exercise = Exercise::factory()->for($company)->create(['year' => 2026]);
    $supplier = Supplier::factory()->for($company)->create();
    $this->actingAs($manager);
    Filament::setTenant(($company)->tenantCompany);
    Storage::fake('local');

    $firstAttachment = UploadedFile::fake()->createWithContent('contratto.pdf', 'primo allegato');
    $secondAttachment = UploadedFile::fake()->createWithContent('condizioni.txt', 'secondo allegato');

    Livewire::test(CreateContract::class)
        ->assertSee('Le date di fattura e pagamento appartengono alle Spese.')
        ->assertSee('non sono calcolati prorata')
        ->assertSee('non determina la scadenza del Contratto')
        ->assertSee('Durata Contrattuale')
        ->assertSee('Con Scadenza')
        ->assertSee('Senza Scadenza')
        ->assertSee('Scadenza da Definire')
        ->assertDontSee('Sì, la scadenza è definita')
        ->assertDontSee('Nessuna scadenza o data limite di disdetta verrà calcolata.')
        ->assertDontSee('Le condizioni economiche senza “Valida fino al” continuano a generare Stime')
        ->assertDontSee('Salva & nuovo')
        ->assertFormFieldExists('title')
        ->assertFormFieldExists('supplier_id')
        ->assertFormFieldExists('default_cost_center_id')
        ->assertFormFieldExists('attachments', fn ($field): bool => $field instanceof AttachmentUpload && $field->isMultiple())
        ->assertFormComponentActionHidden('supplier_id', 'createOption')
        ->assertFormFieldExists('conditions')
        ->assertFormFieldExists('contractual_start_date', fn ($field): bool => $field instanceof TextInput && $field->getMask() === '99/99/9999')
        ->assertFormFieldExists('duration_type')
        ->assertFormFieldDoesNotExist('next_expiry_date')
        ->assertFormFieldDoesNotExist('automatic_renewal')
        ->assertFormFieldDoesNotExist('renewal_duration_months')
        ->assertFormFieldDoesNotExist('notice_days')
        ->assertFormFieldDoesNotExist('replacement_contract_id')
        ->assertFormFieldDoesNotExist('proposal_id')
        ->assertFormFieldDoesNotExist('budget_id')
        ->assertFormSet(function (array $state) use ($exercise): array {
            expect($state['duration_type'])->toBe('undefined')
                ->and($state['automatic_renewal'])->toBeTrue();

            expect(array_values($state['classifications']))->toBe([[
                'exercise_id' => $exercise->id,
                'cost_center_selection' => '__default__',
            ]]);

            return [];
        })
        ->fillForm(['duration_type' => 'indefinite'])
        ->assertFormFieldDoesNotExist('next_expiry_date')
        ->assertFormFieldDoesNotExist('renewal_duration_months')
        ->assertFormFieldDoesNotExist('notice_days')
        ->assertFormSet(['automatic_renewal' => false])
        ->fillForm(['duration_type' => 'undefined'])
        ->assertFormFieldDoesNotExist('next_expiry_date')
        ->assertFormFieldDoesNotExist('renewal_duration_months')
        ->assertFormFieldDoesNotExist('notice_days')
        ->assertFormSet(['automatic_renewal' => true])
        ->fillForm(['duration_type' => 'fixed'])
        ->assertFormFieldExists('next_expiry_date')
        ->assertFormFieldExists('automatic_renewal')
        ->assertFormFieldExists('renewal_duration_months')
        ->assertFormFieldExists('notice_days')
        ->fillForm(['renewal_duration_months' => 12, 'automatic_renewal' => false])
        ->assertFormFieldDoesNotExist('renewal_duration_months')
        ->assertFormSet(['renewal_duration_months' => null])
        ->fillForm([
            'title' => 'Contratto energia',
            'supplier_id' => $supplier->id,
            'contractual_start_date' => '01/01/2026',
            'duration_type' => 'indefinite',
            'attachments' => [$firstAttachment, $secondAttachment],
            'conditions' => [[
                'amount' => '120,50',
                'cycle' => 'monthly',
                'attribution_mode' => 'cycle_start',
                'valid_from' => '01/01/2026',
                'valid_to' => null,
            ]],
            'classifications' => [[
                'exercise_id' => $exercise->id,
                'cost_center_selection' => '__default__',
            ]],
        ])
        ->assertFormSet([
            'contractual_start_date' => '01/01/2026',
            'renewal_effective_from' => '2026-01-01',
            'automatic_renewal' => false,
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    expect(Contract::query()->count())->toBe(1)
        ->and(ContractCondition::query()->count())->toBe(1)
        ->and(ContractCondition::query()->sole()->amount)->toBe('120.50')
        ->and(Contract::query()->sole()->contractualStartDate()->toDateString())->toBe('2026-01-01')
        ->and(ContractCondition::query()->sole()->validFrom()->toDateString())->toBe('2026-01-01')
        ->and(Contract::query()->sole()->automatic_renewal)->toBeFalse()
        ->and(Attachment::query()->where('contract_id', Contract::query()->sole()->id)->orderBy('original_name')->pluck('original_name')->all())->toBe([
            'condizioni.txt',
            'contratto.pdf',
        ])
        ->and(AuditEvent::query()->where('event_type', AuditEventType::AttachmentUploaded)->count())->toBe(2);

    Attachment::query()->each(fn (Attachment $attachment) => Storage::disk('local')->assertExists($attachment->storage_path));
});

it('applies one default Cost Center while preserving annual exceptions', function () {
    $manager = User::factory()->create();
    $company = Company::factory()->create(['timezone' => 'Europe/Rome']);
    grantContractResource($manager, $company);
    $exercises = collect([
        Exercise::factory()->for($company)->create(['year' => 2026]),
        Exercise::factory()->for($company)->create(['year' => 2027]),
        Exercise::factory()->for($company)->create(['year' => 2028]),
    ]);
    $supplier = Supplier::factory()->for($company)->create();
    $defaultCostCenter = CostCenter::factory()->for($company)->create(['name' => 'IT']);
    $exceptionCostCenter = CostCenter::factory()->for($company)->create(['name' => 'Operations']);
    $this->actingAs($manager);
    Filament::setTenant(($company)->tenantCompany);

    Livewire::test(CreateContract::class)
        ->assertSee('Predefinito per tutti gli Esercizi Aperti')
        ->assertSee('Avanzate')
        ->fillForm([
            'title' => 'Contratto con classificazioni',
            'supplier_id' => $supplier->id,
            'default_cost_center_id' => $defaultCostCenter->id,
            'contractual_start_date' => '01/01/2026',
            'duration_type' => 'indefinite',
            'conditions' => [[
                'amount' => '100,00',
                'cycle' => 'monthly',
                'attribution_mode' => 'cycle_start',
                'valid_from' => '01/01/2026',
                'valid_to' => null,
            ]],
            'classifications' => [
                ['exercise_id' => $exercises[0]->id, 'cost_center_selection' => '__default__'],
                ['exercise_id' => $exercises[1]->id, 'cost_center_selection' => (string) $exceptionCostCenter->id],
                ['exercise_id' => $exercises[2]->id, 'cost_center_selection' => '__unclassified__'],
            ],
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    $classifications = ContractExerciseClassification::query()
        ->orderBy('exercise_id')
        ->pluck('cost_center_id', 'exercise_id');

    expect($classifications[$exercises[0]->id])->toBe($defaultCostCenter->id)
        ->and($classifications[$exercises[1]->id])->toBe($exceptionCostCenter->id)
        ->and($classifications[$exercises[2]->id])->toBeNull();
});

it('suggests the contractual start from the earliest economic condition', function () {
    $manager = User::factory()->create();
    $company = Company::factory()->create(['timezone' => 'Europe/Rome']);
    grantContractResource($manager, $company);
    $this->actingAs($manager);
    Filament::setTenant(($company)->tenantCompany);

    $component = Livewire::test(CreateContract::class);
    $conditionKey = array_key_first((array) $component->get('data.conditions'));

    $component
        ->set("data.conditions.{$conditionKey}.valid_from", '01/02/2026')
        ->assertFormSet([
            'contractual_start_date' => '01/02/2026',
            'renewal_effective_from' => '2026-02-01',
        ])
        ->set("data.conditions.{$conditionKey}.valid_from", '01/03/2026')
        ->assertFormSet([
            'contractual_start_date' => '01/03/2026',
            'renewal_effective_from' => '2026-03-01',
        ])
        ->set('data.conditions', [
            'first' => ['valid_from' => '01/03/2026'],
            'second' => ['valid_from' => '01/01/2026'],
        ])
        ->assertFormSet([
            'contractual_start_date' => '01/01/2026',
            'renewal_effective_from' => '2026-01-01',
        ]);
});

it('suggests editable contractual terms from the latest bounded economic condition', function () {
    $manager = User::factory()->create();
    $company = Company::factory()->create(['timezone' => 'Europe/Rome']);
    grantContractResource($manager, $company);
    $this->actingAs($manager);
    Filament::setTenant(($company)->tenantCompany);

    $component = Livewire::test(CreateContract::class);

    $component
        ->set('data.conditions', [
            'first' => ['valid_from' => '01/09/2025', 'valid_to' => '31/12/2025'],
            'second' => ['valid_from' => '01/01/2026', 'valid_to' => '31/12/2026'],
        ])
        ->assertFormSet([
            'duration_type' => 'fixed',
            'next_expiry_date' => '31/12/2026',
            'automatic_renewal' => true,
            'renewal_duration_months' => 12,
            'notice_days' => 30,
        ])
        ->assertFormFieldExists('next_expiry_date')
        ->assertFormFieldExists('automatic_renewal')
        ->assertFormFieldExists('renewal_duration_months')
        ->assertFormFieldExists('notice_days')
        ->set('data.next_expiry_date', '31/01/2027')
        ->set('data.renewal_duration_months', 6)
        ->set('data.notice_days', 60)
        ->set('data.conditions', [
            'first' => ['valid_from' => '01/09/2025', 'valid_to' => '31/12/2025'],
            'second' => ['valid_from' => '01/01/2026', 'valid_to' => '31/12/2027'],
        ])
        ->assertFormSet([
            'next_expiry_date' => '31/01/2027',
            'renewal_duration_months' => 6,
            'notice_days' => 60,
        ]);
});

it('returns an automatic duration suggestion to undefined when the economic term disappears', function () {
    $manager = User::factory()->create();
    $company = Company::factory()->create(['timezone' => 'Europe/Rome']);
    grantContractResource($manager, $company);
    $this->actingAs($manager);
    Filament::setTenant(($company)->tenantCompany);

    Livewire::test(CreateContract::class)
        ->set('data.conditions', [
            'first' => ['valid_from' => '01/01/2026', 'valid_to' => '31/12/2026'],
        ])
        ->assertFormSet([
            'duration_type' => 'fixed',
            'next_expiry_date' => '31/12/2026',
            'renewal_duration_months' => 12,
        ])
        ->set('data.conditions', [
            'first' => ['valid_from' => '01/01/2026', 'valid_to' => null],
        ])
        ->assertFormSet([
            'duration_type' => 'undefined',
            'next_expiry_date' => null,
            'automatic_renewal' => true,
            'renewal_duration_months' => null,
            'notice_days' => null,
        ])
        ->assertFormFieldDoesNotExist('next_expiry_date')
        ->assertFormFieldDoesNotExist('automatic_renewal')
        ->assertFormFieldDoesNotExist('renewal_duration_months')
        ->assertFormFieldDoesNotExist('notice_days');
});

it('preserves a manual duration choice during later economic-condition updates', function () {
    $manager = User::factory()->create();
    $company = Company::factory()->create(['timezone' => 'Europe/Rome']);
    grantContractResource($manager, $company);
    $this->actingAs($manager);
    Filament::setTenant(($company)->tenantCompany);

    Livewire::test(CreateContract::class)
        ->set('data.conditions', [
            'first' => ['amount' => '100,00', 'valid_from' => '01/01/2026', 'valid_to' => '31/12/2026'],
        ])
        ->assertFormSet(['duration_type' => 'fixed'])
        ->set('data.duration_type', 'indefinite')
        ->set('data.conditions', [
            'first' => ['amount' => '200,00', 'valid_from' => '01/01/2026', 'valid_to' => '31/12/2026'],
        ])
        ->assertFormSet([
            'duration_type' => 'indefinite',
            'next_expiry_date' => null,
            'automatic_renewal' => false,
            'renewal_duration_months' => null,
            'notice_days' => null,
        ])
        ->set('data.duration_type', 'undefined')
        ->set('data.conditions', [
            'first' => ['amount' => '300,00', 'valid_from' => '01/01/2026', 'valid_to' => '31/12/2026'],
        ])
        ->assertFormSet([
            'duration_type' => 'undefined',
            'next_expiry_date' => null,
            'automatic_renewal' => true,
            'renewal_duration_months' => null,
            'notice_days' => null,
        ]);
});

it('creates and selects a Supplier inline with a dedicated operation', function () {
    $manager = User::factory()->create();
    $company = Company::factory()->create();
    grantContractResource($manager, $company);
    grantTestPermissions([
        'company_id' => $company->id,
        'user' => $manager,
        'permissions' => TestPermissions::MANAGE_MASTER_DATA,
    ]);
    $this->actingAs($manager);
    Filament::setTenant(($company)->tenantCompany);

    $component = Livewire::test(CreateContract::class)
        ->assertFormComponentActionVisible('supplier_id', 'createOption')
        ->callFormComponentAction('supplier_id', 'createOption', [
            'legal_name' => 'Fornitore contratto inline',
            'vat_number' => 'IT12345678901',
            'notes' => 'Creato dal Contratto',
        ]);

    $supplier = Supplier::query()->sole();
    $component->assertFormSet(['supplier_id' => $supplier->id]);

    expect(AuditEvent::query()->where('subject_type', Supplier::class)->sole()->operation_id)
        ->not->toBe($component->get('operationId'));
});

it('lists and views tenant Contracts with undefined expiry and annual situations', function () {
    $viewer = User::factory()->create();
    $companyA = Company::factory()->create(['timezone' => 'Europe/Rome']);
    $companyB = Company::factory()->create();
    grantContractResource($viewer, $companyA, false);
    Exercise::factory()->for($companyA)->create(['year' => 2026]);
    $contractA = Contract::factory()->for($companyA)->create([
        'title' => 'Visibile',
        'contractual_start_date' => '2026-01-01',
        'next_expiry_date' => null,
        'renewal_anchor_date' => null,
    ]);
    ContractLifecycleFact::factory()->forContract($contractA)->create();
    $contractB = Contract::factory()->for($companyB)->create(['title' => 'Segreto']);
    $this->actingAs($viewer);
    Filament::setTenant(($companyA)->tenantCompany);

    Livewire::test(ListContracts::class)
        ->assertCanSeeTableRecords([$contractA])
        ->assertCanNotSeeTableRecords([$contractB])
        ->assertTableFilterExists('supplier')
        ->assertTableFilterExists('cost_center')
        ->assertTableFilterExists('automatic_renewal')
        ->assertTableFilterExists('next_expiry_date')
        ->assertTableFilterExists('archived_at')
        ->assertTableActionDoesNotExist('delete', record: $contractA)
        ->assertTableActionHidden('edit', record: $contractA);

    Livewire::test(ViewContract::class, ['record' => $contractA->getRouteKey()])
        ->assertSuccessful()
        ->assertSeeHtml('class="mp2-object-header')
        ->assertSeeHtml('data-object-icon="contract"')
        ->assertSee('Visibile')
        ->assertSee($contractA->supplier->legal_name)
        ->assertSee('Attivo')
        ->assertSee('Scadenza Non Definita')
        ->assertSee('Nessuna condizione economica vigente')
        ->assertSee('Non classificato')
        ->assertSee('Situazioni Annuali')
        ->assertSee('Condizioni Economiche')
        ->assertSee('Rinnovi e Scadenze')
        ->assertSee('Ciclo di Vita')
        ->assertSee('Spese')
        ->assertSee('Classificazioni')
        ->assertSee('Progetti Collegati')
        ->assertSee('Allegati')
        ->assertActionHidden('createContractActual')
        ->assertActionDoesNotExist('delete')
        ->assertDontSee('Sostituisce');

    $this->get(ContractResource::getUrl('view', ['record' => $contractB], tenant: $companyA))->assertNotFound();
    $this->get(ContractResource::getUrl('edit', ['record' => $contractA], tenant: $companyA))->assertForbidden();
});

it('renders the canonical current agreement and selected Exercise economics', function () {
    $manager = User::factory()->create();
    $company = Company::factory()->create(['timezone' => 'Europe/Rome']);
    grantContractResource($manager, $company);
    $selectedExercise = Exercise::factory()->for($company)->create(['year' => 2025]);
    Exercise::factory()->for($company)->create(['year' => 2026]);
    $contract = Contract::factory()->for($company)->create([
        'title' => 'Servizi infrastrutturali',
        'contractual_start_date' => '2025-01-01',
        'next_expiry_date' => null,
        'renewal_anchor_date' => null,
        'automatic_renewal' => false,
        'renewal_duration_months' => null,
    ]);
    ContractLifecycleFact::factory()->forContract($contract)->create([
        'declared_contractual_date' => '2025-01-01',
        'state_change_date' => '2025-01-01',
    ]);
    ContractCondition::factory()->forContract($contract)->create([
        'amount' => '100.00',
        'valid_from' => '2025-01-01',
        'valid_to' => '2025-12-31',
        'reason' => 'CONDIZIONE TERMINATA',
    ]);
    ContractCondition::factory()->forContract($contract)->create([
        'amount' => '200.00',
        'valid_from' => '2026-01-01',
        'valid_to' => null,
        'reason' => 'CONDIZIONE CORRENTE',
    ]);
    ContractCondition::factory()->forContract($contract)->create([
        'amount' => '300.00',
        'valid_from' => '2027-01-01',
        'valid_to' => null,
        'reason' => 'CONDIZIONE FUTURA',
    ]);
    ContractCondition::factory()->forContract($contract)->create([
        'amount' => '400.00',
        'valid_from' => '2026-01-01',
        'valid_to' => null,
        'reason' => 'CONDIZIONE ANNULLATA',
        'annulled_at' => '2026-01-02 10:00:00',
        'annulled_by_id' => $manager->id,
    ]);
    $expense = Expense::factory()->forExercise($selectedExercise)->for($contract)->create([
        'origin' => 'manual',
        'supplier_id' => $contract->supplier_id,
    ]);
    ExpenseLine::factory()->actual()->for($expense)->create(['amount' => '250.00']);

    $this->actingAs($manager);
    Filament::setTenant(($company)->tenantCompany);
    app(ExerciseContext::class)->select($company, $selectedExercise->id);

    Livewire::test(ViewContract::class, ['record' => $contract->getRouteKey()])
        ->assertSuccessful()
        ->assertSee('CONDIZIONE CORRENTE')
        ->assertDontSee('CONDIZIONE TERMINATA')
        ->assertDontSee('CONDIZIONE FUTURA')
        ->assertDontSee('CONDIZIONE ANNULLATA')
        ->assertSee('Esercizio Selezionato')
        ->assertSee('2025')
        ->assertSee('1.200,00')
        ->assertSee('250,00')
        ->assertSee('-950,00')
        ->assertSee('Non classificato')
        ->assertActionVisible('createContractActual')
        ->assertActionVisible('edit');
});

it('previews a long annual allocation composition and exposes every cycle on demand', function () {
    $manager = User::factory()->create();
    $company = Company::factory()->create(['timezone' => 'Europe/Rome']);
    grantContractResource($manager, $company);
    $exercise = Exercise::factory()->for($company)->create(['year' => 2026]);
    $contract = Contract::factory()->for($company)->create([
        'contractual_start_date' => '2026-01-01',
        'next_expiry_date' => null,
        'renewal_anchor_date' => null,
    ]);
    ContractLifecycleFact::factory()->forContract($contract)->create([
        'declared_contractual_date' => '2026-01-01',
        'state_change_date' => '2026-01-01',
    ]);
    ContractCondition::factory()->forContract($contract)->create([
        'amount' => '100.00',
        'cycle' => 'monthly',
        'attribution_mode' => 'cycle_start',
        'valid_from' => '2026-01-01',
        'valid_to' => '2026-12-31',
    ]);

    $this->actingAs($manager);
    Filament::setTenant(($company)->tenantCompany);
    app(ExerciseContext::class)->select($company, $exercise->id);

    $component = Livewire::test(ViewContract::class, ['record' => $contract->getRouteKey()])
        ->assertSuccessful()
        ->assertSee('12 Cicli Compongono l’Allocato')
        ->assertSee('Primo Ciclo Incluso')
        ->assertSee('Ultimo Ciclo Incluso')
        ->assertSee('Vedi Tutti i 12 Cicli')
        ->assertSeeHtml('mp2-list-preview-has-more')
        ->assertDontSeeHtml('<th scope="col">Composizione</th>')
        ->assertSee('Dettaglio Allocato 2026');

    $component->mountAction(TestAction::make('allocationDetail')->arguments(['year' => 2026]))
        ->assertMountedActionModalSee('Dettaglio Allocato 2026')
        ->assertMountedActionModalSee('12')
        ->assertMountedActionModalSee('01/01/2026')
        ->assertMountedActionModalSee('01/12/2026')
        ->assertMountedActionModalSee('1.200,00');
});

it('shows the complete allocation list without a fade when no cycles are hidden', function () {
    $viewer = User::factory()->create();
    $company = Company::factory()->create(['timezone' => 'Europe/Rome']);
    grantContractResource($viewer, $company, false);
    $exercise = Exercise::factory()->for($company)->create(['year' => 2026]);
    $contract = Contract::factory()->for($company)->create([
        'contractual_start_date' => '2026-01-01',
        'next_expiry_date' => null,
        'renewal_anchor_date' => null,
    ]);
    ContractLifecycleFact::factory()->forContract($contract)->create([
        'declared_contractual_date' => '2026-01-01',
        'state_change_date' => '2026-01-01',
    ]);
    ContractCondition::factory()->forContract($contract)->create([
        'amount' => '250.00',
        'cycle' => 'quarterly',
        'attribution_mode' => 'cycle_start',
        'valid_from' => '2026-01-01',
        'valid_to' => '2026-12-31',
    ]);

    $this->actingAs($viewer);
    Filament::setTenant(($company)->tenantCompany);
    app(ExerciseContext::class)->select($company, $exercise->id);

    Livewire::test(ViewContract::class, ['record' => $contract->getRouteKey()])
        ->assertSuccessful()
        ->assertSee('4 Cicli Compongono l’Allocato')
        ->assertDontSee('Vedi Tutti i 4 Cicli')
        ->assertDontSeeHtml('mp2-list-preview-has-more');
});

it('keeps lifecycle state separate from archive status on the object page', function () {
    $manager = User::factory()->create();
    $company = Company::factory()->create(['timezone' => 'Europe/Rome']);
    grantContractResource($manager, $company);
    Exercise::factory()->for($company)->create(['year' => 2026]);
    $contract = Contract::factory()->for($company)->create([
        'contractual_start_date' => '2026-01-01',
        'archived_at' => '2026-08-01 10:00:00',
    ]);
    ContractLifecycleFact::factory()->forContract($contract)->create([
        'declared_contractual_date' => '2026-01-01',
        'state_change_date' => '2026-01-01',
    ]);
    $this->actingAs($manager);
    Filament::setTenant(($company)->tenantCompany);

    Livewire::test(ViewContract::class, ['record' => $contract->getRouteKey()])
        ->assertSuccessful()
        ->assertSee('Attivo')
        ->assertSee('Archiviato')
        ->assertActionHidden('edit')
        ->assertActionHidden('createContractActual')
        ->assertActionVisible('restore');
});

it('allows descriptive and eligible Supplier edit while keeping contractual dates immutable', function () {
    $manager = User::factory()->create();
    $company = Company::factory()->create();
    grantContractResource($manager, $company);
    $contract = Contract::factory()->for($company)->create(['title' => 'Prima']);
    $replacement = Supplier::factory()->for($company)->create();
    $this->actingAs($manager);
    Filament::setTenant(($company)->tenantCompany);

    Livewire::test(EditContract::class, ['record' => $contract->getRouteKey()])
        ->assertFormFieldExists('title')
        ->assertFormFieldExists('notes')
        ->assertFormFieldExists('supplier_id')
        ->assertFormFieldDoesNotExist('contractual_start_date')
        ->assertFormFieldDoesNotExist('next_expiry_date')
        ->assertFormFieldDoesNotExist('automatic_renewal')
        ->fillForm(['title' => 'Dopo', 'notes' => 'Aggiornato', 'supplier_id' => $replacement->id])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($contract->refresh()->title)->toBe('Dopo')
        ->and($contract->supplier_id)->toBe($replacement->id)
        ->and($contract->revision)->toBe(1);
});

it('shows stored attachments inline and adds new ones through the Contract edit form', function () {
    $manager = User::factory()->create();
    $company = Company::factory()->create();
    grantContractResource($manager, $company);
    $contract = Contract::factory()->for($company)->create();
    $stored = Attachment::factory()->forContract($contract)->create([
        'original_name' => 'contratto-esistente.pdf',
        'uploaded_by_id' => $manager->id,
    ]);
    $this->actingAs($manager);
    Filament::setTenant($company->tenantCompany);
    Storage::fake('local');

    Livewire::test(EditContract::class, ['record' => $contract->getRouteKey()])
        ->assertFormFieldExists("attachment_{$stored->id}", fn ($field): bool => $field instanceof AttachmentUpload)
        ->assertFormFieldExists('attachments', fn ($field): bool => $field instanceof AttachmentUpload && $field->isMultiple())
        ->fillForm([
            'attachments' => [UploadedFile::fake()->create('nuove-condizioni.pdf', 10, 'application/pdf')],
        ])
        ->call('save')
        ->assertHasNoFormErrors();

    $uploaded = $contract->attachments()->where('original_name', 'nuove-condizioni.pdf')->sole();

    Storage::disk('local')->assertExists($uploaded->storage_path);
    expect($contract->attachments()->attached()->count())->toBe(2)
        ->and(AuditEvent::query()->where('event_type', AuditEventType::AttachmentUploaded)->count())->toBe(1);
});

it('registers explicit lifecycle and renewal management surfaces', function () {
    expect(ContractResource::getRelations())->toBe([
        ContractConditionsRelationManager::class,
        ContractRenewalsRelationManager::class,
        ContractLifecycleRelationManager::class,
        ContractExpensesRelationManager::class,
        ContractClassificationsRelationManager::class,
        ProjectContractLinksRelationManager::class,
        ContractAttachmentsRelationManager::class,
    ]);
});
