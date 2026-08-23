<?php

namespace App\Actions\Closing;

use App\Domain\Closing\ClosingOverspendNotes;
use App\Domain\Closing\ClosingReview;
use App\Domain\Closing\ContractClosingProjection;
use App\Domain\Expenses\Decimal;
use App\Models\Contract;
use App\Models\Exercise;
use App\Models\User;

final class PrepareExerciseClosing
{
    public function __construct(
        private readonly NormalizeClosingInput $normalize,
        private readonly ReviewExerciseClosing $review,
    ) {}

    /**
     * @param  array<string, mixed>  $input
     * @return array{input: array<string, mixed>, review: ClosingReview, execution_fingerprint: string}
     */
    public function execute(User $actor, Exercise $exercise, array $input): array
    {
        $normalized = $this->normalize->execute($exercise, $input);
        $economicReview = $this->review->execute($actor, $exercise, $normalized);
        $executionFingerprint = $economicReview->fingerprint();
        $affectedExercises = $this->withNewExerciseContractImpact($exercise, $economicReview);
        $overspendBlocks = ClosingOverspendNotes::missingRequired($exercise->company, $exercise);

        $review = new ClosingReview(
            exerciseId: $economicReview->exerciseId,
            exerciseYear: $economicReview->exerciseYear,
            totals: $economicReview->totals,
            blocks: [...$economicReview->blocks, ...$overspendBlocks],
            warnings: $economicReview->warnings,
            projectDecisions: $economicReview->projectDecisions,
            affectedExercises: $affectedExercises,
            budget: $economicReview->budget,
            nextExercise: $economicReview->nextExercise,
            appliedSettings: $economicReview->appliedSettings,
            sourceState: [
                ...$economicReview->sourceState,
                'required_overspend_note_blocks' => $overspendBlocks,
            ],
        );

        return [
            'input' => $normalized,
            'review' => $review,
            'execution_fingerprint' => $executionFingerprint,
        ];
    }

    /** @return list<array<string, mixed>> */
    private function withNewExerciseContractImpact(Exercise $exercise, ClosingReview $review): array
    {
        if (($review->nextExercise['exists'] ?? false) || ($review->nextExercise['management_continues'] ?? null) !== true) {
            return $review->affectedExercises;
        }

        $nextYear = $exercise->year + 1;
        $contractDelta = '0.00';
        $projections = [];
        $contractRows = $review->sourceState['contracts'] ?? [];
        if (is_array($contractRows)) {
            foreach ($contractRows as $row) {
                if (is_array($row) && isset($row['id'])) {
                    $projections[(int) $row['id']] = $row;
                }
            }
        }
        foreach (Contract::query()->where('company_id', $exercise->company_id)->with('renewalConfigurations')->orderBy('id')->get() as $contract) {
            $projection = $projections[$contract->id]['projection'] ?? null;
            if (! is_array($projection)) {
                continue;
            }
            $allocation = ContractClosingProjection::allocationForYear($contract, $projection, $nextYear);
            $contractDelta = Decimal::add($contractDelta, $allocation['amount']);
        }

        $affected = $review->affectedExercises;
        foreach ($affected as &$impact) {
            if (($impact['exercise_id'] ?? null) === null && (int) ($impact['year'] ?? 0) === $nextYear) {
                $impact['allocation_delta'] = Decimal::add((string) ($impact['allocation_delta'] ?? '0.00'), $contractDelta);
                $impact['contract_allocation_created'] = $contractDelta;

                return $affected;
            }
        }
        unset($impact);

        $affected[] = [
            'exercise_id' => null,
            'year' => $nextYear,
            'status' => 'will_be_created',
            'revision' => null,
            'allocation_delta' => $contractDelta,
            'contract_allocation_created' => $contractDelta,
        ];

        return $affected;
    }
}
