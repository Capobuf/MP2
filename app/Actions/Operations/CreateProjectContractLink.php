<?php

namespace App\Actions\Operations;

use App\Domain\Company\AuditEventType;
use App\Models\AuditEvent;
use App\Models\Company;
use App\Models\Contract;
use App\Models\Project;
use App\Models\ProjectContractLink;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

final class CreateProjectContractLink
{
    public function execute(User $actor, Project $project, Contract $contract, ?string $note, string $operationId): ProjectContractLink
    {
        /** @var array{note: ?string, operation_id: string} $data */
        $data = Validator::make([
            'note' => is_string($note) && trim($note) !== '' ? trim($note) : null,
            'operation_id' => $operationId,
        ], [
            'note' => ['nullable', 'string'],
            'operation_id' => ['required', 'uuid'],
        ])->validate();

        return DB::transaction(function () use ($actor, $project, $contract, $data): ProjectContractLink {
            $company = Company::query()->lockForUpdate()->findOrFail($project->company_id);
            $lockedProject = Project::query()->lockForUpdate()->findOrFail($project->id);
            $lockedContract = Contract::query()->lockForUpdate()->findOrFail($contract->id);
            Gate::forUser($actor)->authorize('create', [ProjectContractLink::class, $company]);

            $existingEvent = AuditEvent::query()->where('operation_id', $data['operation_id'])->first();
            if ($existingEvent !== null) {
                if ($existingEvent->eventType() !== AuditEventType::ProjectContractLinked
                    || $existingEvent->subject_type !== ProjectContractLink::class) {
                    throw ValidationException::withMessages(['operation_id' => 'Identificativo operazione già utilizzato.']);
                }

                return ProjectContractLink::query()->findOrFail($existingEvent->subject_id);
            }
            if ($lockedProject->company_id !== $company->id || $lockedContract->company_id !== $company->id) {
                throw ValidationException::withMessages(['contract_id' => 'Progetto e Contratto devono appartenere alla stessa Azienda.']);
            }
            if ($lockedProject->isArchived() || $lockedContract->isArchived()) {
                throw ValidationException::withMessages(['link' => 'Ripristinare Progetto e Contratto prima di collegarli.']);
            }
            if (ProjectContractLink::query()->active()->where('project_id', $lockedProject->id)->where('contract_id', $lockedContract->id)->lockForUpdate()->exists()) {
                throw ValidationException::withMessages(['link' => 'Esiste già un collegamento attivo tra Progetto e Contratto.']);
            }

            $link = ProjectContractLink::query()->create([
                'company_id' => $company->id,
                'project_id' => $lockedProject->id,
                'contract_id' => $lockedContract->id,
                'note' => $data['note'],
                'revision' => 0,
            ]);
            AuditEvent::query()->create([
                'operation_id' => $data['operation_id'],
                'company_id' => $company->id,
                'actor_id' => $actor->id,
                'event_type' => AuditEventType::ProjectContractLinked,
                'subject_type' => ProjectContractLink::class,
                'subject_id' => $link->id,
                'affected_exercise_ids' => [],
                'effective_from' => now($company->timezone)->toDateString(),
                'previous_value' => null,
                'new_value' => $this->snapshot($link),
                'allocated_impact_by_exercise' => [],
                'actual_impact_by_exercise' => [],
                'reference_type' => Contract::class,
                'reference_id' => $lockedContract->id,
            ]);

            return $link;
        });
    }

    /** @return array<string, mixed> */
    private function snapshot(ProjectContractLink $link): array
    {
        return $link->only(['id', 'project_id', 'contract_id', 'note', 'archived_at', 'revision']);
    }
}
