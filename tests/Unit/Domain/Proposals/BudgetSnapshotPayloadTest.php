<?php

use App\Domain\Proposals\BudgetSnapshotPayload;
use App\Models\Expense;
use App\Models\Proposal;
use App\Models\ProposalItem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

it('builds autonomous plan-only rows and a non-duplicated exercise total', function (): void {
    $proposal = Proposal::factory()->create();
    $expense = Expense::factory()->forExercise($proposal->exercise)->create();
    $expense->lines()->create(['type' => 'estimate', 'amount' => '4.00']);
    $item = ProposalItem::factory()->for($proposal)->create(['company_id' => $proposal->company_id, 'source_type' => 'expense', 'expense_id' => $expense->id, 'baseline_revision' => 0, 'baseline_fingerprint' => str_repeat('a', 64), 'result' => ['description' => $expense->description, 'estimate_lines' => [['amount' => '4.00', 'annulled' => false]]]]);
    $proposal->load(['exercise', 'items.actions', 'actions']);
    $payload = BudgetSnapshotPayload::build($proposal, [(string) $item->proposal_item_id => $expense], []);
    expect($payload['total'])->toBe('4.00')->and($payload['rows'])->toHaveCount(1)->and($payload['rows'][0]['detail'])->not->toHaveKey('actual_context');
});
