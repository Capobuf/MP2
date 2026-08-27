<?php

namespace App\Actions\Operations;

use App\Domain\Company\AuditEventType;
use App\Models\Attachment;
use App\Models\AuditEvent;
use App\Models\Company;
use App\Models\Contract;
use App\Models\Expense;
use App\Models\ExpenseLine;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

final class DetachAttachment
{
    public function execute(User $actor, Attachment $attachment, string $operationId): Attachment
    {
        Validator::make(['operation_id' => $operationId], ['operation_id' => ['required', 'uuid']])->validate();

        return DB::transaction(function () use ($actor, $attachment, $operationId): Attachment {
            Company::query()->lockForUpdate()->findOrFail($attachment->company_id);
            $locked = Attachment::query()->lockForUpdate()->findOrFail($attachment->id);
            Gate::forUser($actor)->authorize('update', $locked);
            $existing = AuditEvent::query()->where('operation_id', $operationId)->first();
            if ($existing !== null) {
                if ($existing->eventType() !== AuditEventType::AttachmentDetached
                    || $existing->subject_type !== Attachment::class
                    || $existing->subject_id !== $locked->id) {
                    throw ValidationException::withMessages(['operation_id' => 'Identificativo operazione già utilizzato.']);
                }

                return $locked;
            }
            if ($locked->isDetached()) {
                return $locked;
            }

            $contract = $this->ownerContract($locked);
            if ($contract?->isArchived()) {
                throw ValidationException::withMessages(['attachment' => 'Ripristinare il Contratto prima di rimuovere Allegati.']);
            }

            $before = $this->snapshot($locked, $contract?->id);
            $locked->detached_at = now();
            $locked->detached_by_id = $actor->id;
            $locked->save();
            AuditEvent::query()->create([
                'operation_id' => $operationId,
                'company_id' => $locked->company_id,
                'actor_id' => $actor->id,
                'event_type' => AuditEventType::AttachmentDetached,
                'subject_type' => Attachment::class,
                'subject_id' => $locked->id,
                'affected_exercise_ids' => $this->exerciseIds($locked),
                'effective_from' => now($locked->company->timezone)->toDateString(),
                'previous_value' => $before,
                'new_value' => $this->snapshot($locked, $contract?->id),
                'allocated_impact_by_exercise' => [],
                'actual_impact_by_exercise' => [],
                'reference_type' => $this->ownerType($locked),
                'reference_id' => $locked->contract_id ?? $locked->expense_id ?? $locked->expense_line_id,
            ]);

            return $locked;
        });
    }

    /** @return list<int> */
    private function exerciseIds(Attachment $attachment): array
    {
        if ($attachment->expense_id !== null) {
            return [$attachment->expense->exercise_id];
        }
        if ($attachment->expense_line_id !== null) {
            return [$attachment->expenseLine->expense->exercise_id];
        }

        return [];
    }

    private function ownerType(Attachment $attachment): string
    {
        return match (true) {
            $attachment->contract_id !== null => Contract::class,
            $attachment->expense_id !== null => Expense::class,
            default => ExpenseLine::class,
        };
    }

    private function ownerContract(Attachment $attachment): ?Contract
    {
        if ($attachment->contract_id !== null) {
            return Contract::query()->lockForUpdate()->findOrFail($attachment->contract_id);
        }
        if ($attachment->expense_id !== null) {
            $expense = Expense::query()->lockForUpdate()->findOrFail($attachment->expense_id);

            return $expense->contract_id === null
                ? null
                : Contract::query()->lockForUpdate()->findOrFail($expense->contract_id);
        }

        $line = ExpenseLine::query()->lockForUpdate()->findOrFail($attachment->expense_line_id);
        $expense = Expense::query()->lockForUpdate()->findOrFail($line->expense_id);

        return $expense->contract_id === null
            ? null
            : Contract::query()->lockForUpdate()->findOrFail($expense->contract_id);
    }

    /** @return array<string, mixed> */
    private function snapshot(Attachment $attachment, ?int $ownerContractId): array
    {
        return [
            ...$attachment->only([
                'id', 'contract_id', 'expense_id', 'expense_line_id', 'original_name',
                'media_type', 'size_bytes', 'sha256', 'uploaded_by_id', 'detached_by_id',
            ]),
            'detached_at' => $attachment->detachedAt()?->toIso8601String(),
            'owner_contract_id' => $ownerContractId,
        ];
    }
}
