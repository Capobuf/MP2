<?php

use App\Filament\Resources\Contracts\Pages\ViewContract;
use App\Filament\Resources\Contracts\RelationManagers\ContractRenewalsRelationManager;
use App\Models\Company;
use App\Models\Contract;
use App\Models\Exercise;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\Support\TestPermissions;

uses(RefreshDatabase::class);

it('keeps renewal history without a duplicate edit operation', function () {
    $manager = User::factory()->create();
    $company = Company::factory()->create();
    foreach ([TestPermissions::VIEW, TestPermissions::MANAGE_OPERATIONS] as $capability) {
        grantTestPermissions([
            'company_id' => $company->id,
            'user' => $manager,
            'permissions' => $capability,
        ]);
    }
    $exercise = Exercise::factory()->for($company)->create();
    $contract = Contract::factory()->for($company)->create();
    $this->actingAs($manager);
    Filament::setTenant(($company)->tenantCompany);

    Livewire::test(ContractRenewalsRelationManager::class, [
        'ownerRecord' => $contract,
        'pageClass' => ViewContract::class,
    ])->assertTableActionDoesNotExist('updateRenewal');
});
