<?php

use App\Domain\Company\Capability;
use App\Filament\Resources\Contracts\Pages\ViewContract;
use App\Filament\Resources\Contracts\RelationManagers\ContractConditionsRelationManager;
use App\Models\Company;
use App\Models\CompanyCapability;
use App\Models\Contract;
use App\Models\ContractLifecycleFact;
use App\Models\Exercise;
use App\Models\Supplier;
use App\Models\User;
use Carbon\CarbonImmutable;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(fn () => CarbonImmutable::setTestNow('2026-08-20 10:00:00 Europe/Rome'));
afterEach(fn () => CarbonImmutable::setTestNow());

it('creates and annuls conditions explicitly without raw edit or delete', function () {
    $manager = User::factory()->create();
    $company = Company::factory()->create(['timezone' => 'Europe/Rome']);
    foreach ([Capability::View, Capability::ManageOperations] as $capability) {
        CompanyCapability::query()->create(['company_id' => $company->id, 'user_id' => $manager->id, 'capability' => $capability]);
    }
    Exercise::factory()->for($company)->create(['year' => 2026]);
    $supplier = Supplier::factory()->for($company)->create();
    $contract = Contract::factory()->for($company)->for($supplier)->create(['next_expiry_date' => null, 'renewal_anchor_date' => null]);
    ContractLifecycleFact::factory()->forContract($contract)->create();
    $this->actingAs($manager);
    Filament::setTenant($company);

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
    CompanyCapability::query()->create(['company_id' => $company->id, 'user_id' => $viewer->id, 'capability' => Capability::View]);
    $contract = Contract::factory()->for($company)->create();
    $condition = $contract->conditions()->create([
        'company_id' => $company->id, 'cycle' => 'monthly', 'attribution_mode' => 'cycle_start', 'amount' => '1.00',
        'valid_from' => '2026-01-01', 'created_by_id' => $viewer->id,
    ]);
    $this->actingAs($viewer);
    Filament::setTenant($company);

    Livewire::test(ContractConditionsRelationManager::class, ['ownerRecord' => $contract, 'pageClass' => ViewContract::class])
        ->assertTableActionHidden('createCondition')
        ->assertTableActionHidden('annul', record: $condition)
        ->assertTableActionDoesNotExist('delete', record: $condition);
});
