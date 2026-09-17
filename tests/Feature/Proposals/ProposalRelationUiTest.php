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
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\Support\TestPermissions;

uses(RefreshDatabase::class);

it('offers only the canonical project contract link', function (): void {
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
    Livewire::test(ViewProposal::class, ['record' => $proposal->id])->assertActionExists(TestAction::make('linkProjectContract')->table($item))->assertActionDoesNotExist('replaceRelation')->assertDontSee('Sostituisce');
});
