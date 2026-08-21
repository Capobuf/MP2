<?php

use App\Domain\Company\Capability;
use App\Filament\Resources\Proposals\Pages\ViewProposal;
use App\Models\CompanyCapability;
use App\Models\Proposal;
use App\Models\ProposalItem;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

it('shows all canonical readiness labels impacts and no S7 resolution controls', function (): void {
    $proposal = Proposal::factory()->create();
    $user = User::factory()->create();
    foreach ([Capability::View, Capability::ManageProposals] as $capability) {
        CompanyCapability::query()->create(['company_id' => $proposal->company_id, 'user_id' => $user->id, 'capability' => $capability]);
    }
    foreach (['aligned', 'to_review', 'to_realign', 'inconsistent'] as $state) {
        ProposalItem::factory()->for($proposal)->create(['company_id' => $proposal->company_id, 'source_type' => 'expense', 'readiness_state' => $state]);
    }
    $this->actingAs($user);
    Filament::setTenant($proposal->company);
    Livewire::test(ViewProposal::class, ['record' => $proposal->id])->assertActionExists('reviewReadiness')->assertSee('Allineato')->assertSee('Da prendere in visione')->assertSee('Da riallineare')->assertSee('Incoerente')->assertSee('Esercizi interessati')->assertActionDoesNotExist('reloadReality')->assertActionDoesNotExist('keepProposal');
});
