<?php

use App\Filament\Resources\Contracts\Pages\ViewContract;
use App\Filament\Resources\Contracts\RelationManagers\ContractConditionsRelationManager;
use App\Models\Company;
use App\Models\Contract;
use App\Models\ContractCondition;
use App\Models\ContractLifecycleFact;
use App\Models\Exercise;
use App\Models\Supplier;
use App\Models\User;
use Carbon\CarbonImmutable;
use Filament\Facades\Filament;
use Filament\Forms\Components\Checkbox;
use Filament\Forms\Components\Placeholder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Tests\Support\TestPermissions;

uses(RefreshDatabase::class);

beforeEach(fn () => CarbonImmutable::setTestNow('2026-08-20 10:00:00 Europe/Rome'));
afterEach(fn () => CarbonImmutable::setTestNow());

it('creates and annuls conditions explicitly without raw edit or delete', function () {
    $manager = User::factory()->create();
    $company = Company::factory()->create(['timezone' => 'Europe/Rome']);
    foreach ([TestPermissions::VIEW, TestPermissions::MANAGE_OPERATIONS] as $capability) {
        grantTestPermissions(['company_id' => $company->id, 'user' => $manager, 'permissions' => $capability]);
    }
    Exercise::factory()->for($company)->create(['year' => 2026]);
    $supplier = Supplier::factory()->for($company)->create();
    $contract = Contract::factory()->for($company)->for($supplier)->create(['next_expiry_date' => null, 'renewal_anchor_date' => null]);
    ContractLifecycleFact::factory()->forContract($contract)->create();
    $this->actingAs($manager);
    Filament::setTenant(($company)->tenantCompany);

    $component = Livewire::test(ContractConditionsRelationManager::class, ['ownerRecord' => $contract, 'pageClass' => ViewContract::class])
        ->callTableAction('createCondition', data: [
            'amount' => '10.00', 'cycle' => 'monthly', 'attribution_mode' => 'cycle_start', 'valid_from' => '2026-01-01', 'valid_to' => null,
        ])->assertHasNoTableActionErrors();

    $condition = $contract->conditions()->sole();
    $component->assertCanSeeTableRecords([$condition])
        ->assertTableActionDoesNotExist('edit', record: $condition)
        ->assertTableActionDoesNotExist('delete', record: $condition)
        ->callTableAction('annul', record: $condition, data: ['reason' => 'Errore materiale'])
        ->assertHasNoTableActionErrors();

    expect($condition->refresh()->isAnnulled())->toBeTrue();
});

it('hides condition mutations from viewers', function () {
    $viewer = User::factory()->create();
    $company = Company::factory()->create();
    grantTestPermissions(['company_id' => $company->id, 'user' => $viewer, 'permissions' => TestPermissions::VIEW]);
    $contract = Contract::factory()->for($company)->create();
    $condition = $contract->conditions()->create([
        'company_id' => $company->id, 'cycle' => 'monthly', 'attribution_mode' => 'cycle_start', 'amount' => '1.00',
        'valid_from' => '2026-01-01', 'created_by_id' => $viewer->id,
    ]);
    $this->actingAs($viewer);
    Filament::setTenant(($company)->tenantCompany);

    Livewire::test(ContractConditionsRelationManager::class, ['ownerRecord' => $contract, 'pageClass' => ViewContract::class])
        ->assertTableActionHidden('createCondition')
        ->assertTableActionHidden('annul', record: $condition)
        ->assertTableActionDoesNotExist('delete', record: $condition);
});

it('exposes separate confirmed previews for agreement changes and material corrections', function () {
    $manager = User::factory()->create();
    $company = Company::factory()->create(['timezone' => 'Europe/Rome']);
    foreach ([TestPermissions::VIEW, TestPermissions::MANAGE_OPERATIONS] as $capability) {
        grantTestPermissions(['company_id' => $company->id, 'user' => $manager, 'permissions' => $capability]);
    }
    Exercise::factory()->for($company)->create(['year' => 2026]);
    $supplier = Supplier::factory()->for($company)->create();
    $contract = Contract::factory()->for($company)->for($supplier)->create(['next_expiry_date' => null, 'renewal_anchor_date' => null]);
    ContractLifecycleFact::factory()->forContract($contract)->create();
    $condition = ContractCondition::factory()->forContract($contract)->create(['valid_from' => '2026-01-01']);
    $this->actingAs($manager);
    Filament::setTenant(($company)->tenantCompany);

    Livewire::test(ContractConditionsRelationManager::class, ['ownerRecord' => $contract, 'pageClass' => ViewContract::class])
        ->assertTableActionExists('changeAgreement', record: $condition)
        ->mountTableAction('changeAgreement', record: $condition)
        ->assertSchemaComponentExists('impact_preview', checkComponentUsing: function (Placeholder $component): bool {
            $html = $component->getContent()->render();

            expect($html)
                ->toContain('Decorrenza')
                ->toContain('Data richiesta')
                ->toContain('Data minima richiedibile')
                ->toContain('Data effettiva applicabile')
                ->toContain('Prorata applicato: no')
                ->toContain('Impatto sugli Esercizi Aperti');

            return true;
        })
        ->assertSchemaComponentExists('effective_date_confirmed', checkComponentUsing: function (Checkbox $component): bool {
            expect($component->isRequired())->toBeFalse()
                ->and($component->isMarkedAsRequired())->toBeTrue();

            return true;
        });

    Livewire::test(ContractConditionsRelationManager::class, ['ownerRecord' => $contract, 'pageClass' => ViewContract::class])
        ->assertTableActionExists('correctMaterialError', record: $condition)
        ->mountTableAction('correctMaterialError', record: $condition)
        ->assertSchemaComponentExists('declared_input_error', checkComponentUsing: function (Checkbox $component): bool {
            expect($component->isRequired())->toBeFalse()
                ->and($component->isMarkedAsRequired())->toBeTrue();

            return true;
        })
        ->assertSchemaComponentExists('impact_preview');
});

it('shows validation errors for every unconfirmed economic action without mutating the condition', function () {
    $manager = User::factory()->create();
    $company = Company::factory()->create(['timezone' => 'Europe/Rome']);
    foreach ([TestPermissions::VIEW, TestPermissions::MANAGE_OPERATIONS] as $capability) {
        grantTestPermissions(['company_id' => $company->id, 'user' => $manager, 'permissions' => $capability]);
    }
    Exercise::factory()->for($company)->create(['year' => 2026]);
    $supplier = Supplier::factory()->for($company)->create();
    $contract = Contract::factory()->for($company)->for($supplier)->create(['next_expiry_date' => null, 'renewal_anchor_date' => null]);
    ContractLifecycleFact::factory()->forContract($contract)->create();
    $condition = ContractCondition::factory()->forContract($contract)->create(['valid_from' => '2026-01-01']);
    $this->actingAs($manager);
    Filament::setTenant(($company)->tenantCompany);

    Livewire::test(ContractConditionsRelationManager::class, ['ownerRecord' => $contract, 'pageClass' => ViewContract::class])
        ->callTableAction('changeAgreement', record: $condition, data: [
            'requested_date' => '2026-08-20',
            'amount' => '150.00',
            'cycle' => 'monthly',
            'attribution_mode' => 'cycle_start',
            'reason' => 'Nuovo accordo',
            'effective_date_confirmed' => false,
        ])
        ->assertHasTableActionErrors(['effective_date_confirmed' => 'accepted']);

    Livewire::test(ContractConditionsRelationManager::class, ['ownerRecord' => $contract, 'pageClass' => ViewContract::class])
        ->callTableAction('correctMaterialError', record: $condition, data: [
            'amount' => '90.00',
            'cycle' => 'monthly',
            'attribution_mode' => 'cycle_start',
            'reason' => 'Errore di inserimento',
            'declared_input_error' => false,
            'declared_no_new_agreement' => false,
            'impact_confirmed' => false,
        ])
        ->assertHasTableActionErrors([
            'declared_input_error' => 'accepted',
            'declared_no_new_agreement' => 'accepted',
            'impact_confirmed' => 'accepted',
        ]);

    expect($condition->refresh()->amount)->toBe('100.00')
        ->and($condition->valid_to)->toBeNull()
        ->and($contract->refresh()->revision)->toBe(0)
        ->and(ContractCondition::query()->where('contract_id', $contract->id)->count())->toBe(1);
});

it('generates the hidden operation identifier when opening either economic action', function () {
    $manager = User::factory()->create();
    $company = Company::factory()->create(['timezone' => 'Europe/Rome']);
    foreach ([TestPermissions::VIEW, TestPermissions::MANAGE_OPERATIONS] as $capability) {
        grantTestPermissions(['company_id' => $company->id, 'user' => $manager, 'permissions' => $capability]);
    }
    Exercise::factory()->for($company)->create(['year' => 2026]);
    $supplier = Supplier::factory()->for($company)->create();
    $contract = Contract::factory()->for($company)->for($supplier)->create(['next_expiry_date' => null, 'renewal_anchor_date' => null]);
    ContractLifecycleFact::factory()->forContract($contract)->create();
    $condition = ContractCondition::factory()->forContract($contract)->create(['valid_from' => '2026-01-01']);
    $this->actingAs($manager);
    Filament::setTenant(($company)->tenantCompany);

    foreach (['changeAgreement', 'correctMaterialError'] as $action) {
        $component = Livewire::test(ContractConditionsRelationManager::class, ['ownerRecord' => $contract, 'pageClass' => ViewContract::class])
            ->mountTableAction($action, record: $condition);

        expect($component->get('mountedActions.0.data.operation_id'))
            ->toBeString()
            ->and(Str::isUuid($component->get('mountedActions.0.data.operation_id')))->toBeTrue();
    }
});
