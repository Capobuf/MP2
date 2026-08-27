<?php

namespace App\Actions\Operations;

use App\Domain\Company\AuditEventType;
use App\Models\Attachment;
use App\Models\AuditEvent;
use App\Models\Company;
use App\Models\Contract;
use App\Models\Expense;
use App\Models\ExpenseLine;
use App\Models\HistoricalErrorAnnotation;
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
    public function execute(User $actor, Contract|Expense|ExpenseLine|HistoricalErrorAnnotation|Proposal $owner, UploadedFile $file, string $operationId): Attachment
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
                $retainedCorrection = $lockedOwner instanceof ExpenseLine
                    && $lockedOwner->lateCorrection()->exists();
                $retainedAnnotation = $lockedOwner instanceof HistoricalErrorAnnotation;
                if ($retainedCorrection || $retainedAnnotation) {
                    Gate::forUser($actor)->authorize('create', [Attachment::class, $company, $lockedOwner]);
                } elseif ($lockedOwner instanceof Proposal) {
                    Gate::forUser($actor)->authorize('update', $lockedOwner);
                } else {
                    Gate::forUser($actor)->authorize('create', [Attachment::class, $company, $lockedOwner]);
                }
                if ($contract?->isArchived() && ! $retainedCorrection && ! $retainedAnnotation) {
                    throw ValidationException::withMessages(['attachment' => 'Ripristinare il Contratto prima di aggiungere Allegati.']);
                }

                $existing = AuditEvent::query()->where('operation_id', $operationId)->lockForUpdate()->first();
                if ($existing !== null) {
                    $attachment = Attachment::query()->lockForUpdate()->find($existing->subject_id);
                    if ($existing->company_id !== $company->id
                        || $existing->eventType() !== AuditEventType::AttachmentUploaded
                        || $existing->subject_type !== Attachment::class
                        || $attachment === null
                        || $attachment->company_id !== $company->id
                        || $existing->reference_type !== $lockedOwner::class
                        || (int) $existing->reference_id !== (int) $lockedOwner->getKey()
                        || ! $this->attachmentMatchesOwner($attachment, $lockedOwner)) {
                        throw ValidationException::withMessages(['operation_id' => 'Identificativo operazione già utilizzato per un altro Azienda o proprietario.']);
                    }

                    return $attachment;
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
    private function lockOwner(Contract|Expense|ExpenseLine|HistoricalErrorAnnotation|Proposal $owner): array
    {
        if ($owner instanceof Proposal) {
            $company = Company::query()->lockForUpdate()->findOrFail($owner->company_id);
            $locked = Proposal::query()->lockForUpdate()->findOrFail($owner->id);

            return [$locked, $company, null];
        }
        if ($owner instanceof HistoricalErrorAnnotation) {
            $company = Company::query()->lockForUpdate()->findOrFail($owner->company_id);
            $locked = HistoricalErrorAnnotation::query()->lockForUpdate()->findOrFail($owner->id);

            return [$locked, $company, null];
        }
        if ($owner instanceof Contract) {
            $company = Company::query()->lockForUpdate()->findOrFail($owner->company_id);
            $locked = Contract::query()->lockForUpdate()->findOrFail($owner->id);

            return [$locked, $company, $locked];
        }
        if ($owner instanceof Expense) {
            $unlocked = Expense::query()->findOrFail($owner->id);
            $company = Company::query()->lockForUpdate()->findOrFail($unlocked->company_id);
            $contract = $unlocked->contract_id === null ? null : Contract::query()->lockForUpdate()->findOrFail($unlocked->contract_id);
            $locked = Expense::query()->lockForUpdate()->findOrFail($unlocked->id);

            return [$locked, $company, $contract];
        }

        $unlockedLine = ExpenseLine::query()->findOrFail($owner->id);
        $unlockedExpense = Expense::query()->findOrFail($unlockedLine->expense_id);
        $company = Company::query()->lockForUpdate()->findOrFail($unlockedExpense->company_id);
        $contract = $unlockedExpense->contract_id === null ? null : Contract::query()->lockForUpdate()->findOrFail($unlockedExpense->contract_id);
        $expense = Expense::query()->lockForUpdate()->findOrFail($unlockedExpense->id);
        $locked = ExpenseLine::query()->lockForUpdate()->findOrFail($unlockedLine->id);

        return [$locked, $company, $contract];
    }

    /** @return array{proposal_id: int|null, contract_id: int|null, expense_id: int|null, expense_line_id: int|null, historical_error_annotation_id: int|null} */
    private function ownerColumns(Model $owner): array
    {
        return [
            'proposal_id' => $owner instanceof Proposal ? $owner->id : null,
            'contract_id' => $owner instanceof Contract ? $owner->id : null,
            'expense_id' => $owner instanceof Expense ? $owner->id : null,
            'expense_line_id' => $owner instanceof ExpenseLine ? $owner->id : null,
            'historical_error_annotation_id' => $owner instanceof HistoricalErrorAnnotation ? $owner->id : null,
        ];
    }

    private function attachmentMatchesOwner(Attachment $attachment, Model $owner): bool
    {
        foreach ($this->ownerColumns($owner) as $column => $value) {
            if ((int) $attachment->getAttribute($column) !== (int) ($value ?? 0)) {
                return false;
            }
        }

        return true;
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
        if ($owner instanceof HistoricalErrorAnnotation) {
            return [$owner->exercise_id];
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
                'id', 'proposal_id', 'contract_id', 'expense_id', 'expense_line_id', 'historical_error_annotation_id', 'original_name',
                'media_type', 'size_bytes', 'sha256', 'uploaded_by_id', 'detached_at',
            ]),
            'owner_contract_id' => $ownerContractId,
        ];
    }
}
