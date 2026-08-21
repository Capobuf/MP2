<?php

use App\Domain\Proposals\ProposalItemReference;
use App\Models\Proposal;
use App\Models\ProposalItem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

it('resolves only compatible items in the same proposal', function (): void {
    $proposal = Proposal::factory()->create();
    $project = ProposalItem::factory()->for($proposal)->create(['company_id' => $proposal->company_id, 'source_type' => 'project']);
    expect(ProposalItemReference::item($proposal, $project->proposal_item_id, 'project')->is($project))->toBeTrue()
        ->and(fn () => ProposalItemReference::item($proposal, $project->proposal_item_id, 'contract'))->toThrow(ValidationException::class);
});
