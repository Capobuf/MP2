<?php

namespace App\Actions\Operations;

use App\Domain\Company\AuditEventType;
use App\Domain\Contracts\ContractEconomicUse;
use App\Models\AuditEvent;
use App\Models\Company;
use App\Models\Contract;
use App\Models\Supplier;
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
            'supplier_id' => $input['supplier_id'] ?? $contract->supplier_id,
            'operation_id' => $operationId,
        ], [
            'title' => ['required', 'string', 'max:255'],
            'notes' => ['nullable', 'string'],
            'supplier_id' => ['required', 'integer'],
            'operation_id' => ['required', 'uuid'],
        ])->validate();

        return DB::transaction(function () use ($actor, $contract, $validated): Contract {
            $company = Company::query()->lockForUpdate()->findOrFail($contract->company_id);
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

            $supplier = Supplier::query()
                ->whereBelongsTo($company, 'company')
                ->lockForUpdate()
                ->find($validated['supplier_id']);
            if (! $supplier instanceof Supplier) {
                throw ValidationException::withMessages(['supplier_id' => 'Il Fornitore deve appartenere alla stessa Azienda.']);
            }
            $supplierChanged = $supplier->id !== $locked->supplier_id;
            if ($supplierChanged && $supplier->isArchived()) {
                throw ValidationException::withMessages(['supplier_id' => 'Non è possibile selezionare un Fornitore archiviato.']);
            }
            if ($supplierChanged && ContractEconomicUse::exists($locked)) {
                throw ValidationException::withMessages(['supplier_id' => 'Il Contratto è già stato usato economicamente: per un altro Fornitore serve un nuovo Contratto.']);
            }

            $before = ['title' => $locked->title, 'notes' => $locked->notes, 'supplier_id' => $locked->supplier_id];
            $locked->fill([
                'title' => $validated['title'],
                'notes' => $validated['notes'],
                'supplier_id' => $supplier->id,
            ]);
            if (! $locked->isDirty()) {
                return $locked;
            }
            $locked->update([
                'title' => $validated['title'],
                'notes' => $validated['notes'],
                'supplier_id' => $supplier->id,
                'revision' => $locked->revision + 1,
            ]);
            if ($supplierChanged) {
                $locked->expenses()->update(['supplier_id' => $supplier->id]);
            }
            AuditEvent::query()->create([
                'operation_id' => $validated['operation_id'],
                'event_sequence' => 0,
                'company_id' => $company->id,
                'actor_id' => $actor->id,
                'event_type' => AuditEventType::ContractUpdated,
                'subject_type' => Contract::class,
                'subject_id' => $locked->id,
                'affected_exercise_ids' => [],
                'effective_from' => now($locked->company->timezone)->toDateString(),
                'previous_value' => $before,
                'new_value' => ['title' => $locked->title, 'notes' => $locked->notes, 'supplier_id' => $locked->supplier_id],
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
