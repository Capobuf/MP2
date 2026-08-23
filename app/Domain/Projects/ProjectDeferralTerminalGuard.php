<?php

namespace App\Domain\Projects;

use App\Models\Project;
use App\Models\ProjectDeferral;
use Illuminate\Validation\ValidationException;

final class ProjectDeferralTerminalGuard
{
    /**
     * @param  iterable<ProjectDeferral>  $deferrals
     * @param  list<array<string, mixed>>  $transitionRows
     */
    public static function validate(Project $project, iterable $deferrals, array $transitionRows): void
    {
        foreach ($deferrals as $deferral) {
            if ($deferral->mode === ProjectDeferralMode::None || ! $deferral->sourceExercise->isOpen()) {
                continue;
            }
            $state = ProjectStateTimeline::stateAtDate(
                $project->initialState(),
                $project->initialEffectiveDate()->toDateString(),
                $transitionRows,
                $deferral->sourceExercise->year.'-12-31',
            );
            if (in_array($state, [ProjectState::Closed, ProjectState::Cancelled], true)) {
                throw ValidationException::withMessages([
                    'transition' => 'Prima della transizione terminale impostare il rinvio del Progetto su Nessuna.',
                ]);
            }
        }
    }
}
