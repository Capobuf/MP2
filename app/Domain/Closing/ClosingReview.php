<?php

namespace App\Domain\Closing;

final readonly class ClosingReview
{
    /**
     * @param  array<string, string>  $totals
     * @param  list<array<string, mixed>>  $blocks
     * @param  list<array<string, mixed>>  $warnings
     * @param  list<array<string, mixed>>  $projectDecisions
     * @param  list<array<string, mixed>>  $affectedExercises
     * @param  array<string, mixed>  $budget
     * @param  array<string, mixed>  $nextExercise
     * @param  array<string, mixed>  $appliedSettings
     * @param  array<string, mixed>  $sourceState
     */
    public function __construct(
        public int $exerciseId,
        public int $exerciseYear,
        public array $totals,
        public array $blocks,
        public array $warnings,
        public array $projectDecisions,
        public array $affectedExercises,
        public array $budget,
        public array $nextExercise,
        public array $appliedSettings,
        public array $sourceState,
    ) {}

    public function canClose(): bool
    {
        return $this->blocks === [];
    }

    public function fingerprint(): string
    {
        $payload = $this->toArray();
        self::sortRecursive($payload);

        return hash('sha256', json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION));
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'exercise_id' => $this->exerciseId,
            'exercise_year' => $this->exerciseYear,
            'totals' => $this->totals,
            'blocks' => $this->blocks,
            'warnings' => $this->warnings,
            'project_decisions' => $this->projectDecisions,
            'affected_exercises' => $this->affectedExercises,
            'budget' => $this->budget,
            'next_exercise' => $this->nextExercise,
            'applied_settings' => $this->appliedSettings,
            'source_state' => $this->sourceState,
        ];
    }

    /** @param array<mixed> $value */
    private static function sortRecursive(array &$value): void
    {
        if (! array_is_list($value)) {
            ksort($value);
        }

        foreach ($value as &$item) {
            if (is_array($item)) {
                self::sortRecursive($item);
            }
        }
    }
}
