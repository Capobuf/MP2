<?php

use App\Domain\Company\Capability;
use App\Filament\Resources\Contracts\ContractResource;
use App\Filament\Resources\Contracts\Pages\CreateContract;
use App\Filament\Resources\Contracts\Pages\EditContract;
use App\Filament\Resources\Contracts\Pages\ListContracts;
use App\Filament\Resources\Contracts\Pages\ViewContract;
use App\Filament\Resources\Contracts\RelationManagers\ContractLifecycleRelationManager;
use App\Filament\Resources\Contracts\RelationManagers\ContractRenewalsRelationManager;
use App\Models\AuditEvent;
use App\Models\Company;
use App\Models\CompanyCapability;
use App\Models\Contract;
use App\Models\ContractCondition;
use App\Models\ContractLifecycleFact;
use App\Models\Exercise;
use App\Models\Supplier;
use App\Models\User;
use Carbon\CarbonImmutable;
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
        ->assertSee('Nessun prorata')
        ->assertDontSee('Salva & nuovo')
        ->assertFormFieldExists('title')
        ->assertFormFieldExists('supplier_id')
        ->assertFormComponentActionHidden('supplier_id', 'createOption')
        ->assertFormFieldExists('contractual_start_date')
        ->assertFormFieldExists('next_expiry_date')
        ->assertFormFieldExists('renewal_effective_from')
        ->assertFormFieldExists('automatic_renewal')
        ->assertFormFieldDoesNotExist('renewal_duration_months')
        ->assertFormFieldExists('condition.amount')
        ->assertFormFieldExists('condition.cycle')
        ->assertFormFieldExists('condition.attribution_mode')
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
        ->fillForm(['next_expiry_date' => '2026-12-31'])
        ->assertFormFieldExists('renewal_duration_months')
        ->fillForm(['renewal_duration_months' => 12, 'automatic_renewal' => false])
        ->assertFormFieldDoesNotExist('renewal_duration_months')
        ->assertFormSet(['renewal_duration_months' => null])
        ->fillForm([
            'title' => 'Contratto energia',
            'supplier_id' => $supplier->id,
            'contractual_start_date' => '2026-01-01',
            'next_expiry_date' => null,
            'renewal_effective_from' => '2026-01-01',
            'automatic_renewal' => true,
            'renewal_duration_months' => null,
            'notice_days' => null,
            'condition' => [
                'amount' => '120,50',
                'cycle' => 'monthly',
                'attribution_mode' => 'cycle_start',
                'valid_from' => '2026-01-01',
                'valid_to' => null,
            ],
            'classifications' => [[
                'exercise_id' => $exercise->id,
                'cost_center_id' => null,
            ]],
        ])
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
        ->assertTableActionDoesNotExist('delete', record: $contractA)
        ->assertTableActionHidden('edit', record: $contractA);

    Livewire::test(ViewContract::class, ['record' => $contractA->getRouteKey()])
        ->assertSuccessful()
        ->assertSee('contract:'.$contractA->id)
        ->assertSee('Scadenza non definita')
        ->assertSee('Situazioni annuali')
        ->assertActionDoesNotExist('delete')
        ->assertDontSee('Sostituisce');

    $this->get(ContractResource::getUrl('view', ['record' => $contractB], tenant: $companyA))->assertNotFound();
    $this->get(ContractResource::getUrl('edit', ['record' => $contractA], tenant: $companyA))->assertForbidden();
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
    expect(ContractResource::getRelations())->toContain(
        ContractLifecycleRelationManager::class,
        ContractRenewalsRelationManager::class,
    );
});
