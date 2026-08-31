<?php

use App\Filament\Resources\Proposals\Pages\ViewProposal;
use App\Models\Proposal;
use App\Models\User;
use Filament\Facades\Filament;
use Filament\Forms\Components\Select;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\Support\TestPermissions;

uses(RefreshDatabase::class);

it('exposes Project planning, Rinvio and independent new allocation controls', function (): void {
    $proposal = Proposal::factory()->create();
    $user = User::factory()->create();
    foreach ([TestPermissions::VIEW, TestPermissions::MANAGE_PROPOSALS] as $capability) {
        grantTestPermissions(['company_id' => $proposal->company_id, 'user' => $user, 'permissions' => $capability]);
    }
    $this->actingAs($user);
    Filament::setTenant(($proposal->company)->tenantCompany);
    Livewire::test(ViewProposal::class, ['record' => $proposal->id])
        ->assertActionExists('includeClosedProject')->assertActionExists('createPlannedProject')->assertActionExists('planProjectTransition')
        ->assertActionExists('planProjectDeferral')->assertActionExists('createProjectAllocation')->assertActionExists('createPlannedExpense')
        ->assertActionExists('planProjectChildExpenses')->assertActionExists('planProjectExpenseEstimates')->assertActionExists('planProjectCostCenter')
        ->assertActionDoesNotExist('carryover')->assertActionDoesNotExist('reprogramming')
        ->mountAction('planProjectDeferral')
        ->assertSchemaComponentExists('mode', checkComponentUsing: fn (Select $component): bool => $component->getOptions() === [
            'none' => 'Nessuna',
            'carryover' => 'Riporto',
            'reprogramming' => 'Riprogrammazione',
        ])
        ->assertSchemaComponentExists('carryover_amount')
        ->assertSchemaComponentExists('source_estimate_reductions')
        ->assertSchemaComponentExists('deferral_formula')
        ->assertSchemaComponentExists('reason');
});
