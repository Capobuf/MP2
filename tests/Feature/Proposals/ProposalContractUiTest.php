<?php

use App\Domain\Proposals\ProposalSourceSnapshot;
use App\Filament\Resources\Proposals\Pages\ViewProposal;
use App\Models\Contract;
use App\Models\Proposal;
use App\Models\ProposalItem;
use App\Models\User;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\Support\TestPermissions;

uses(RefreshDatabase::class);

it('exposes typed contract planning and no manual estimate or prorata control', function (): void {
    $proposal = Proposal::factory()->create();
    $contract = Contract::factory()->create(['company_id' => $proposal->company_id]);
    $snapshot = ProposalSourceSnapshot::contract($contract, $proposal->exercise_id);
    $item = ProposalItem::factory()->for($proposal)->create(['source_type' => 'contract', 'contract_id' => $contract->id, 'baseline_revision' => $contract->revision, 'baseline_fingerprint' => ProposalSourceSnapshot::fingerprint($snapshot), 'baseline' => $snapshot, 'result' => $snapshot['plan_baseline']]);
    $user = User::factory()->create();
    foreach ([TestPermissions::VIEW, TestPermissions::MANAGE_PROPOSALS] as $capability) {
        grantTestPermissions(['company_id' => $proposal->company_id, 'user' => $user, 'permissions' => $capability]);
    }
    $this->actingAs($user);
    Filament::setTenant(($proposal->company)->tenantCompany);
    Livewire::test(ViewProposal::class, ['record' => $proposal->id])->assertActionExists(TestAction::make('includeTerminatedContract')->table())->assertActionExists(TestAction::make('createPlannedContract')->table())->assertActionExists(TestAction::make('addContractCondition')->table($item))->assertActionExists(TestAction::make('changeContractEconomics')->table($item))->assertActionExists(TestAction::make('planContractLifecycle')->table($item))->assertActionExists(TestAction::make('planContractRenewal')->table($item))->assertActionExists(TestAction::make('planContractCostCenter')->table($item))->assertActionDoesNotExist('manualContractEstimate')->assertActionDoesNotExist('prorata')->assertActionDoesNotExist('changeUsedSupplier');
});
