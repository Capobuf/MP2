<?php

use App\Domain\Proposals\ProposalReadiness;
use App\Domain\Proposals\ProposalReadinessState;
use App\Domain\Proposals\ProposalSourceSnapshot;
use App\Models\Expense;
use App\Models\Proposal;
use App\Models\ProposalItem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

it('marks a whole existing source for realignment when its revision changes', function (): void {
    $proposal = Proposal::factory()->create();
    $expense = Expense::factory()->forExercise($proposal->exercise)->create();
    $snapshot = ProposalSourceSnapshot::expense($expense);
    $item = ProposalItem::factory()->for($proposal)->create(['company_id' => $proposal->company_id, 'source_type' => 'expense', 'expense_id' => $expense->id, 'baseline_revision' => 0, 'baseline_fingerprint' => ProposalSourceSnapshot::fingerprint($snapshot), 'baseline' => $snapshot, 'result' => $snapshot['plan_baseline']]);
    $expense->increment('revision');
    $item->load(['proposal', 'expense', 'actions']);
    expect(app(ProposalReadiness::class)->assessItem($item)['state'])->toBe(ProposalReadinessState::ToRealign);
});
