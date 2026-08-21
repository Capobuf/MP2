<?php

use App\Domain\Proposals\ProposalRelationPlan;
use App\Models\Proposal;
use App\Models\ProposalItem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

it('validates a same-proposal project contract link without economic result', function (): void {
    $proposal = Proposal::factory()->create();
    $project = ProposalItem::factory()->for($proposal)->create(['company_id' => $proposal->company_id, 'source_type' => 'project']);
    $contract = ProposalItem::factory()->for($proposal)->create(['company_id' => $proposal->company_id, 'source_type' => 'contract']);
    $validated = ProposalRelationPlan::validate($proposal, ['project_item_id' => $project->proposal_item_id, 'contract_item_id' => $contract->proposal_item_id]);
    expect($validated)->toBe(['contract_item_id' => (string) $contract->proposal_item_id, 'project_item_id' => (string) $project->proposal_item_id]);
});
