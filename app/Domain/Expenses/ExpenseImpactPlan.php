<?php

namespace App\Domain\Expenses;

use App\Models\Exercise;
use App\Models\Expense;

final readonly class ExpenseImpactPlan
{
    /**
     * @param  array<int, int>  $exerciseRevisions
     * @param  array<int, array<string, int|string>>  $exerciseImpacts
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
    ) {}

    public static function build(
        Expense $expense,
        Exercise $source,
        Exercise $target,
        ?int $supplierId,
        ?int $directCostCenterId,
        ?string $reason,
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
