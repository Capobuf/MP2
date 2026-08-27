<?php

namespace App\Actions\Operations;

use App\Domain\Company\AuditEventType;
use App\Domain\Contracts\ContractState;
use App\Models\AuditEvent;
use App\Models\Company;
use App\Models\Contract;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

final class SetContractArchived
{
    public function execute(User $actor, Contract $contract, bool $archived, string $operationId, int $expectedRevision): Contract
    {
        Validator::make([
            'operation_id' => $operationId,
            'expected_revision' => $expectedRevision,
        ], [
            'operation_id' => ['required', 'uuid'],
            'expected_revision' => ['required', 'integer', 'min:0'],
        ])->validate();

        return DB::transaction(function () use ($actor, $contract, $archived, $operationId, $expectedRevision): Contract {
            Company::query()->lockForUpdate()->findOrFail($contract->company_id);
            $locked = Contract::query()->lockForUpdate()->findOrFail($contract->id);
            $locked->setRelation('lifecycleFacts', $locked->lifecycleFacts()->orderBy('id')->lockForUpdate()->get());
            $locked->setRelation('renewalConfigurations', $locked->renewalConfigurations()->orderBy('id')->lockForUpdate()->get());
            $locked->conditions()->orderBy('id')->lockForUpdate()->get();
            $locked->classifications()->orderBy('id')->lockForUpdate()->get();
            $locked->expenses()->orderBy('id')->lockForUpdate()->get();
            $locked->projectLinks()->orderBy('id')->lockForUpdate()->get();
            $locked->attachments()->orderBy('id')->lockForUpdate()->get();
            Gate::forUser($actor)->authorize('update', $locked);
            $eventType = $archived ? AuditEventType::ContractArchived : AuditEventType::ContractRestored;
            $existing = AuditEvent::query()->where('operation_id', $operationId)->first();
            if ($existing !== null) {
                if ($existing->eventType() !== $eventType || $existing->subject_type !== Contract::class || $existing->subject_id !== $locked->id) {
                    throw ValidationException::withMessages(['operation_id' => 'Identificativo operazione già utilizzato.']);
                }

                return $locked;
            }
            if ($locked->isArchived() === $archived) {
                return $locked;
            }
            if ($locked->revision !== $expectedRevision) {
                throw ValidationException::withMessages(['revision' => 'Il Contratto è cambiato: ricaricare i dati.']);
            }
            $today = now($locked->company->timezone)->toDateString();
            if ($archived && ! in_array($locked->stateAtDate($today), [ContractState::Cessated, ContractState::Cancelled], true)) {
                throw ValidationException::withMessages(['contract' => 'È possibile archiviare soltanto un Contratto Cessato o Annullato.']);
            }

            $exerciseIds = $locked->classifications()->pluck('exercise_id')
                ->merge($locked->expenses()->pluck('exercise_id'))->unique()->sort()->values()->all();
            $before = $this->snapshot($locked);
            $locked->archived_at = $archived ? now() : null;
            $locked->revision++;
            $locked->save();
            $zeroImpact = array_fill_keys(array_map('strval', $exerciseIds), '0.00');
            AuditEvent::query()->create([
                'operation_id' => $operationId,
                'company_id' => $locked->company_id,
                'actor_id' => $actor->id,
                'event_type' => $eventType,
                'subject_type' => Contract::class,
                'subject_id' => $locked->id,
                'affected_exercise_ids' => $exerciseIds,
                'effective_from' => $today,
                'previous_value' => $before,
                'new_value' => $this->snapshot($locked),
                'allocated_impact_by_exercise' => $zeroImpact,
                'actual_impact_by_exercise' => $zeroImpact,
            ]);

            return $locked;
        });
    }

    /** @return array<string, mixed> */
    private function snapshot(Contract $contract): array
    {
        return [
            'origin_key' => $contract->originKey(),
            'title' => $contract->title,
            'archived_at' => $contract->archivedAt()?->toIso8601String(),
            'revision' => $contract->revision,
        ];
    }
}
