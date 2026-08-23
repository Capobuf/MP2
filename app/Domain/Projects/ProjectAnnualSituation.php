<?php

namespace App\Domain\Projects;

use App\Domain\Expenses\Decimal;
use App\Models\Exercise;
use App\Models\Project;
use App\Models\ProjectDeferral;
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
        public string $estimates,
        public string $receivedCarryover,
        public string $allocation,
        public string $actual,
        public string $variance,
        public string $residual,
        public string $maximumTransferable,
        public ProjectDeferralMode $incomingDeferralMode,
        public string $incomingDeferralAmount,
        public bool $carryoverAboveCurrentMaximum,
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
        $deferrals = $project->relationLoaded('deferrals') ? $project->deferrals : $project->deferrals()->get();

        foreach ($exercises as $exercise) {
            $referenceDate = ProjectAnnualReferenceDate::forYear($exercise->year, $today);
            /** @var ProjectExerciseClassification|null $classification */
            $classification = $classifications->firstWhere('exercise_id', $exercise->id);
            $costCenter = $classification?->costCenter;
            $allocation = $totals[$exercise->id]['allocation'] ?? '0.00';
            $actual = $totals[$exercise->id]['actual'] ?? '0.00';
            $incoming = $deferrals->firstWhere('destination_exercise_id', $exercise->id);
            $outgoing = $deferrals->firstWhere('source_exercise_id', $exercise->id);
            $receivedCarryover = $incoming?->mode === ProjectDeferralMode::Carryover
                ? (string) $incoming->carryover_amount
                : '0.00';
            $residual = ProjectDeferralValues::residual($allocation, $actual);
            $maximum = ProjectDeferralValues::maximumTransferable($allocation, $actual);
            $incomingMode = $incoming instanceof ProjectDeferral ? $incoming->mode : ProjectDeferralMode::None;

            $situations[] = new self(
                exerciseId: $exercise->id,
                year: $exercise->year,
                referenceDate: $referenceDate->toDateString(),
                state: $project->stateAtDate($referenceDate->toDateString()),
                costCenterId: $classification?->cost_center_id,
                costCenterLabel: $costCenter === null
                    ? null
                    : $costCenter->name.($costCenter->isArchived() ? ' · Archiviato' : ''),
                estimates: Decimal::subtract($allocation, $receivedCarryover),
                receivedCarryover: $receivedCarryover,
                allocation: $allocation,
                actual: $actual,
                variance: Decimal::subtract($actual, $allocation),
                residual: $residual,
                maximumTransferable: $maximum,
                incomingDeferralMode: $incomingMode,
                incomingDeferralAmount: match ($incomingMode) {
                    ProjectDeferralMode::Carryover => (string) $incoming->carryover_amount,
                    ProjectDeferralMode::Reprogramming => (string) $incoming->reprogrammed_amount,
                    default => '0.00',
                },
                carryoverAboveCurrentMaximum: $outgoing?->mode === ProjectDeferralMode::Carryover
                    && Decimal::compare((string) $outgoing->carryover_amount, $maximum) > 0,
            );
        }

        usort($situations, fn (self $left, self $right): int => $right->year <=> $left->year);

        return $situations;
    }

    /** @return array<string, bool|int|string|null> */
    public function toArray(): array
    {
        return [
            'exercise_id' => $this->exerciseId,
            'year' => $this->year,
            'reference_date' => $this->referenceDate,
            'state' => $this->state?->label() ?? 'Assente alla data',
            'cost_center_id' => $this->costCenterId,
            'cost_center' => $this->costCenterLabel,
            'estimates' => $this->estimates,
            'received_carryover' => $this->receivedCarryover,
            'allocation' => $this->allocation,
            'actual' => $this->actual,
            'variance' => $this->variance,
            'residual' => $this->residual,
            'maximum_transferable' => $this->maximumTransferable,
            'incoming_deferral_mode' => $this->incomingDeferralMode->label(),
            'incoming_deferral_amount' => $this->incomingDeferralAmount,
            'carryover_above_current_maximum' => $this->carryoverAboveCurrentMaximum,
        ];
    }
}
