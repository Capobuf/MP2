<?php

namespace App\Actions\Proposals;

use App\Domain\Company\AuditEventType;
use App\Domain\Proposals\ProposalActionType;
use App\Domain\Proposals\ProposalRelationPlan;
use App\Models\AuditEvent;
use App\Models\Company;
use App\Models\Proposal;
use App\Models\ProposalAction;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

final class PlanProposalRelation
{
    /** @param array<string, mixed> $payload */
    public function execute(User $actor, Proposal $proposal, array $payload, string $operationId, int $expectedRevision): ProposalAction
    {
        return DB::transaction(function () use ($actor, $proposal, $payload, $operationId, $expectedRevision): ProposalAction {
            Company::query()->lockForUpdate()->findOrFail($proposal->company_id);
            $locked = Proposal::query()->with('company')->lockForUpdate()->findOrFail($proposal->id);
            Gate::forUser($actor)->authorize('update', $locked);
            $existing = ProposalAction::query()->where('operation_id', $operationId)->first();
            if ($existing !== null) {
                if ($existing->proposal_id !== $locked->id || $existing->action_type !== ProposalActionType::LinkProjectContract) {
                    throw ValidationException::withMessages(['operation_id' => 'Identificativo operazione già utilizzato.']);
                }

                return $existing;
            }
            if (! Str::isUuid($operationId) || $locked->revision !== $expectedRevision) {
                throw ValidationException::withMessages(['revision' => 'La Proposta è cambiata o l’operazione non è valida.']);
            }
            $validated = ProposalRelationPlan::validate($locked, $payload);
            $duplicate = $locked->actions()->get()->contains(fn (ProposalAction $action): bool => $action->action_type === ProposalActionType::LinkProjectContract && collect($action->payload)->sortKeys()->all() == $validated);
            if ($duplicate) {
                throw ValidationException::withMessages(['relation' => 'Il collegamento è già presente nella Proposta.']);
            }
            $sequence = ((int) $locked->actionHistory()->max('sequence')) + 1;
            $action = $locked->actions()->create(['company_id' => $locked->company_id, 'proposal_item_id' => null, 'sequence' => $sequence, 'action_type' => ProposalActionType::LinkProjectContract, 'payload_version' => 1, 'payload' => $validated, 'created_by_id' => $actor->id, 'operation_id' => $operationId]);
            $locked->increment('revision');
            AuditEvent::query()->create([
                'operation_id' => $operationId,
                'company_id' => $locked->company_id,
                'actor_id' => $actor->id,
                'event_type' => AuditEventType::ProposalActionPlanned,
                'subject_type' => Proposal::class,
                'subject_id' => $locked->id,
                'affected_exercise_ids' => [$locked->exercise_id],
                'effective_from' => now($locked->company->timezone)->toDateString(),
                'previous_value' => ['relation' => null],
                'new_value' => ['action_type' => ProposalActionType::LinkProjectContract->value, 'payload_version' => 1, 'relation' => $validated],
                'allocated_impact_by_exercise' => [(string) $locked->exercise_id => '0.00'],
                'actual_impact_by_exercise' => [(string) $locked->exercise_id => '0.00'],
            ]);

            return $action;
        });
    }
}
