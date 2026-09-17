<?php

use App\Domain\Expenses\Decimal;
use App\Domain\Proposals\BudgetSnapshotPayload;
use App\Models\Contract;
use App\Models\ContractCondition;
use App\Models\ContractExerciseClassification;
use App\Models\CostCenter;
use App\Models\Exercise;
use App\Models\Expense;
use App\Models\ExpenseLine;
use App\Models\Project;
use App\Models\ProjectContractLink;
use App\Models\ProjectDeferral;
use App\Models\ProjectExerciseClassification;
use App\Models\Proposal;
use App\Models\ProposalItem;
use App\Models\Supplier;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

function budgetLoadingFixture(string $type, int $count): array
{
    $proposal = Proposal::factory()->create();
    $proposal->exercise->update(['year' => 2026]);
    $previous = Exercise::factory()->for($proposal->company)->create(['year' => 2025]);
    $parent = CostCenter::factory()->for($proposal->company)->create(['name' => 'IT']);
    $center = CostCenter::factory()->for($proposal->company)->create(['name' => 'Software', 'parent_id' => $parent->id]);
    $supplier = Supplier::factory()->for($proposal->company)->create();
    $identities = [];
    for ($index = 0; $index < $count; $index++) {
        $expense = Expense::factory()->forExercise($proposal->exercise)->create([
            'supplier_id' => $supplier->id, 'direct_cost_center_id' => $type === 'expense' ? $center->id : null,
        ]);
        $live = $expense;
        if ($type !== 'expense') {
            $project = Project::factory()->for($proposal->company)->create();
            $contract = Contract::factory()->for($proposal->company)->create(['supplier_id' => $supplier->id]);
            $live = $type === 'project' ? $project : $contract;
            $expense->update([$type.'_id' => $live->id]);
            ProjectExerciseClassification::factory()->forProjectAndExercise($project, $proposal->exercise)->create(['cost_center_id' => $center->id]);
            ContractExerciseClassification::factory()->forContractAndExercise($contract, $proposal->exercise)->create(['cost_center_id' => $center->id]);
            ProjectContractLink::factory()->forProjectAndContract($project, $contract)->create(['note' => 'Relazione informativa']);
            $oldExpense = Expense::factory()->forExercise($previous)->create([$type.'_id' => $live->id]);
            ExpenseLine::factory()->for($oldExpense)->create(['amount' => '999.00']);
            if ($type === 'contract') {
                ContractCondition::factory()->forContract($contract)->create(['amount' => '10.00']);
            } else {
                ProjectDeferral::factory()->carryover('5.00')->create([
                    'company_id' => $proposal->company_id, 'project_id' => $project->id,
                    'source_exercise_id' => $previous->id, 'destination_exercise_id' => $proposal->exercise_id,
                ]);
            }
        }
        ExpenseLine::factory()->for($expense)->create(['amount' => '120.00', 'note' => 'Stima approvata']);
        ExpenseLine::factory()->for($expense)->actual()->create(['amount' => '30.00']);
        ExpenseLine::factory()->for($expense)->annulled()->create(['amount' => '500.00']);
        $item = ProposalItem::factory()->for($proposal)->create([
            'source_type' => $type, $type.'_id' => $live->id,
            'baseline_revision' => 0, 'baseline_fingerprint' => str_repeat('a', 64),
        ]);
        $identities[(string) $item->proposal_item_id] = $live->fresh();
    }
    $proposal->load(['company', 'exercise', 'items.actions', 'actions']);

    return [$proposal, $identities, $supplier];
}

function measuredBudgetPayload(Proposal $proposal, array $identities): array
{
    DB::flushQueryLog();
    DB::enableQueryLog();
    try {
        $payload = BudgetSnapshotPayload::build($proposal, $identities, [1, 2]);
        $queries = DB::getQueryLog();
    } finally {
        DB::disableQueryLog();
        DB::flushQueryLog();
    }

    return [$payload, count($queries)];
}

it('keeps snapshot dependency queries bounded as the number of sources grows', function (string $type) {
    $counts = [];
    foreach ([1, 10] as $count) {
        [$proposal, $identities, $supplier] = budgetLoadingFixture($type, $count);
        [$payload, $counts[]] = measuredBudgetPayload($proposal, $identities);
        $allocation = $type === 'project' ? '125.00' : '120.00';
        expect($payload['total'])->toBe(Decimal::multiply($allocation, (string) $count))
            ->and($payload['rows'])->toHaveCount($count);
        foreach ($payload['rows'] as $row) {
            expect($row['approved_allocation'])->toBe($allocation)
                ->and($row['approved_estimates'])->toBe('120.00')
                ->and($row['cost_center_label'])->toBe('IT / Software')
                ->and($row['supplier_label'])->toBe($type === 'project' ? null : $supplier->legal_name)
                ->and($row['detail']['approval_event_sequences'])->toBe([1, 2]);
            if ($type === 'contract') {
                expect($row['detail']['contract']['conditions'])->toHaveCount(1)
                    ->and($row['detail']['contract']['annual_composition'])->toHaveCount(12);
            } else {
                $expense = $type === 'expense' ? $row['detail']['expense'] : $row['detail']['project']['expenses'][0];
                expect($expense['active_estimate_lines'])->toHaveCount(1)
                    ->and($expense['active_estimate_lines'][0]['amount'])->toBe('120.00');
            }
            if ($type !== 'expense') {
                expect($row['detail']['relations'])->toHaveCount(1)
                    ->and($row['detail']['relations'][0]['note'])->toBe('Relazione informativa');
            }
        }
    }

    expect($counts[1])->toBeLessThanOrEqual($counts[0] + 2);
})->with(['expense', 'project', 'contract']);

it('preserves inherited classifications and avoids counting child expenses twice', function (string $type) {
    [$proposal, $identities] = budgetLoadingFixture($type, 3);
    foreach ($identities as $owner) {
        $expense = $owner->expenses()->where('exercise_id', $proposal->exercise_id)->sole();
        ProposalItem::factory()->for($proposal)->create([
            'source_type' => 'expense', 'expense_id' => $expense->id,
            'baseline_revision' => 0, 'baseline_fingerprint' => str_repeat('a', 64),
        ]);
    }
    $proposal->load('items.actions');

    $resolved = BudgetSnapshotPayload::build($proposal, $identities, []);
    $fromItems = BudgetSnapshotPayload::build($proposal->fresh(), [], []);

    expect($fromItems)->toBe($resolved)
        ->and($resolved['total'])->toBe($type === 'project' ? '375.00' : '360.00')
        ->and($resolved['rows'])->toHaveCount(6);
    foreach (array_slice($resolved['rows'], 3) as $row) {
        expect($row['detail']['expense']['owner']['type'])->toBe($type)
            ->and($row['cost_center_label'])->toBe('IT / Software')
            ->and($row['detail']['expense']['cost_center']['label'])->toBe('IT / Software');
    }
})->with(['project', 'contract']);
