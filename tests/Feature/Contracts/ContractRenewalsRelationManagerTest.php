<?php

use App\Domain\Company\Capability;
use App\Filament\Resources\Contracts\Pages\ViewContract;
use App\Filament\Resources\Contracts\RelationManagers\ContractRenewalsRelationManager;
use App\Models\BudgetSnapshot;
use App\Models\Company;
use App\Models\CompanyCapability;
use App\Models\Contract;
use App\Models\Exercise;
use App\Models\Proposal;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

it('shows only the renewal fields required by the current choices and Budget state', function () {
    $manager = User::factory()->create();
    $company = Company::factory()->create();
    foreach ([Capability::View, Capability::ManageOperations] as $capability) {
        CompanyCapability::query()->create([
            'company_id' => $company->id,
            'user_id' => $manager->id,
            'capability' => $capability,
        ]);
    }
    $exercise = Exercise::factory()->for($company)->create();
    $contract = Contract::factory()->for($company)->create();
    $this->actingAs($manager);
    Filament::setTenant(($company)->tenantCompany);

    Livewire::test(ContractRenewalsRelationManager::class, [
        'ownerRecord' => $contract,
        'pageClass' => ViewContract::class,
    ])->mountTableAction('updateRenewal')
        ->assertSchemaComponentExists('impact_preview')
        ->assertSchemaComponentExists('impact_confirmed')
        ->fillForm(['automatic_renewal' => false, 'expiry_anchor_date' => null])
        ->assertSchemaComponentHidden('renewal_duration_months')
        ->assertSchemaComponentHidden('reason');

    $proposal = Proposal::factory()->for($company)->for($exercise)->create(['created_by_id' => $manager->id]);
    BudgetSnapshot::factory()->for($proposal)->create([
        'company_id' => $company->id,
        'exercise_id' => $exercise->id,
        'approved_by_id' => $manager->id,
    ]);

    Livewire::test(ContractRenewalsRelationManager::class, [
        'ownerRecord' => $contract,
        'pageClass' => ViewContract::class,
    ])->mountTableAction('updateRenewal')
        ->assertSchemaComponentVisible('reason')
        ->fillForm(['automatic_renewal' => true, 'expiry_anchor_date' => '2027-12-31'])
        ->assertSchemaComponentVisible('renewal_duration_months');
});
