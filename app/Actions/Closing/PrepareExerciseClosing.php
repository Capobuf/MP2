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
     * @return array{input: array<string, mixed>, review: ClosingReview}
     */
    public function execute(User $actor, Exercise $exercise, array $input): array
    {
        $normalized = $this->normalize->execute($exercise, $input);
        $review = $this->review->execute($actor, $exercise, $normalized);
        $overspendBlocks = ClosingOverspendNotes::missingRequired($exercise->company, $exercise);
        if ($overspendBlocks !== []) {
            $review = new ClosingReview(
                exerciseId: $review->exerciseId,
                exerciseYear: $review->exerciseYear,
                totals: $review->totals,
                blocks: [...$review->blocks, ...$overspendBlocks],
                warnings: $review->warnings,
                projectDecisions: $review->projectDecisions,
                affectedExercises: $review->affectedExercises,
                budget: $review->budget,
                nextExercise: $review->nextExercise,
                appliedSettings: $review->appliedSettings,
                sourceState: [
                    ...$review->sourceState,
                    'required_overspend_note_blocks' => $overspendBlocks,
                ],
            );
        }

        return ['input' => $normalized, 'review' => $review];
    }
}
