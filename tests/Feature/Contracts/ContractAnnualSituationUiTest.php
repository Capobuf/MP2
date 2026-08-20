<?php

use App\Domain\Company\Capability;
use App\Filament\Resources\Contracts\Pages\ViewContract;
use App\Filament\Resources\Contracts\RelationManagers\ContractAnnualSituationsRelationManager;
use App\Models\Company;
use App\Models\CompanyCapability;
use App\Models\Contract;
use App\Models\ContractCondition;
use App\Models\ContractExerciseClassification;
use App\Models\ContractLifecycleFact;
use App\Models\Exercise;
use App\Models\Supplier;
use App\Models\User;
use Carbon\CarbonImmutable;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

it('shows exact annual composition without generated estimate mutation controls', function () {
    CarbonImmutable::setTestNow('2026-08-20 10:00:00 Europe/Rome');
    $viewer = User::factory()->create();
    $company = Company::factory()->create(['timezone' => 'Europe/Rome']);
    CompanyCapability::query()->create(['company_id' => $company->id, 'user_id' => $viewer->id, 'capability' => Capability::View]);
    $exercise = Exercise::factory()->for($company)->create(['year' => 2026]);
    $supplier = Supplier::factory()->for($company)->create();
    $contract = Contract::factory()->for($company)->for($supplier)->create(['next_expiry_date' => null, 'renewal_anchor_date' => null]);
    ContractLifecycleFact::factory()->forContract($contract)->create();
    ContractCondition::factory()->forContract($contract)->create(['amount' => '12.34', 'valid_from' => '2026-01-01', 'valid_to' => '2026-02-01']);
    ContractExerciseClassification::factory()->forContractAndExercise($contract, $exercise)->create();
    $this->actingAs($viewer);
    Filament::setTenant($company);

    Livewire::test(ContractAnnualSituationsRelationManager::class, ['ownerRecord' => $contract, 'pageClass' => ViewContract::class])
        ->assertCanSeeTableRecords([$exercise])
        ->assertSee('24,68')
        ->assertSee('01/01/2026')
        ->assertTableActionDoesNotExist('edit', record: $exercise)
        ->assertTableActionDoesNotExist('delete', record: $exercise)
        ->assertTableActionDoesNotExist('create', record: $exercise);

    CarbonImmutable::setTestNow();
});
