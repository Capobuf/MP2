<?php

use App\Filament\Resources\Proposals\Pages\ViewProposal;
use App\Models\Proposal;
use App\Models\ProposalItem;
use App\Models\User;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\Support\TestPermissions;

uses(RefreshDatabase::class);

it('exposes only plan-safe expense controls on a draft', function (): void {
    $proposal = Proposal::factory()->create();
    $item = ProposalItem::factory()->for($proposal)->create(['result' => ['description' => 'Spesa prevista', 'exercise_id' => $proposal->exercise_id, 'estimate_lines' => []]]);
    $user = User::factory()->create();
    foreach ([TestPermissions::VIEW, TestPermissions::MANAGE_PROPOSALS] as $capability) {
        grantTestPermissions(['company_id' => $proposal->company_id, 'user' => $user, 'permissions' => $capability]);
    }
    $this->actingAs($user);
    Filament::setTenant(($proposal->company)->tenantCompany);
    Livewire::test(ViewProposal::class, ['record' => $proposal->id])
        ->assertActionExists(TestAction::make('createPlannedExpense')->table())->assertActionExists(TestAction::make('planExpenseEstimates')->table($item))->assertActionExists(TestAction::make('copyExpense')->table())
        ->assertActionExists(TestAction::make('planExpenseOwner')->table($item))->assertActionExists(TestAction::make('planExpenseSupplier')->table($item))->assertActionExists(TestAction::make('planExpenseCostCenter')->table($item))
        ->assertActionExists(TestAction::make('reversePlannedExpense')->table($item))->assertActionExists(TestAction::make('restorePlannedExpense')->table($item))
        ->assertActionDoesNotExist('editActual')->assertActionDoesNotExist('reprogramming')->assertActionDoesNotExist('carryover')->assertActionDoesNotExist('delete');
});
