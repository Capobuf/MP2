<?php

namespace App\Actions\Operations;

use App\Domain\Company\AuditEventType;
use App\Models\AuditEvent;
use App\Models\Contract;
use App\Models\ProjectContractLink;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

final class SetProjectContractLinkArchived
{
    public function execute(User $actor, ProjectContractLink $link, bool $archived, string $operationId, int $expectedRevision): ProjectContractLink
    {
        Validator::make([
            'operation_id' => $operationId,
            'expected_revision' => $expectedRevision,
        ], [
            'operation_id' => ['required', 'uuid'],
            'expected_revision' => ['required', 'integer', 'min:0'],
        ])->validate();

        return DB::transaction(function () use ($actor, $link, $archived, $operationId, $expectedRevision): ProjectContractLink {
            $unlocked = ProjectContractLink::query()->findOrFail($link->id);
            $contract = Contract::query()->lockForUpdate()->findOrFail($unlocked->contract_id);
            $locked = ProjectContractLink::query()->lockForUpdate()->findOrFail($link->id);
            Gate::forUser($actor)->authorize('update', $locked);
            $eventType = $archived ? AuditEventType::ProjectContractLinkArchived : AuditEventType::ProjectContractLinkRestored;
            $existing = AuditEvent::query()->where('operation_id', $operationId)->first();
            if ($existing !== null) {
                if ($existing->eventType() !== $eventType || $existing->subject_type !== ProjectContractLink::class || $existing->subject_id !== $locked->id) {
                    throw ValidationException::withMessages(['operation_id' => 'Identificativo operazione già utilizzato.']);
                }

                return $locked;
            }
            if ($contract->isArchived()) {
                throw ValidationException::withMessages(['link' => 'Ripristinare il Contratto prima di modificare i collegamenti.']);
            }
            if ($locked->revision !== $expectedRevision) {
                throw ValidationException::withMessages(['revision' => 'Il collegamento è cambiato: ricaricare i dati.']);
            }
            if ($locked->isArchived() === $archived) {
                return $locked;
            }
            if (! $archived && ProjectContractLink::query()->active()->where('project_id', $locked->project_id)->where('contract_id', $locked->contract_id)->whereKeyNot($locked->id)->exists()) {
                throw ValidationException::withMessages(['link' => 'Esiste già un collegamento attivo tra Progetto e Contratto.']);
            }

            $before = $this->snapshot($locked);
            $locked->archived_at = $archived ? now() : null;
            $locked->revision++;
            $locked->save();
            AuditEvent::query()->create([
                'operation_id' => $operationId,
                'company_id' => $locked->company_id,
                'actor_id' => $actor->id,
                'event_type' => $eventType,
                'subject_type' => ProjectContractLink::class,
                'subject_id' => $locked->id,
                'affected_exercise_ids' => [],
                'effective_from' => now($locked->company->timezone)->toDateString(),
                'previous_value' => $before,
                'new_value' => $this->snapshot($locked),
                'allocated_impact_by_exercise' => [],
                'actual_impact_by_exercise' => [],
                'reference_type' => Contract::class,
                'reference_id' => $locked->contract_id,
            ]);

            return $locked;
        });
    }

    /** @return array<string, mixed> */
    private function snapshot(ProjectContractLink $link): array
    {
        return [
            'id' => $link->id,
            'project_id' => $link->project_id,
            'contract_id' => $link->contract_id,
            'note' => $link->note,
            'archived_at' => $link->archivedAt()?->toIso8601String(),
            'revision' => $link->revision,
        ];
    }
}
