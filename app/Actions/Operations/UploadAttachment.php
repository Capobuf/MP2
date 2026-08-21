<?php

namespace App\Actions\Operations;

use App\Domain\Company\AuditEventType;
use App\Models\Attachment;
use App\Models\AuditEvent;
use App\Models\Company;
use App\Models\Contract;
use App\Models\Expense;
use App\Models\ExpenseLine;
use App\Models\Proposal;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

final class UploadAttachment
{
    public function execute(User $actor, Contract|Expense|ExpenseLine|Proposal $owner, UploadedFile $file, string $operationId): Attachment
    {
        Validator::make([
            'file' => $file,
            'operation_id' => $operationId,
        ], [
            'file' => ['required', 'file'],
            'operation_id' => ['required', 'uuid'],
        ])->validate();

        $storedPath = null;
        try {
            return DB::transaction(function () use ($actor, $owner, $file, $operationId, &$storedPath): Attachment {
                [$lockedOwner, $company, $contract] = $this->lockOwner($owner);
                if ($lockedOwner instanceof Proposal) {
                    Gate::forUser($actor)->authorize('update', $lockedOwner);
                } else {
                    Gate::forUser($actor)->authorize('create', [Attachment::class, $company]);
                }
                if ($contract?->isArchived()) {
                    throw ValidationException::withMessages(['attachment' => 'Ripristinare il Contratto prima di aggiungere Allegati.']);
                }

                $existing = AuditEvent::query()->where('operation_id', $operationId)->first();
                if ($existing !== null) {
                    if ($existing->eventType() !== AuditEventType::AttachmentUploaded
                        || $existing->subject_type !== Attachment::class) {
                        throw ValidationException::withMessages(['operation_id' => 'Identificativo operazione già utilizzato.']);
                    }

                    return Attachment::query()->findOrFail($existing->subject_id);
                }

                $extension = $file->guessExtension();
                $filename = (string) Str::uuid().($extension === null ? '' : '.'.$extension);
                $stored = Storage::disk('local')->putFileAs('attachments/'.$company->id, $file, $filename);
                if (! is_string($stored)) {
                    throw ValidationException::withMessages(['file' => 'Impossibile salvare l’Allegato.']);
                }
                $storedPath = $stored;
                $realPath = $file->getRealPath();
                if (! is_string($realPath)) {
                    throw ValidationException::withMessages(['file' => 'File caricato non leggibile.']);
                }

                $attachment = Attachment::query()->create([
                    'company_id' => $company->id,
                    ...$this->ownerColumns($lockedOwner),
                    'storage_disk' => 'local',
                    'storage_path' => $storedPath,
                    'original_name' => $file->getClientOriginalName(),
                    'media_type' => $file->getMimeType(),
                    'size_bytes' => $file->getSize(),
                    'sha256' => hash_file('sha256', $realPath),
                    'uploaded_by_id' => $actor->id,
                ]);
                AuditEvent::query()->create([
                    'operation_id' => $operationId,
                    'company_id' => $company->id,
                    'actor_id' => $actor->id,
                    'event_type' => AuditEventType::AttachmentUploaded,
                    'subject_type' => Attachment::class,
                    'subject_id' => $attachment->id,
                    'affected_exercise_ids' => $this->exerciseIds($lockedOwner),
                    'effective_from' => now($company->timezone)->toDateString(),
                    'previous_value' => null,
                    'new_value' => $this->snapshot($attachment, $contract?->id),
                    'allocated_impact_by_exercise' => [],
                    'actual_impact_by_exercise' => [],
                    'reference_type' => $lockedOwner::class,
                    'reference_id' => $lockedOwner->getKey(),
                ]);

                return $attachment;
            });
        } catch (\Throwable $throwable) {
            if ($storedPath !== null && ! Attachment::query()->where('storage_path', $storedPath)->exists()) {
                Storage::disk('local')->delete($storedPath);
            }

            throw $throwable;
        }
    }

    /** @return array{Model, Company, Contract|null} */
    private function lockOwner(Contract|Expense|ExpenseLine|Proposal $owner): array
    {
        if ($owner instanceof Proposal) {
            $locked = Proposal::query()->lockForUpdate()->findOrFail($owner->id);

            return [$locked, Company::query()->lockForUpdate()->findOrFail($locked->company_id), null];
        }
        if ($owner instanceof Contract) {
            $locked = Contract::query()->lockForUpdate()->findOrFail($owner->id);

            return [$locked, Company::query()->lockForUpdate()->findOrFail($locked->company_id), $locked];
        }
        if ($owner instanceof Expense) {
            $locked = Expense::query()->lockForUpdate()->findOrFail($owner->id);
            $contract = $locked->contract_id === null ? null : Contract::query()->lockForUpdate()->findOrFail($locked->contract_id);

            return [$locked, Company::query()->lockForUpdate()->findOrFail($locked->company_id), $contract];
        }

        $locked = ExpenseLine::query()->lockForUpdate()->findOrFail($owner->id);
        $expense = Expense::query()->lockForUpdate()->findOrFail($locked->expense_id);
        $contract = $expense->contract_id === null ? null : Contract::query()->lockForUpdate()->findOrFail($expense->contract_id);

        return [$locked, Company::query()->lockForUpdate()->findOrFail($expense->company_id), $contract];
    }

    /** @return array{proposal_id: int|null, contract_id: int|null, expense_id: int|null, expense_line_id: int|null} */
    private function ownerColumns(Model $owner): array
    {
        return [
            'proposal_id' => $owner instanceof Proposal ? $owner->id : null,
            'contract_id' => $owner instanceof Contract ? $owner->id : null,
            'expense_id' => $owner instanceof Expense ? $owner->id : null,
            'expense_line_id' => $owner instanceof ExpenseLine ? $owner->id : null,
        ];
    }

    /** @return list<int> */
    private function exerciseIds(Model $owner): array
    {
        if ($owner instanceof Expense) {
            return [$owner->exercise_id];
        }
        if ($owner instanceof ExpenseLine) {
            return [$owner->expense->exercise_id];
        }

        if ($owner instanceof Proposal) {
            return [$owner->exercise_id];
        }

        return [];
    }

    /** @return array<string, mixed> */
    private function snapshot(Attachment $attachment, ?int $ownerContractId): array
    {
        return [
            ...$attachment->only([
                'id', 'proposal_id', 'contract_id', 'expense_id', 'expense_line_id', 'original_name',
                'media_type', 'size_bytes', 'sha256', 'uploaded_by_id', 'detached_at',
            ]),
            'owner_contract_id' => $ownerContractId,
        ];
    }
}
