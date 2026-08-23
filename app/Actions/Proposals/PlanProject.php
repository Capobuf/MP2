<?php

namespace App\Actions\Proposals;

use App\Domain\Company\AuditEventType;
use App\Domain\Proposals\ProjectPlan;
use App\Domain\Proposals\ProposalActionPayload;
use App\Domain\Proposals\ProposalActionType;
use App\Domain\Proposals\ProposalImpactPlan;
use App\Domain\Proposals\ProposalSourceType;
use App\Models\AuditEvent;
use App\Models\Company;
use App\Models\Proposal;
use App\Models\ProposalAction;
use App\Models\ProposalItem;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

final class PlanProject
{
    /** @param array<string, mixed> $payload */
    public function create(User $actor, Proposal $proposal, array $payload, string $operationId, int $expectedRevision): ProposalAction
    {
        return $this->record($actor, $proposal, null, ProposalActionType::CreateProject, $payload, null, $operationId, $expectedRevision);
    }

    /** @param array<string, mixed> $payload */
    public function execute(User $actor, Proposal $proposal, ProposalItem $item, ProposalActionType $type, array $payload, ?string $reason, string $operationId, int $expectedRevision): ProposalAction
    {
        return $this->record($actor, $proposal, $item, $type, $payload, $reason, $operationId, $expectedRevision);
    }

    /** @param array<string, mixed> $payload */
    private function record(User $actor, Proposal $proposal, ?ProposalItem $item, ProposalActionType $type, array $payload, ?string $reason, string $operationId, int $expectedRevision): ProposalAction
    {
        return DB::transaction(function () use ($actor, $proposal, $item, $type, $payload, $reason, $operationId, $expectedRevision): ProposalAction {
            Company::query()->lockForUpdate()->findOrFail($proposal->company_id);
            $locked = Proposal::query()->with('company')->lockForUpdate()->findOrFail($proposal->id);
            Gate::forUser($actor)->authorize('update', $locked);
            $existing = ProposalAction::query()->where('operation_id', $operationId)->first();
            if ($existing !== null) {
                if ($existing->proposal_id !== $locked->id || $existing->action_type !== $type) {
                    throw ValidationException::withMessages(['operation_id' => 'Identificativo operazione già utilizzato.']);
                }

                return $existing;
            }
            if (! Str::isUuid($operationId) || $locked->revision !== $expectedRevision) {
                throw ValidationException::withMessages(['revision' => 'La Proposta è cambiata o l’operazione non è valida.']);
            }
            $validated = ProposalActionPayload::validate($type, $payload);
            $previous = [];
            if ($item === null) {
                if ($type !== ProposalActionType::CreateProject) {
                    throw ValidationException::withMessages(['item' => 'Elemento Progetto obbligatorio.']);
                }
                $lockedItem = $locked->items()->create(['proposal_item_id' => (string) Str::uuid(), 'company_id' => $locked->company_id, 'source_type' => ProposalSourceType::Project, 'baseline' => ['plan_baseline' => [], 'actual_context' => ['has_actuals' => false]], 'result' => ProjectPlan::create($validated), 'readiness_state' => 'aligned', 'readiness_reasons' => [], 'read_only_source' => false, 'last_aligned_at' => now(), 'last_aligned_by_id' => $actor->id]);
            } else {
                $lockedItem = ProposalItem::query()->where('proposal_id', $locked->id)->lockForUpdate()->find($item->id);
                if ($lockedItem === null) {
                    throw ValidationException::withMessages(['item' => 'Elemento non disponibile.']);
                }
                $previous = $lockedItem->result;
                $lockedItem->update(['result' => ProjectPlan::apply($locked, $lockedItem, $type, $validated)]);
            }
            $sequence = ((int) $locked->actionHistory()->max('sequence')) + 1;
            $action = $locked->actions()->create(['company_id' => $locked->company_id, 'proposal_item_id' => $lockedItem->id, 'sequence' => $sequence, 'action_type' => $type, 'payload_version' => 1, 'payload' => $validated, 'reason' => $reason, 'created_by_id' => $actor->id, 'operation_id' => $operationId]);
            $locked->increment('revision');
            $impacts = ProposalImpactPlan::build($locked->fresh(['company', 'exercise', 'items.actions', 'items.expense', 'items.project', 'items.contract']));
            $exerciseIds = collect($impacts)->pluck('exercise_id')->all();
            AuditEvent::query()->create(['operation_id' => $operationId, 'company_id' => $locked->company_id, 'actor_id' => $actor->id, 'event_type' => AuditEventType::ProposalActionPlanned, 'subject_type' => Proposal::class, 'subject_id' => $locked->id, 'affected_exercise_ids' => $exerciseIds, 'effective_from' => now($locked->company->timezone)->toDateString(), 'previous_value' => ['proposal_item_id' => $lockedItem->proposal_item_id, 'plan' => $previous], 'new_value' => ['proposal_item_id' => $lockedItem->proposal_item_id, 'action_type' => $type->value, 'payload_version' => 1, 'payload' => $validated, 'result' => $lockedItem->refresh()->result], 'allocated_impact_by_exercise' => collect($impacts)->mapWithKeys(fn (array $impact): array => [(string) $impact['exercise_id'] => $impact['allocation_delta']])->all(), 'actual_impact_by_exercise' => collect($exerciseIds)->mapWithKeys(fn (int $id): array => [(string) $id => '0.00'])->all(), 'reason' => $reason]);

            return $action;
        });
    }
}
