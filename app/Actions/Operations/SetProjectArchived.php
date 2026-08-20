<?php

namespace App\Actions\Operations;

use App\Domain\Company\AuditEventType;
use App\Domain\Projects\ProjectAuditSnapshot;
use App\Domain\Projects\ProjectState;
use App\Models\AuditEvent;
use App\Models\Company;
use App\Models\Project;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class SetProjectArchived
{
    public function execute(
        User $actor,
        Project $project,
        bool $archived,
        string $operationId,
        ?int $expectedRevision = null,
    ): Project {
        Validator::make([
            'operation_id' => $operationId,
            'expected_revision' => $expectedRevision,
        ], [
            'operation_id' => ['required', 'uuid'],
            'expected_revision' => ['nullable', 'integer', 'min:0'],
        ])->validate();

        return DB::transaction(function () use ($actor, $project, $archived, $operationId, $expectedRevision): Project {
            $company = Company::query()->lockForUpdate()->findOrFail($project->company_id);
            $lockedProject = Project::query()->lockForUpdate()->findOrFail($project->id);
            $transitions = $lockedProject->transitions()
                ->orderBy('effective_date')
                ->orderBy('id')
                ->lockForUpdate()
                ->get();
            $lockedProject->classifications()->orderBy('exercise_id')->lockForUpdate()->get();
            $lockedProject->setRelation('transitions', $transitions);
            Gate::forUser($actor)->authorize('update', $lockedProject);

            $eventType = $archived ? AuditEventType::ProjectArchived : AuditEventType::ProjectRestored;
            $existing = AuditEvent::query()->where('operation_id', $operationId)->first();
            if ($existing !== null) {
                if ($existing->eventType() !== $eventType
                    || $existing->subject_type !== Project::class
                    || $existing->subject_id !== $lockedProject->id) {
                    throw ValidationException::withMessages([
                        'operation_id' => 'Identificativo operazione già utilizzato.',
                    ]);
                }

                return $lockedProject;
            }

            if ($expectedRevision !== null && $lockedProject->revision !== $expectedRevision) {
                throw ValidationException::withMessages([
                    'project' => 'Il Progetto è cambiato: ricaricare la pagina prima di continuare.',
                ]);
            }
            if ($lockedProject->isArchived() === $archived) {
                return $lockedProject;
            }

            $today = now($company->timezone)->toDateString();
            if ($archived && ! in_array($lockedProject->stateAtDate($today), [ProjectState::Closed, ProjectState::Cancelled], true)) {
                throw ValidationException::withMessages([
                    'project' => 'Solo un Progetto Chiuso o Cancellato può essere archiviato.',
                ]);
            }

            $previous = ProjectAuditSnapshot::project($lockedProject);
            $lockedProject->forceFill([
                'archived_at' => $archived ? now() : null,
                'revision' => $lockedProject->revision + 1,
            ])->save();

            AuditEvent::query()->create([
                'operation_id' => $operationId,
                'company_id' => $company->id,
                'actor_id' => $actor->id,
                'event_type' => $eventType,
                'subject_type' => Project::class,
                'subject_id' => $lockedProject->id,
                'affected_exercise_ids' => [],
                'effective_from' => $today,
                'previous_value' => $previous,
                'new_value' => ProjectAuditSnapshot::project($lockedProject),
                'allocated_impact_by_exercise' => [],
                'actual_impact_by_exercise' => [],
            ]);

            return $lockedProject;
        });
    }
}
