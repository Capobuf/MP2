<?php

namespace App\Domain\Projects;

use App\Domain\Expenses\Decimal;
use App\Models\Exercise;
use App\Models\Project;
use App\Models\ProjectExerciseClassification;
use Carbon\CarbonImmutable;

final readonly class ProjectAnnualSituation
{
    public function __construct(
        public int $exerciseId,
        public int $year,
        public string $referenceDate,
        public ?ProjectState $state,
        public ?int $costCenterId,
        public ?string $costCenterLabel,
        public string $allocation,
        public string $actual,
        public string $variance,
    ) {}

    /**
     * @param  iterable<Exercise>  $exercises
     * @return list<self>
     */
    public static function build(Project $project, iterable $exercises, CarbonImmutable $today): array
    {
        $totals = $project->annualTotals();
        $classifications = $project->relationLoaded('classifications')
            ? $project->classifications
            : $project->classifications()->with('costCenter')->get();
        $situations = [];

        foreach ($exercises as $exercise) {
            $referenceDate = ProjectAnnualReferenceDate::forYear($exercise->year, $today);
            /** @var ProjectExerciseClassification|null $classification */
            $classification = $classifications->firstWhere('exercise_id', $exercise->id);
            $costCenter = $classification?->costCenter;
            $allocation = $totals[$exercise->id]['allocation'] ?? '0.00';
            $actual = $totals[$exercise->id]['actual'] ?? '0.00';

            $situations[] = new self(
                exerciseId: $exercise->id,
                year: $exercise->year,
                referenceDate: $referenceDate->toDateString(),
                state: $project->stateAtDate($referenceDate->toDateString()),
                costCenterId: $classification?->cost_center_id,
                costCenterLabel: $costCenter === null
                    ? null
                    : $costCenter->name.($costCenter->isArchived() ? ' · Archiviato' : ''),
                allocation: $allocation,
                actual: $actual,
                variance: Decimal::subtract($actual, $allocation),
            );
        }

        usort($situations, fn (self $left, self $right): int => $right->year <=> $left->year);

        return $situations;
    }

    /** @return array<string, int|string|null> */
    public function toArray(): array
    {
        return [
            'exercise_id' => $this->exerciseId,
            'year' => $this->year,
            'reference_date' => $this->referenceDate,
            'state' => $this->state?->label() ?? 'Assente alla data',
            'cost_center_id' => $this->costCenterId,
            'cost_center' => $this->costCenterLabel,
            'allocation' => $this->allocation,
            'actual' => $this->actual,
            'variance' => $this->variance,
        ];
    }
}
