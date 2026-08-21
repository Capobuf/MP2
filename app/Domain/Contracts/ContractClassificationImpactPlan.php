<?php

namespace App\Domain\Contracts;

use App\Models\Contract;
use App\Models\ContractExerciseClassification;
use App\Models\Exercise;

final readonly class ContractClassificationImpactPlan
{
    /** @param list<int> $expenseIds */
    public function __construct(
        public int $contractId,
        public int $exerciseId,
        public int $contractRevision,
        public int $exerciseRevision,
        public ?int $classificationId,
        public ?int $oldCostCenterId,
        public ?int $newCostCenterId,
        public array $expenseIds,
        public string $allocation,
        public string $actual,
    ) {}

    public static function build(Contract $contract, Exercise $exercise, ?int $newCostCenterId): self
    {
        /** @var ContractExerciseClassification|null $classification */
        $classification = $contract->classifications()->where('exercise_id', $exercise->id)->first();
        $totals = $contract->annualTotals()[$exercise->id] ?? ['allocation' => '0.00', 'actual' => '0.00'];

        return new self(
            contractId: $contract->id,
            exerciseId: $exercise->id,
            contractRevision: $contract->revision,
            exerciseRevision: $exercise->revision,
            classificationId: $classification?->id,
            oldCostCenterId: $classification?->cost_center_id,
            newCostCenterId: $newCostCenterId,
            expenseIds: $contract->expenses()->where('exercise_id', $exercise->id)->orderBy('id')->pluck('id')->map(fn (mixed $id): int => (int) $id)->all(),
            allocation: $totals['allocation'],
            actual: $totals['actual'],
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
