<?php

use App\Domain\Company\Capability;
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
use App\Models\AuditEvent;
use App\Models\Company;
use App\Models\CompanyCapability;
use App\Models\Contract;
use App\Models\ContractCondition;
use App\Models\ContractLifecycleFact;
use App\Models\Exercise;
use App\Models\Expense;
use App\Models\ExpenseLine;
use App\Models\Supplier;
use App\Models\User;
use App\Support\ExerciseContext;
use Carbon\CarbonImmutable;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    CarbonImmutable::setTestNow('2026-08-20 10:00:00 Europe/Rome');
});

afterEach(function () {
    CarbonImmutable::setTestNow();
});

function grantContractResource(User $user, Company $company, bool $manage = true): void
{
    foreach ($manage ? [Capability::View, Capability::ManageOperations] : [Capability::View] as $capability) {
        CompanyCapability::query()->create([
            'company_id' => $company->id,
            'user_id' => $user->id,
            'capability' => $capability,
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
    Filament::setTenant($company);

    Livewire::test(CreateContract::class)
        ->assertSee('Le date di fattura e pagamento appartengono alle Spese.')
        ->assertSee('non producono prorata')
        ->assertDontSee('Salva & nuovo')
        ->assertFormFieldExists('title')
        ->assertFormFieldExists('supplier_id')
        ->assertFormComponentActionHidden('supplier_id', 'createOption')
        ->assertFormFieldExists('conditions')
        ->assertFormFieldExists('contractual_start_date')
        ->assertFormFieldExists('duration_type')
        ->assertFormFieldDoesNotExist('next_expiry_date')
        ->assertFormFieldDoesNotExist('automatic_renewal')
        ->assertFormFieldDoesNotExist('renewal_duration_months')
        ->assertFormFieldDoesNotExist('notice_days')
        ->assertFormFieldDoesNotExist('replacement_contract_id')
        ->assertFormFieldDoesNotExist('proposal_id')
        ->assertFormFieldDoesNotExist('budget_id')
        ->assertFormSet(function (array $state) use ($exercise): array {
            expect(array_values($state['classifications']))->toBe([[
                'exercise_id' => $exercise->id,
                'cost_center_id' => null,
            ]]);

            return [];
        })
        ->fillForm(['duration_type' => 'indefinite'])
        ->assertFormFieldDoesNotExist('next_expiry_date')
        ->assertFormFieldDoesNotExist('renewal_duration_months')
        ->assertFormFieldDoesNotExist('notice_days')
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
            'contractual_start_date' => '2026-01-01',
            'duration_type' => 'indefinite',
            'automatic_renewal' => true,
            'renewal_duration_months' => null,
            'conditions' => [[
                'amount' => '120,50',
                'cycle' => 'monthly',
                'attribution_mode' => 'cycle_start',
                'valid_from' => '2026-01-01',
                'valid_to' => null,
            ]],
            'classifications' => [[
                'exercise_id' => $exercise->id,
                'cost_center_id' => null,
            ]],
        ])
        ->assertFormSet(['renewal_effective_from' => '2026-01-01'])
        ->call('create')
        ->assertHasNoFormErrors();

    expect(Contract::query()->count())->toBe(1)
        ->and(ContractCondition::query()->count())->toBe(1)
        ->and(ContractCondition::query()->sole()->amount)->toBe('120.50');
});

it('creates and selects a Supplier inline with a dedicated operation', function () {
    $manager = User::factory()->create();
    $company = Company::factory()->create();
    grantContractResource($manager, $company);
    CompanyCapability::query()->create([
        'company_id' => $company->id,
        'user_id' => $manager->id,
        'capability' => Capability::ManageMasterData,
    ]);
    $this->actingAs($manager);
    Filament::setTenant($company);

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
    Filament::setTenant($companyA);

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
        ->assertSee('Visibile')
        ->assertSee($contractA->supplier->legal_name)
        ->assertSee('Attivo')
        ->assertSee('Scadenza non definita')
        ->assertSee('Nessuna condizione economica vigente')
        ->assertSee('Non classificato')
        ->assertSee('Situazioni annuali')
        ->assertSee('Condizioni economiche')
        ->assertSee('Rinnovi e scadenze')
        ->assertSee('Ciclo di vita')
        ->assertSee('Spese')
        ->assertSee('Classificazioni')
        ->assertSee('Progetti collegati')
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
    Filament::setTenant($company);
    app(ExerciseContext::class)->select($company, $selectedExercise->id);

    Livewire::test(ViewContract::class, ['record' => $contract->getRouteKey()])
        ->assertSuccessful()
        ->assertSee('CONDIZIONE CORRENTE')
        ->assertDontSee('CONDIZIONE TERMINATA')
        ->assertDontSee('CONDIZIONE FUTURA')
        ->assertDontSee('CONDIZIONE ANNULLATA')
        ->assertSee('Esercizio selezionato')
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
    Filament::setTenant($company);
    app(ExerciseContext::class)->select($company, $exercise->id);

    $component = Livewire::test(ViewContract::class, ['record' => $contract->getRouteKey()])
        ->assertSuccessful()
        ->assertSee('12 cicli compongono l’Allocato')
        ->assertSee('Primo ciclo incluso')
        ->assertSee('Ultimo ciclo incluso')
        ->assertSee('Vedi tutti i 12 cicli')
        ->assertSeeHtml('mp2-list-preview-has-more')
        ->assertDontSeeHtml('<th scope="col">Composizione</th>')
        ->assertSee('Dettaglio allocato 2026');

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
    Filament::setTenant($company);
    app(ExerciseContext::class)->select($company, $exercise->id);

    Livewire::test(ViewContract::class, ['record' => $contract->getRouteKey()])
        ->assertSuccessful()
        ->assertSee('4 cicli compongono l’Allocato')
        ->assertDontSee('Vedi tutti i 4 cicli')
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
    Filament::setTenant($company);

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
    Filament::setTenant($company);

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
