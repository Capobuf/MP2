<?php

namespace App\Actions\Operations;

use App\Domain\Company\AuditEventType;
use App\Models\AuditEvent;
use App\Models\Contract;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class UpdateContract
{
    /** @param array<string, mixed> $input */
    public function execute(User $actor, Contract $contract, array $input, string $operationId): Contract
    {
        $validated = Validator::make([
            'title' => is_string($input['title'] ?? null) ? trim($input['title']) : ($input['title'] ?? null),
            'notes' => $this->nullableTrim($input['notes'] ?? null),
            'operation_id' => $operationId,
        ], [
            'title' => ['required', 'string', 'max:255'],
            'notes' => ['nullable', 'string'],
            'operation_id' => ['required', 'uuid'],
        ])->validate();

        return DB::transaction(function () use ($actor, $contract, $validated): Contract {
            $locked = Contract::query()->lockForUpdate()->findOrFail($contract->id);
            Gate::forUser($actor)->authorize('update', $locked);
            $existing = AuditEvent::query()->where('operation_id', $validated['operation_id'])->where('event_sequence', 0)->first();

            if ($existing !== null) {
                if ($existing->eventType() !== AuditEventType::ContractUpdated
                    || $existing->subject_type !== Contract::class
                    || $existing->subject_id !== $locked->id) {
                    throw ValidationException::withMessages(['operation_id' => 'Identificativo operazione già utilizzato.']);
                }

                return $locked;
            }

            $before = ['title' => $locked->title, 'notes' => $locked->notes];
            $locked->update([
                'title' => $validated['title'],
                'notes' => $validated['notes'],
                'revision' => $locked->revision + 1,
            ]);
            AuditEvent::query()->create([
                'operation_id' => $validated['operation_id'],
                'event_sequence' => 0,
                'company_id' => $locked->company_id,
                'actor_id' => $actor->id,
                'event_type' => AuditEventType::ContractUpdated,
                'subject_type' => Contract::class,
                'subject_id' => $locked->id,
                'affected_exercise_ids' => [],
                'effective_from' => now($locked->company->timezone)->toDateString(),
                'previous_value' => $before,
                'new_value' => ['title' => $locked->title, 'notes' => $locked->notes],
                'allocated_impact_by_exercise' => [],
                'actual_impact_by_exercise' => [],
            ]);

            return $locked;
        });
    }

    private function nullableTrim(mixed $value): mixed
    {
        $value = is_string($value) ? trim($value) : $value;

        return $value === '' ? null : $value;
    }
}
