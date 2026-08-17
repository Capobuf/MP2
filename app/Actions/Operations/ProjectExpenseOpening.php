<?php

namespace App\Actions\Operations;

use App\Domain\Projects\ProjectState;
use App\Domain\Projects\ProjectStateTimeline;
use App\Models\Company;
use App\Models\Project;
use App\Models\ProjectTransition;
use App\Models\User;
use Illuminate\Validation\ValidationException;

final class ProjectExpenseOpening
{
    /**
     * @param  array{actual_kind: mixed, activity_note: ?string, open_project: bool, overspend_note: ?string, today: string}  $context
     * @param  iterable<ProjectTransition>  $transitions
     */
    public function create(Project $project, Company $company, User $actor, array $context, iterable $transitions): ?ProjectTransition
    {
        if (! $context['open_project']) {
            return null;
        }

        $rows = [];
        foreach ($transitions as $transition) {
            $rows[] = [
                'from_state' => $transition->from_state,
                'to_state' => $transition->to_state,
                'effective_date' => $transition->effectiveDate()->toDateString(),
                'annulled_at' => $transition->annulledAt()?->toISOString(),
            ];
        }
        $candidate = [
            'from_state' => ProjectState::Planned->value,
            'to_state' => ProjectState::Open->value,
            'effective_date' => $context['today'],
            'annulled_at' => null,
        ];
        try {
            ProjectStateTimeline::validate(
                $project->initialState(),
                $project->initialEffectiveDate()->toDateString(),
                [...$rows, $candidate],
            );
        } catch (\DomainException $exception) {
            throw ValidationException::withMessages(['open_project' => $exception->getMessage()]);
        }

        return ProjectTransition::query()->create([
            'company_id' => $company->id,
            'project_id' => $project->id,
            'from_state' => ProjectState::Planned,
            'to_state' => ProjectState::Open,
            'effective_date' => $context['today'],
            'created_by_id' => $actor->id,
        ]);
    }
}
