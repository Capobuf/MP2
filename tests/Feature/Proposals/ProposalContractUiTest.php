<?php

use App\Filament\Resources\Proposals\Pages\ViewProposal;
use App\Models\Proposal;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\Support\TestPermissions;

uses(RefreshDatabase::class);

it('exposes typed contract planning and no manual estimate or prorata control', function (): void {
    $proposal = Proposal::factory()->create();
    $user = User::factory()->create();
    foreach ([TestPermissions::VIEW, TestPermissions::MANAGE_PROPOSALS] as $capability) {
        grantTestPermissions(['company_id' => $proposal->company_id, 'user' => $user, 'permissions' => $capability]);
    }
    $this->actingAs($user);
    Filament::setTenant(($proposal->company)->tenantCompany);
    Livewire::test(ViewProposal::class, ['record' => $proposal->id])->assertActionExists('includeTerminatedContract')->assertActionExists('createPlannedContract')->assertActionExists('addContractCondition')->assertActionExists('changeContractEconomics')->assertActionExists('planContractLifecycle')->assertActionExists('planContractRenewal')->assertActionExists('planContractCostCenter')->assertActionDoesNotExist('manualContractEstimate')->assertActionDoesNotExist('prorata')->assertActionDoesNotExist('changeUsedSupplier');
});
