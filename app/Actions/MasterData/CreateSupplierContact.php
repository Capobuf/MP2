<?php

namespace App\Actions\MasterData;

use App\Domain\Company\AuditEventType;
use App\Models\AuditEvent;
use App\Models\Supplier;
use App\Models\SupplierContact;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class CreateSupplierContact
{
    /** @param array<string, mixed> $input */
    public function execute(User $actor, Supplier $supplier, array $input, string $operationId): SupplierContact
    {
        $normalized = $this->normalize($input);
        /** @var array{first_name: ?string, last_name: ?string, phone: ?string, email: ?string, notes: ?string, role_tags: list<string>, operation_id: string} $validated */
        $validated = Validator::make([...$normalized, 'operation_id' => $operationId], [
            'first_name' => ['nullable', 'string', 'max:255'],
            'last_name' => ['nullable', 'string', 'max:255'],
            'phone' => ['nullable', 'string', 'max:64'],
            'email' => ['nullable', 'email:rfc', 'max:255'],
            'notes' => ['nullable', 'string'],
            'role_tags' => ['present', 'array'],
            'role_tags.*' => ['string'],
            'operation_id' => ['required', 'uuid'],
        ])->validate();

        return DB::transaction(function () use ($actor, $supplier, $validated): SupplierContact {
            $lockedSupplier = Supplier::query()->with('company')->lockForUpdate()->findOrFail($supplier->id);
            Gate::forUser($actor)->authorize('create', [SupplierContact::class, $lockedSupplier]);

            $existing = AuditEvent::query()->where('operation_id', $validated['operation_id'])->first();

            if ($existing !== null) {
                if (
                    $existing->eventType() !== AuditEventType::SupplierContactCreated
                    || $existing->subject_type !== SupplierContact::class
                    || $existing->company_id !== $lockedSupplier->company_id
                ) {
                    throw ValidationException::withMessages([
                        'operation_id' => 'Identificativo operazione già utilizzato.',
                    ]);
                }

                return SupplierContact::query()
                    ->whereBelongsTo($lockedSupplier, 'supplier')
                    ->findOrFail($existing->subject_id);
            }

            $contact = $lockedSupplier->contacts()->create([
                'first_name' => $validated['first_name'],
                'last_name' => $validated['last_name'],
                'phone' => $validated['phone'],
                'email' => $validated['email'],
                'notes' => $validated['notes'],
                'role_tags' => $validated['role_tags'],
            ]);

            AuditEvent::query()->create([
                'operation_id' => $validated['operation_id'],
                'company_id' => $lockedSupplier->company_id,
                'actor_id' => $actor->id,
                'event_type' => AuditEventType::SupplierContactCreated,
                'subject_type' => SupplierContact::class,
                'subject_id' => $contact->id,
                'affected_exercise_ids' => [],
                'effective_from' => now($lockedSupplier->company->timezone)->toDateString(),
                'previous_value' => null,
                'new_value' => $this->snapshot($contact),
                'allocated_impact_by_exercise' => [],
                'actual_impact_by_exercise' => [],
            ]);

            return $contact;
        });
    }

    /** @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    private function normalize(array $input): array
    {
        $normalized = [];

        foreach (['first_name', 'last_name', 'phone', 'email', 'notes'] as $field) {
            $normalized[$field] = $this->nullableTrimmed($input[$field] ?? null);
        }

        $normalized['role_tags'] = $input['role_tags'] ?? [];

        if (is_array($normalized['role_tags'])) {
            $normalized['role_tags'] = array_map(
                fn (mixed $tag): mixed => is_string($tag) ? trim($tag) : $tag,
                array_values($normalized['role_tags']),
            );
        }

        return $normalized;
    }

    private function nullableTrimmed(mixed $value): mixed
    {
        if (! is_string($value)) {
            return $value;
        }

        $value = trim($value);

        return $value === '' ? null : $value;
    }

    /** @return array{first_name: ?string, last_name: ?string, phone: ?string, email: ?string, notes: ?string, role_tags: mixed} */
    private function snapshot(SupplierContact $contact): array
    {
        return [
            'first_name' => $contact->first_name,
            'last_name' => $contact->last_name,
            'phone' => $contact->phone,
            'email' => $contact->email,
            'notes' => $contact->notes,
            'role_tags' => $contact->role_tags,
        ];
    }
}
