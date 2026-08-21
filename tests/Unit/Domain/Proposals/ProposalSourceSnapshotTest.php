<?php

use App\Domain\Proposals\ProposalSourceSnapshot;
use App\Models\Expense;
use App\Models\ExpenseLine;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

it('separates actual context from editable expense planning facts and fingerprints stably', function (): void {
    $expense = Expense::factory()->create();
    ExpenseLine::factory()->for($expense)->create(['type' => 'estimate', 'amount' => '12.30']);
    ExpenseLine::factory()->for($expense)->create(['type' => 'actual', 'amount' => '4.10']);
    $expense->load(['lines', 'supplier', 'directCostCenter']);

    $snapshot = ProposalSourceSnapshot::expense($expense);

    expect($snapshot['plan_baseline']['estimate_lines'])->toHaveCount(1)
        ->and($snapshot['actual_context']['actual_lines'])->toHaveCount(1)
        ->and(ProposalSourceSnapshot::fingerprint($snapshot))->toHaveLength(64)
        ->and(ProposalSourceSnapshot::fingerprint($snapshot))->toBe(ProposalSourceSnapshot::fingerprint($snapshot));
});
