<?php

use App\Domain\Company\Capability;
use App\Filament\Resources\Proposals\Pages\ViewProposal;
use App\Models\CompanyCapability;
use App\Models\Proposal;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

it('exposes only plan-safe expense controls on a draft', function (): void {
    $proposal = Proposal::factory()->create();
    $user = User::factory()->create();
    foreach ([Capability::View, Capability::ManageProposals] as $capability) {
        CompanyCapability::query()->create(['company_id' => $proposal->company_id, 'user_id' => $user->id, 'capability' => $capability]);
    }
    $this->actingAs($user);
    Filament::setTenant($proposal->company);
    Livewire::test(ViewProposal::class, ['record' => $proposal->id])
        ->assertActionExists('createPlannedExpense')->assertActionExists('planExpenseEstimates')->assertActionExists('copyExpense')
        ->assertActionExists('planExpenseOwner')->assertActionExists('planExpenseSupplier')->assertActionExists('planExpenseCostCenter')
        ->assertActionExists('reversePlannedExpense')->assertActionExists('restorePlannedExpense')
        ->assertActionDoesNotExist('editActual')->assertActionDoesNotExist('reprogramming')->assertActionDoesNotExist('carryover')->assertActionDoesNotExist('delete');
});
