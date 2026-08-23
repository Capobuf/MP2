<?php

namespace App\Actions\Closing;

use App\Domain\Closing\ClosingOverspendNotes;
use App\Domain\Closing\ClosingReview;
use App\Models\Exercise;
use App\Models\User;

final class PrepareExerciseClosing
{
    public function __construct(
        private readonly NormalizeClosingInput $normalize,
        private readonly ReviewExerciseClosing $review,
    ) {}

    /**
     * @param array<string, mixed> $input
     * @return array{input: array<string, mixed>, review: ClosingReview, execution_fingerprint: string}
     */
    public function execute(User $actor, Exercise $exercise, array $input): array
    {
        $normalized = $this->normalize->execute($exercise, $input);
        $economicReview = $this->review->execute($actor, $exercise, $normalized);
        $executionFingerprint = $economicReview->fingerprint();
        $review = $economicReview;
        $overspendBlocks = ClosingOverspendNotes::missingRequired($exercise->company, $exercise);
        if ($overspendBlocks !== []) {
            $review = new ClosingReview(
                exerciseId: $economicReview->exerciseId,
                exerciseYear: $economicReview->exerciseYear,
                totals: $economicReview->totals,
                blocks: [...$economicReview->blocks, ...$overspendBlocks],
                warnings: $economicReview->warnings,
                projectDecisions: $economicReview->projectDecisions,
                affectedExercises: $economicReview->affectedExercises,
                budget: $economicReview->budget,
                nextExercise: $economicReview->nextExercise,
                appliedSettings: $economicReview->appliedSettings,
                sourceState: [
                    ...$economicReview->sourceState,
                    'required_overspend_note_blocks' => $overspendBlocks,
                ],
            );
        }

        return [
            'input' => $normalized,
            'review' => $review,
            'execution_fingerprint' => $executionFingerprint,
        ];
    }
}
