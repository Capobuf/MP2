<?php

namespace App\Actions\MasterData;

use App\Domain\Company\AuditEventType;
use App\Models\AuditEvent;
use App\Models\Company;
use App\Models\Supplier;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class SetSupplierArchived
{
    public function execute(User $actor, Supplier $supplier, bool $archived, string $operationId): Supplier
    {
        /** @var array{operation_id: string} $validated */
        $validated = Validator::make(['operation_id' => $operationId], [
            'operation_id' => ['required', 'uuid'],
        ])->validate();

        return DB::transaction(function () use ($actor, $supplier, $archived, $validated): Supplier {
            Company::query()->lockForUpdate()->findOrFail($supplier->company_id);
            $lockedSupplier = Supplier::query()->with('company')->lockForUpdate()->findOrFail($supplier->id);
            Gate::forUser($actor)->authorize('update', $lockedSupplier);
            $eventType = $archived ? AuditEventType::SupplierArchived : AuditEventType::SupplierRestored;
            $existing = AuditEvent::query()->where('operation_id', $validated['operation_id'])->first();

            if ($existing !== null) {
                if (
                    $existing->eventType() !== $eventType
                    || $existing->subject_type !== Supplier::class
                    || $existing->subject_id !== $lockedSupplier->id
                ) {
                    throw ValidationException::withMessages([
                        'operation_id' => 'Identificativo operazione già utilizzato.',
                    ]);
                }

                return $lockedSupplier;
            }

            if ($lockedSupplier->isArchived() === $archived) {
                return $lockedSupplier;
            }

            $previous = $this->snapshot($lockedSupplier);
            $lockedSupplier->forceFill(['archived_at' => $archived ? now() : null])->save();

            AuditEvent::query()->create([
                'operation_id' => $validated['operation_id'],
                'company_id' => $lockedSupplier->company_id,
                'actor_id' => $actor->id,
                'event_type' => $eventType,
                'subject_type' => Supplier::class,
                'subject_id' => $lockedSupplier->id,
                'affected_exercise_ids' => [],
                'effective_from' => now($lockedSupplier->company->timezone)->toDateString(),
                'previous_value' => $previous,
                'new_value' => $this->snapshot($lockedSupplier),
                'allocated_impact_by_exercise' => [],
                'actual_impact_by_exercise' => [],
            ]);

            return $lockedSupplier;
        });
    }

    /** @return array{legal_name: string, vat_number: ?string, notes: ?string, archived: bool} */
    private function snapshot(Supplier $supplier): array
    {
        return [
            'legal_name' => $supplier->legal_name,
            'vat_number' => $supplier->vat_number,
            'notes' => $supplier->notes,
            'archived' => $supplier->isArchived(),
        ];
    }
}
