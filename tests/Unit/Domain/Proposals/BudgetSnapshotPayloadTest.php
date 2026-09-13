<?php

use App\Domain\Proposals\BudgetSnapshotPayload;
use App\Models\CostCenter;
use App\Models\Expense;
use App\Models\Proposal;
use App\Models\ProposalItem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

it('builds autonomous plan-only rows and a non-duplicated exercise total', function (): void {
    $proposal = Proposal::factory()->create();
    $parent = CostCenter::factory()->for($proposal->company)->create(['name' => 'IT']);
    $costCenter = CostCenter::factory()->for($proposal->company)->create(['name' => 'Software', 'parent_id' => $parent->id]);
    $expense = Expense::factory()->forExercise($proposal->exercise)->create(['direct_cost_center_id' => $costCenter->id]);
    $expense->lines()->create(['type' => 'estimate', 'amount' => '4.00']);
    $item = ProposalItem::factory()->for($proposal)->create(['company_id' => $proposal->company_id, 'source_type' => 'expense', 'expense_id' => $expense->id, 'baseline_revision' => 0, 'baseline_fingerprint' => str_repeat('a', 64), 'result' => ['description' => $expense->description, 'estimate_lines' => [['amount' => '4.00', 'annulled' => false]]]]);
    $proposal->load(['exercise', 'items.actions', 'actions']);
    $payload = BudgetSnapshotPayload::build($proposal, [(string) $item->proposal_item_id => $expense], []);
    expect($payload['total'])->toBe('4.00')
        ->and($payload['rows'])->toHaveCount(1)
        ->and($payload['rows'][0]['detail'])->not->toHaveKey('actual_context')
        ->and($payload['rows'][0]['detail_version'])->toBe(2)
        ->and($payload['rows'][0]['cost_center_label'])->toBe('IT / Software')
        ->and($payload['rows'][0]['detail']['cost_center_lineage'])->toBe([
            ['cost_center_id' => $parent->id, 'cost_center_label' => 'IT'],
            ['cost_center_id' => $costCenter->id, 'cost_center_label' => 'Software'],
        ]);

    $newParent = CostCenter::factory()->for($proposal->company)->create(['name' => 'Digital']);
    $parent->update(['name' => 'Technology']);
    $costCenter->update(['name' => 'SaaS', 'parent_id' => $newParent->id]);

    expect($payload['rows'][0]['cost_center_label'])->toBe('IT / Software')
        ->and($payload['rows'][0]['detail']['cost_center_lineage'][0]['cost_center_label'])->toBe('IT');
});
