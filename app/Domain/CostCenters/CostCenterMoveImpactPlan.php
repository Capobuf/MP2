<?php

namespace App\Domain\CostCenters;

use App\Domain\Expenses\Decimal;
use App\Domain\Projects\ProjectDeferralMode;
use App\Models\Contract;
use App\Models\ContractExerciseClassification;
use App\Models\CostCenter;
use App\Models\Exercise;
use App\Models\Expense;
use App\Models\Project;
use App\Models\ProjectExerciseClassification;

final readonly class CostCenterMoveImpactPlan
{
    /**
     * @param  list<int>  $subtreeIds
     * @param  list<array<string, mixed>>  $exerciseImpacts
     * @param  list<int>  $expenseIds
     * @param  list<int>  $projectIds
     * @param  list<int>  $contractIds
     */
    public function __construct(
        public int $companyId,
        public int $costCenterId,
        public ?int $oldParentId,
        public ?int $newParentId,
        public string $oldPath,
        public string $newPath,
        public array $subtreeIds,
        public array $exerciseImpacts,
        public array $expenseIds,
        public array $projectIds,
        public array $contractIds,
    ) {}

    public static function build(CostCenter $costCenter, ?int $newParentId): self
    {
        $hierarchy = CostCenterHierarchy::forCompany((int) $costCenter->company_id);
        $hierarchy->assertCanAssignParent((int) $costCenter->id, (int) $costCenter->company_id, $newParentId);
        $subtreeIds = $hierarchy->descendantIds((int) $costCenter->id);
        $oldPath = $hierarchy->path((int) $costCenter->id);
        $newPath = $newParentId === null
            ? $costCenter->name
            : $hierarchy->path($newParentId).' / '.$costCenter->name;
        $expenseIds = [];
        $projectIds = [];
        $contractIds = [];
        $impacts = [];

        foreach (Exercise::query()->where('company_id', $costCenter->company_id)->open()->orderBy('year')->get() as $exercise) {
            $expenses = Expense::query()
                ->where('company_id', $costCenter->company_id)
                ->where('exercise_id', $exercise->id)
                ->whereNull('project_id')
                ->whereNull('contract_id')
                ->whereIn('direct_cost_center_id', $subtreeIds)
                ->with('lines')
                ->orderBy('id')
                ->get();
            $projectClassifications = ProjectExerciseClassification::query()
                ->where('company_id', $costCenter->company_id)
                ->where('exercise_id', $exercise->id)
                ->whereIn('cost_center_id', $subtreeIds)
                ->with(['project.expenses.lines', 'project.deferrals'])
                ->orderBy('id')
                ->get();
            $contractClassifications = ContractExerciseClassification::query()
                ->where('company_id', $costCenter->company_id)
                ->where('exercise_id', $exercise->id)
                ->whereIn('cost_center_id', $subtreeIds)
                ->with(['contract.expenses.lines'])
                ->orderBy('id')
                ->get();

            if ($expenses->isEmpty() && $projectClassifications->isEmpty() && $contractClassifications->isEmpty()) {
                continue;
            }

            $allocation = Decimal::sum($expenses->map(fn (Expense $expense): string => $expense->allocation()));
            $actual = Decimal::sum($expenses->map(fn (Expense $expense): string => $expense->actual()));
            $carryover = '0.00';

            foreach ($projectClassifications as $classification) {
                $project = $classification->project;
                if (! $project instanceof Project) {
                    continue;
                }
                $totals = $project->annualTotals()[$exercise->id] ?? ['allocation' => '0.00', 'actual' => '0.00'];
                $allocation = Decimal::add($allocation, (string) $totals['allocation']);
                $actual = Decimal::add($actual, (string) $totals['actual']);
                $carryover = Decimal::add($carryover, Decimal::sum($project->deferrals
                    ->filter(fn ($deferral): bool => $deferral->source_exercise_id === $exercise->id
                        && $deferral->mode === ProjectDeferralMode::Carryover)
                    ->pluck('carryover_amount')));
                $projectIds[] = (int) $project->id;
            }

            foreach ($contractClassifications as $classification) {
                $contract = $classification->contract;
                if (! $contract instanceof Contract) {
                    continue;
                }
                $totals = $contract->annualTotals()[$exercise->id] ?? ['allocation' => '0.00', 'actual' => '0.00'];
                $allocation = Decimal::add($allocation, (string) $totals['allocation']);
                $actual = Decimal::add($actual, (string) $totals['actual']);
                $contractIds[] = (int) $contract->id;
            }

            array_push($expenseIds, ...$expenses->pluck('id')->map(fn (mixed $id): int => (int) $id)->all());
            $impacts[] = [
                'exercise_id' => (int) $exercise->id,
                'exercise_year' => (int) $exercise->year,
                'old_branch' => $oldPath,
                'new_branch' => $newPath,
                'allocation_moved' => $allocation,
                'actual_moved' => $actual,
                'carryover_moved' => $carryover,
                'source_count' => $expenses->count() + $projectClassifications->count() + $contractClassifications->count(),
            ];
        }

        return new self(
            companyId: (int) $costCenter->company_id,
            costCenterId: (int) $costCenter->id,
            oldParentId: $costCenter->parent_id === null ? null : (int) $costCenter->parent_id,
            newParentId: $newParentId,
            oldPath: $oldPath,
            newPath: $newPath,
            subtreeIds: $subtreeIds,
            exerciseImpacts: $impacts,
            expenseIds: array_values(array_unique($expenseIds)),
            projectIds: array_values(array_unique($projectIds)),
            contractIds: array_values(array_unique($contractIds)),
        );
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return get_object_vars($this);
    }

    public function fingerprint(): string
    {
        return hash('sha256', json_encode($this->toArray(), JSON_THROW_ON_ERROR));
    }
}
