<?php

namespace App\Actions\Operations;

use App\Domain\Company\AuditEventType;
use App\Domain\Expenses\ExpenseAuditSnapshot;
use App\Domain\Projects\ProjectAuditSnapshot;
use App\Models\AuditEvent;
use App\Models\Company;
use App\Models\Project;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class UpdateProject
{
    /** @param array<string, mixed> $input */
    public function execute(User $actor, Project $project, array $input, string $operationId): Project
    {
        $normalized = [
            'title' => is_string($input['title'] ?? null) ? trim($input['title']) : ($input['title'] ?? null),
            'description' => $this->nullableTrim($input['description'] ?? null),
            'notes' => $this->nullableTrim($input['notes'] ?? null),
            'operation_id' => $operationId,
        ];
        /** @var array{title: string, description: ?string, notes: ?string, operation_id: string} $validated */
        $validated = Validator::make($normalized, [
            'title' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'notes' => ['nullable', 'string'],
            'operation_id' => ['required', 'uuid'],
        ])->validate();

        return DB::transaction(function () use ($actor, $project, $validated): Project {
            $company = Company::query()->lockForUpdate()->findOrFail($project->company_id);
            $lockedProject = Project::query()->lockForUpdate()->findOrFail($project->id);
            Gate::forUser($actor)->authorize('update', $lockedProject);

            $existing = AuditEvent::query()->where('operation_id', $validated['operation_id'])->first();
            if ($existing !== null) {
                if ($existing->eventType() !== AuditEventType::ProjectUpdated
                    || $existing->subject_type !== Project::class
                    || $existing->subject_id !== $lockedProject->id) {
                    throw ValidationException::withMessages(['operation_id' => 'Identificativo operazione già utilizzato.']);
                }

                return $lockedProject;
            }

            $before = ProjectAuditSnapshot::project($lockedProject);
            $lockedProject->fill([
                'title' => $validated['title'],
                'description' => $validated['description'],
                'notes' => $validated['notes'],
            ]);
            if (! $lockedProject->isDirty()) {
                return $lockedProject;
            }

            $lockedProject->revision++;
            $lockedProject->save();
            $exerciseIds = $lockedProject->classifications()->orderBy('exercise_id')->pluck('exercise_id')->all();
            $zeroImpact = [];
            foreach ($exerciseIds as $exerciseId) {
                $zeroImpact += ExpenseAuditSnapshot::impact((int) $exerciseId, '0');
            }

            AuditEvent::query()->create([
                'operation_id' => $validated['operation_id'],
                'company_id' => $company->id,
                'actor_id' => $actor->id,
                'event_type' => AuditEventType::ProjectUpdated,
                'subject_type' => Project::class,
                'subject_id' => $lockedProject->id,
                'affected_exercise_ids' => array_map('intval', $exerciseIds),
                'effective_from' => now($company->timezone)->toDateString(),
                'previous_value' => $before,
                'new_value' => ProjectAuditSnapshot::project($lockedProject),
                'allocated_impact_by_exercise' => $zeroImpact,
                'actual_impact_by_exercise' => $zeroImpact,
            ]);

            return $lockedProject;
        });
    }

    private function nullableTrim(mixed $value): mixed
    {
        if (! is_string($value)) {
            return $value;
        }
        $value = trim($value);

        return $value === '' ? null : $value;
    }
}
