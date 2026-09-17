<?php

use App\Domain\Proposals\ProposalSourceSnapshot;
use App\Filament\Resources\Proposals\Pages\ViewProposal;
use App\Models\Exercise;
use App\Models\Project;
use App\Models\Proposal;
use App\Models\ProposalItem;
use App\Models\User;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Filament\Forms\Components\Select;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\Support\TestPermissions;

uses(RefreshDatabase::class);

it('exposes Project planning, Rinvio and independent new allocation controls', function (): void {
    $proposal = Proposal::factory()->create();
    $project = Project::factory()->create(['company_id' => $proposal->company_id, 'initial_state' => 'planned', 'initial_effective_date' => $proposal->exercise->year.'-01-01']);
    $snapshot = ProposalSourceSnapshot::project($project, $proposal->exercise_id);
    $item = ProposalItem::factory()->for($proposal)->create(['source_type' => 'project', 'project_id' => $project->id, 'baseline_revision' => $project->revision, 'baseline_fingerprint' => ProposalSourceSnapshot::fingerprint($snapshot), 'baseline' => $snapshot, 'result' => $snapshot['plan_baseline']]);
    Exercise::factory()->create(['company_id' => $proposal->company_id, 'year' => $proposal->exercise->year - 1]);
    $user = User::factory()->create();
    foreach ([TestPermissions::VIEW, TestPermissions::MANAGE_PROPOSALS] as $capability) {
        grantTestPermissions(['company_id' => $proposal->company_id, 'user' => $user, 'permissions' => $capability]);
    }
    $this->actingAs($user);
    Filament::setTenant(($proposal->company)->tenantCompany);
    Livewire::test(ViewProposal::class, ['record' => $proposal->id])
        ->assertActionExists(TestAction::make('includeClosedProject')->table())->assertActionExists(TestAction::make('createPlannedProject')->table())->assertActionExists(TestAction::make('planProjectTransition')->table($item))
        ->assertActionExists(TestAction::make('planProjectDeferral')->table($item))->assertActionExists(TestAction::make('createProjectAllocation')->table($item))->assertActionExists(TestAction::make('createPlannedExpense')->table())
        ->assertActionExists(TestAction::make('planProjectChildExpenses')->table($item))->assertActionExists(TestAction::make('planProjectExpenseEstimates')->table($item))->assertActionExists(TestAction::make('planProjectCostCenter')->table($item))
        ->assertActionDoesNotExist('carryover')->assertActionDoesNotExist('reprogramming')
        ->mountAction(TestAction::make('planProjectDeferral')->table($item))
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
