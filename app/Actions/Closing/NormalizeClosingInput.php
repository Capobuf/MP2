<?php

namespace App\Actions\Closing;

use App\Domain\Expenses\ExerciseStatus;
use App\Domain\Projects\ProjectDeferralMode;
use App\Models\Exercise;
use App\Models\Project;
use App\Models\ProjectDeferral;
use Illuminate\Validation\ValidationException;

final class NormalizeClosingInput
{
    public function __construct(private readonly BuildClosingReprogrammingPlan $reprogramming) {}

    /** @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    public function execute(Exercise $exercise, array $input): array
    {
        $decisions = $input['projects'] ?? [];
        if (! is_array($decisions)) {
            return $input;
        }

        $nextExercise = Exercise::query()
            ->where('company_id', $exercise->company_id)
            ->where('year', $exercise->year + 1)
            ->first();
        if ($nextExercise === null && filter_var($input['create_next_exercise'] ?? false, FILTER_VALIDATE_BOOL)) {
            $nextExercise = new Exercise([
                'company_id' => $exercise->company_id,
                'year' => $exercise->year + 1,
                'status' => ExerciseStatus::Open,
                'revision' => 0,
            ]);
        }

        foreach ($decisions as $key => $decision) {
            if (! is_array($decision)) {
                continue;
            }
            $projectId = isset($decision['project_id']) ? (int) $decision['project_id'] : (is_numeric($key) ? (int) $key : 0);
            if ($projectId < 1 || ($decision['mode'] ?? null) !== ProjectDeferralMode::Reprogramming->value) {
                continue;
            }
            $project = Project::query()->where('company_id', $exercise->company_id)->find($projectId);
            if ($project === null) {
                throw ValidationException::withMessages(['projects' => 'Progetto non disponibile per questa Azienda.']);
            }
            $active = ProjectDeferral::query()
                ->where('project_id', $project->id)
                ->where('source_exercise_id', $exercise->id)
                ->first();
            if ($active?->mode === ProjectDeferralMode::Reprogramming) {
                $decision['reprogrammed_amount'] = (string) $active->reprogrammed_amount;
                $decisions[$key] = $decision;

                continue;
            }
            if (! $nextExercise instanceof Exercise) {
                throw ValidationException::withMessages([
                    'create_next_exercise' => 'La Riprogrammazione richiede l’Esercizio successivo.',
                ]);
            }

            $plan = $this->reprogramming->build(
                $project,
                $exercise,
                $nextExercise,
                $decision['source_estimate_reductions'] ?? null,
            );
            $decision['source_estimate_reductions'] = $plan['source_estimate_reductions'];
            $decision['destination_plans'] = $plan['destination_plans'];
            $decision['reprogrammed_amount'] = $plan['reprogrammed_amount'];
            $decisions[$key] = $decision;
        }

        $input['projects'] = $decisions;

        return $input;
    }
}
