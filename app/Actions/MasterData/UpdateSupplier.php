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

class UpdateSupplier
{
    /** @param array{legal_name?: mixed, vat_number?: mixed, notes?: mixed} $input */
    public function execute(User $actor, Supplier $supplier, array $input, string $operationId): Supplier
    {
        $normalized = $this->normalize($input);
        /** @var array{legal_name: string, vat_number: ?string, notes: ?string, operation_id: string} $validated */
        $validated = Validator::make([...$normalized, 'operation_id' => $operationId], [
            'legal_name' => ['required', 'string', 'max:255'],
            'vat_number' => ['nullable', 'string', 'max:64'],
            'notes' => ['nullable', 'string'],
            'operation_id' => ['required', 'uuid'],
        ])->validate();

        return DB::transaction(function () use ($actor, $supplier, $validated): Supplier {
            Company::query()->lockForUpdate()->findOrFail($supplier->company_id);
            $lockedSupplier = Supplier::query()->with('company')->lockForUpdate()->findOrFail($supplier->id);
            Gate::forUser($actor)->authorize('update', $lockedSupplier);

            $existing = AuditEvent::query()->where('operation_id', $validated['operation_id'])->first();

            if ($existing !== null) {
                if (
                    $existing->eventType() !== AuditEventType::SupplierUpdated
                    || $existing->subject_type !== Supplier::class
                    || $existing->subject_id !== $lockedSupplier->id
                ) {
                    throw ValidationException::withMessages([
                        'operation_id' => 'Identificativo operazione già utilizzato.',
                    ]);
                }

                return $lockedSupplier;
            }

            $previous = $this->snapshot($lockedSupplier);
            $requested = [
                'legal_name' => $validated['legal_name'],
                'vat_number' => $validated['vat_number'],
                'notes' => $validated['notes'],
            ];

            if (
                $previous['legal_name'] === $requested['legal_name']
                && $previous['vat_number'] === $requested['vat_number']
                && $previous['notes'] === $requested['notes']
            ) {
                return $lockedSupplier;
            }

            $lockedSupplier->forceFill($requested)->save();

            AuditEvent::query()->create([
                'operation_id' => $validated['operation_id'],
                'company_id' => $lockedSupplier->company_id,
                'actor_id' => $actor->id,
                'event_type' => AuditEventType::SupplierUpdated,
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

    /** @param array{legal_name?: mixed, vat_number?: mixed, notes?: mixed} $input
     * @return array{legal_name: mixed, vat_number: mixed, notes: mixed}
     */
    private function normalize(array $input): array
    {
        return [
            'legal_name' => is_string($input['legal_name'] ?? null) ? trim($input['legal_name']) : ($input['legal_name'] ?? null),
            'vat_number' => $this->nullableTrimmed($input['vat_number'] ?? null),
            'notes' => $this->nullableTrimmed($input['notes'] ?? null),
        ];
    }

    private function nullableTrimmed(mixed $value): mixed
    {
        if (! is_string($value)) {
            return $value;
        }

        $value = trim($value);

        return $value === '' ? null : $value;
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
