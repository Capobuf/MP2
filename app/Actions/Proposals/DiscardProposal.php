<?php

namespace App\Actions\Proposals;

use App\Domain\Company\AuditEventType;
use App\Domain\Company\Capability;
use App\Models\AuditEvent;
use App\Models\Company;
use App\Models\Proposal;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

final class DiscardProposal
{
    public function execute(User $actor, Proposal $proposal, string $reason, string $operationId): Proposal
    {
        $reason = trim($reason);
        if ($reason === '') {
            throw ValidationException::withMessages(['reason' => 'La motivazione dello scarto è obbligatoria.']);
        }
        if (! Str::isUuid($operationId)) {
            throw ValidationException::withMessages(['operation_id' => 'Identificativo operazione non valido.']);
        }

        return DB::transaction(function () use ($actor, $proposal, $reason, $operationId): Proposal {
            $company = Company::query()->lockForUpdate()->findOrFail($proposal->company_id);
            $locked = Proposal::query()->with('company')->lockForUpdate()->findOrFail($proposal->id);

            if ($locked->status->value === 'discarded') {
                if ($locked->discard_operation_id === $operationId && $actor->hasCapability($company, Capability::ManageProposals)) {
                    return $locked;
                }
                throw ValidationException::withMessages(['proposal' => 'La Proposta è già terminale.']);
            }
            Gate::forUser($actor)->authorize('update', $locked);
            if ($locked->status->value !== 'draft') {
                throw ValidationException::withMessages(['proposal' => 'La Proposta è già terminale.']);
            }
            if (AuditEvent::query()->where('operation_id', $operationId)->exists()) {
                throw ValidationException::withMessages(['operation_id' => 'Identificativo operazione già utilizzato.']);
            }

            $previousRevision = $locked->revision;
            $locked->update([
                'status' => 'discarded',
                'discarded_by_id' => $actor->id,
                'discarded_at' => now(),
                'discard_reason' => $reason,
                'discard_operation_id' => $operationId,
                'revision' => $previousRevision + 1,
            ]);
            AuditEvent::query()->create([
                'operation_id' => $operationId,
                'company_id' => $company->id,
                'actor_id' => $actor->id,
                'event_type' => AuditEventType::ProposalDiscarded,
                'subject_type' => Proposal::class,
                'subject_id' => $locked->id,
                'affected_exercise_ids' => [$locked->exercise_id],
                'effective_from' => now($company->timezone)->toDateString(),
                'previous_value' => ['status' => 'draft', 'revision' => $previousRevision],
                'new_value' => ['status' => 'discarded', 'revision' => $previousRevision + 1, 'live_reality_changed' => false, 'budgets_changed' => false],
                'allocated_impact_by_exercise' => [(string) $locked->exercise_id => '0.00'],
                'actual_impact_by_exercise' => [(string) $locked->exercise_id => '0.00'],
                'reason' => $reason,
            ]);

            return $locked->fresh(['items', 'actions', 'actionHistory']);
        }, 3);
    }
}
