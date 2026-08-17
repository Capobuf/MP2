<?php

namespace App\Domain\Expenses;

use App\Models\Exercise;
use App\Models\Expense;
use App\Models\Project;

final readonly class ExpenseImpactPlan
{
    /**
     * @param  array<int, int>  $exerciseRevisions
     * @param  array<int, array<string, int|string>>  $exerciseImpacts
     * @param  array<int, int>  $projectRevisions
     * @param  array<int, array<string, int|string>>  $projectImpacts
     * @param  list<int>  $lineIds
     */
    public function __construct(
        public int $expenseId,
        public string $originKey,
        public int $expenseRevision,
        public int $sourceExerciseId,
        public int $targetExerciseId,
        public ?int $supplierId,
        public ?int $directCostCenterId,
        public ?string $reason,
        public array $exerciseRevisions,
        public array $exerciseImpacts,
        public ?int $sourceProjectId,
        public ?int $targetProjectId,
        public array $projectRevisions,
        public array $projectImpacts,
        public ?int $sourceCostCenterId,
        public ?int $targetCostCenterId,
        public array $lineIds,
        public ?string $actualKind,
        public ?string $activityNote,
        public bool $openProject,
        public ?string $overspendNote,
    ) {}

    public static function build(
        Expense $expense,
        Exercise $source,
        Exercise $target,
        ?int $supplierId,
        ?int $directCostCenterId,
        ?string $reason,
        ?Project $sourceProject = null,
        ?Project $targetProject = null,
        ?string $actualKind = null,
        ?string $activityNote = null,
        bool $openProject = false,
        ?string $overspendNote = null,
    ): self {
        $allocation = $expense->allocation();
        $actual = $expense->actual();

        if ($source->is($target)) {
            $impacts = [(string) $source->id => self::row($source, '0.00', '0.00')];
            $revisions = [(string) $source->id => $source->revision];
        } else {
            $impacts = [
                (string) $source->id => self::row($source, Decimal::subtract('0.00', $allocation), Decimal::subtract('0.00', $actual)),
                (string) $target->id => self::row($target, $allocation, $actual),
            ];
            $revisions = [
                (string) $source->id => $source->revision,
                (string) $target->id => $target->revision,
            ];
        }

        ksort($impacts, SORT_NUMERIC);
        ksort($revisions, SORT_NUMERIC);
        $projectRevisions = [];
        $projectImpacts = [];
        foreach (array_filter([$sourceProject, $targetProject]) as $project) {
            $projectRevisions[(string) $project->id] = $project->revision;
            $totals = $project->annualTotals()[$target->id] ?? ['allocation' => '0.00', 'actual' => '0.00'];
            $allocationDelta = '0.00';
            $actualDelta = '0.00';
            if ($sourceProject?->is($project) && ! $targetProject?->is($project)) {
                $allocationDelta = Decimal::subtract('0.00', $allocation);
                $actualDelta = Decimal::subtract('0.00', $actual);
            } elseif ($targetProject?->is($project) && ! $sourceProject?->is($project)) {
                $allocationDelta = $allocation;
                $actualDelta = $actual;
            }
            $allocationAfter = Decimal::add($totals['allocation'], $allocationDelta);
            $actualAfter = Decimal::add($totals['actual'], $actualDelta);
            $projectImpacts[(string) $project->id] = [
                'exercise_id' => $target->id,
                'allocation_before' => $totals['allocation'],
                'allocation_after' => $allocationAfter,
                'allocation_delta' => Decimal::money($allocationDelta),
                'actual_before' => $totals['actual'],
                'actual_after' => $actualAfter,
                'actual_delta' => Decimal::money($actualDelta),
                'variance_before' => Decimal::subtract($totals['actual'], $totals['allocation']),
                'variance_after' => Decimal::subtract($actualAfter, $allocationAfter),
            ];
        }
        ksort($projectRevisions, SORT_NUMERIC);
        ksort($projectImpacts, SORT_NUMERIC);

        $sourceCostCenterId = $sourceProject === null
            ? $expense->direct_cost_center_id
            : $sourceProject->classifications()->where('exercise_id', $source->id)->value('cost_center_id');
        $targetCostCenterId = $targetProject === null
            ? $directCostCenterId
            : $targetProject->classifications()->where('exercise_id', $target->id)->value('cost_center_id');

        return new self(
            expenseId: $expense->id,
            originKey: $expense->originKey(),
            expenseRevision: $expense->revision,
            sourceExerciseId: $source->id,
            targetExerciseId: $target->id,
            supplierId: $supplierId,
            directCostCenterId: $directCostCenterId,
            reason: $reason,
            exerciseRevisions: $revisions,
            exerciseImpacts: $impacts,
            sourceProjectId: $sourceProject?->id,
            targetProjectId: $targetProject?->id,
            projectRevisions: $projectRevisions,
            projectImpacts: $projectImpacts,
            sourceCostCenterId: $sourceCostCenterId === null ? null : (int) $sourceCostCenterId,
            targetCostCenterId: $targetCostCenterId === null ? null : (int) $targetCostCenterId,
            lineIds: $expense->lines()->orderBy('id')->pluck('id')->map(fn (mixed $id): int => (int) $id)->all(),
            actualKind: $actualKind,
            activityNote: $activityNote,
            openProject: $openProject,
            overspendNote: $overspendNote,
        );
    }

    /** @return array<int, string> */
    public function allocatedImpact(): array
    {
        return array_map(fn (array $row): string => (string) $row['allocation_delta'], $this->exerciseImpacts);
    }

    /** @return array<int, string> */
    public function actualImpact(): array
    {
        return array_map(fn (array $row): string => (string) $row['actual_delta'], $this->exerciseImpacts);
    }

    public function fingerprint(): string
    {
        return hash('sha256', json_encode($this->toArray(), JSON_THROW_ON_ERROR));
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'expense_id' => $this->expenseId,
            'origin_key' => $this->originKey,
            'expense_revision' => $this->expenseRevision,
            'source_exercise_id' => $this->sourceExerciseId,
            'target_exercise_id' => $this->targetExerciseId,
            'supplier_id' => $this->supplierId,
            'direct_cost_center_id' => $this->directCostCenterId,
            'reason' => $this->reason,
            'exercise_revisions' => $this->exerciseRevisions,
            'exercise_impacts' => $this->exerciseImpacts,
            'source_project_id' => $this->sourceProjectId,
            'target_project_id' => $this->targetProjectId,
            'project_revisions' => $this->projectRevisions,
            'project_impacts' => $this->projectImpacts,
            'source_cost_center_id' => $this->sourceCostCenterId,
            'target_cost_center_id' => $this->targetCostCenterId,
            'line_ids' => $this->lineIds,
            'actual_kind' => $this->actualKind,
            'activity_note' => $this->activityNote,
            'open_project' => $this->openProject,
            'overspend_note' => $this->overspendNote,
        ];
    }

    /** @return array<string, int|string> */
    private static function row(Exercise $exercise, string $allocationDelta, string $actualDelta): array
    {
        $allocationBefore = $exercise->allocation();
        $actualBefore = $exercise->actual();

        return [
            'year' => $exercise->year,
            'allocation_before' => $allocationBefore,
            'allocation_after' => Decimal::add($allocationBefore, $allocationDelta),
            'allocation_delta' => Decimal::money($allocationDelta),
            'actual_before' => $actualBefore,
            'actual_after' => Decimal::add($actualBefore, $actualDelta),
            'actual_delta' => Decimal::money($actualDelta),
        ];
    }
}
