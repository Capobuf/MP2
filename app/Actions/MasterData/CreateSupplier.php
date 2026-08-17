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

class CreateSupplier
{
    /** @param array{legal_name?: mixed, vat_number?: mixed, notes?: mixed} $input */
    public function execute(User $actor, Company $company, array $input, string $operationId): Supplier
    {
        $normalized = $this->normalize($input);
        /** @var array{legal_name: string, vat_number: ?string, notes: ?string, operation_id: string} $validated */
        $validated = Validator::make([...$normalized, 'operation_id' => $operationId], [
            'legal_name' => ['required', 'string', 'max:255'],
            'vat_number' => ['nullable', 'string', 'max:64'],
            'notes' => ['nullable', 'string'],
            'operation_id' => ['required', 'uuid'],
        ])->validate();

        return DB::transaction(function () use ($actor, $company, $validated): Supplier {
            $lockedCompany = Company::query()->lockForUpdate()->findOrFail($company->id);
            Gate::forUser($actor)->authorize('create', [Supplier::class, $lockedCompany]);

            $existing = AuditEvent::query()->where('operation_id', $validated['operation_id'])->first();

            if ($existing !== null) {
                if (
                    $existing->eventType() !== AuditEventType::SupplierCreated
                    || $existing->subject_type !== Supplier::class
                    || $existing->company_id !== $lockedCompany->id
                ) {
                    throw ValidationException::withMessages([
                        'operation_id' => 'Identificativo operazione già utilizzato.',
                    ]);
                }

                return Supplier::query()->findOrFail($existing->subject_id);
            }

            $supplier = Supplier::query()->create([
                'company_id' => $lockedCompany->id,
                'legal_name' => $validated['legal_name'],
                'vat_number' => $validated['vat_number'],
                'notes' => $validated['notes'],
            ]);

            AuditEvent::query()->create([
                'operation_id' => $validated['operation_id'],
                'company_id' => $lockedCompany->id,
                'actor_id' => $actor->id,
                'event_type' => AuditEventType::SupplierCreated,
                'subject_type' => Supplier::class,
                'subject_id' => $supplier->id,
                'affected_exercise_ids' => [],
                'effective_from' => now($lockedCompany->timezone)->toDateString(),
                'previous_value' => null,
                'new_value' => $this->snapshot($supplier),
                'allocated_impact_by_exercise' => [],
                'actual_impact_by_exercise' => [],
            ]);

            return $supplier;
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
