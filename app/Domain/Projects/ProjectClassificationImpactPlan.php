<?php

namespace App\Domain\Projects;

use App\Models\Exercise;
use App\Models\Project;
use App\Models\ProjectExerciseClassification;

final readonly class ProjectClassificationImpactPlan
{
    /** @param list<int> $expenseIds */
    public function __construct(
        public int $projectId,
        public int $exerciseId,
        public int $projectRevision,
        public int $exerciseRevision,
        public ?int $classificationId,
        public ?int $oldCostCenterId,
        public ?int $newCostCenterId,
        public array $expenseIds,
        public string $allocation,
        public string $actual,
    ) {}

    public static function build(Project $project, Exercise $exercise, ?int $newCostCenterId): self
    {
        /** @var ProjectExerciseClassification|null $classification */
        $classification = $project->classifications()->where('exercise_id', $exercise->id)->first();
        $totals = $project->annualTotals()[$exercise->id] ?? ['allocation' => '0.00', 'actual' => '0.00'];

        return new self(
            projectId: $project->id,
            exerciseId: $exercise->id,
            projectRevision: $project->revision,
            exerciseRevision: $exercise->revision,
            classificationId: $classification?->id,
            oldCostCenterId: $classification?->cost_center_id,
            newCostCenterId: $newCostCenterId,
            expenseIds: $project->expenses()->where('exercise_id', $exercise->id)->orderBy('id')->pluck('id')->map(fn (mixed $id): int => (int) $id)->all(),
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
