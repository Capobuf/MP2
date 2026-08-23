<?php

use App\Domain\Proposals\ProposalActionType;
use App\Domain\Proposals\ProposalReadiness;
use App\Domain\Proposals\ProposalReadinessReason;
use App\Domain\Proposals\ProposalSourceSnapshot;
use App\Models\Exercise;
use App\Models\Expense;
use App\Models\ExpenseLine;
use App\Models\Proposal;
use App\Models\ProposalItem;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('reports an exact actual-mutation reason and never a generic inconsistency', function (): void {
    $proposal = Proposal::factory()->create();
    $expense = Expense::factory()->forExercise($proposal->exercise)->create();
    ExpenseLine::factory()->for($expense)->create(['type' => 'actual', 'amount' => '2.00']);
    $snapshot = ProposalSourceSnapshot::expense($expense);
    $result = $snapshot['plan_baseline'];
    $result['exercise_id'] = Exercise::factory()->for($proposal->company)->create()->id;
    $item = ProposalItem::factory()->for($proposal)->create([
        'company_id' => $proposal->company_id,
        'source_type' => 'expense',
        'expense_id' => $expense->id,
        'baseline_revision' => $expense->revision,
        'baseline_fingerprint' => ProposalSourceSnapshot::fingerprint($snapshot),
        'baseline' => $snapshot,
        'result' => $result,
    ]);

    $assessment = app(ProposalReadiness::class)->assessItem($item->load(['proposal', 'expense', 'actions']));

    expect(collect($assessment['reasons'])->pluck('code')->all())->toBe([ProposalReadinessReason::ActualMutation->value])
        ->not->toContain('invalid_action');
});

it('defines but does not make S8-only predicates reachable through an S7 action', function (): void {
    $s8 = ['carryover_above_limit', 'reprogramming_above_available', 'reprogramming_unbalanced', 'deferral_modes_conflict'];

    expect(collect(ProposalReadinessReason::inconsistencies())->map->value->all())->toContain(...$s8)
        ->and(collect(ProposalActionType::cases())->map->value->all())
        ->not->toContain('carryover', 'reprogramming');
});
