<?php

namespace App\Actions\Proposals;

use App\Domain\Company\AuditEventType;
use App\Domain\Proposals\ExpensePlan;
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

final class PlanExpense
{
    /**
     * @param  array<string, mixed>  $payload
     */
    public function execute(User $actor, Proposal $proposal, ProposalItem $item, ProposalActionType $type, array $payload, ?string $reason, string $operationId, int $expectedRevision): ProposalAction
    {
        return DB::transaction(function () use ($actor, $proposal, $item, $type, $payload, $reason, $operationId, $expectedRevision): ProposalAction {
            [$lockedProposal, $existing] = $this->lockCommand($actor, $proposal, $type, $operationId);
            if ($existing !== null) {
                return $existing;
            }
            if ($lockedProposal->revision !== $expectedRevision) {
                throw ValidationException::withMessages(['revision' => 'La Proposta è cambiata: ricaricare prima di continuare.']);
            }
            $lockedItem = ProposalItem::query()->where('proposal_id', $lockedProposal->id)->lockForUpdate()->find($item->id);
            if ($lockedItem === null) {
                throw ValidationException::withMessages(['item' => 'Elemento non disponibile in questa Proposta.']);
            }
            $validated = ProposalActionPayload::validate($type, $payload);
            if (in_array($type, [ProposalActionType::ReverseExpense, ProposalActionType::RestoreExpense], true) && blank($reason)) {
                throw ValidationException::withMessages(['reason' => 'La motivazione è obbligatoria.']);
            }
            $previous = $lockedItem->result;
            $lockedItem->update(['result' => ExpensePlan::apply($lockedItem, $type, $validated)]);

            return $this->append($actor, $lockedProposal, $lockedItem, $type, $validated, $reason, $operationId, $previous);
        });
    }

    /** @param array<string, mixed> $payload */
    public function create(User $actor, Proposal $proposal, array $payload, ?string $reason, string $operationId, int $expectedRevision, ProposalActionType $type = ProposalActionType::CreateExpense, ?string $copiedFrom = null): ProposalAction
    {
        return DB::transaction(function () use ($actor, $proposal, $payload, $reason, $operationId, $expectedRevision, $type, $copiedFrom): ProposalAction {
            [$lockedProposal, $existing] = $this->lockCommand($actor, $proposal, $type, $operationId);
            if ($existing !== null) {
                return $existing;
            }
            if ($lockedProposal->revision !== $expectedRevision) {
                throw ValidationException::withMessages(['revision' => 'La Proposta è cambiata: ricaricare prima di continuare.']);
            }
            if (! in_array($type, [ProposalActionType::CreateExpense, ProposalActionType::CopyExpense], true)) {
                throw ValidationException::withMessages(['action_type' => 'Azione di creazione Spesa non valida.']);
            }
            $validated = ProposalActionPayload::validate($type, $payload);
            ExpensePlan::validateNew($lockedProposal, $validated);
            $result = $validated;
            unset($result['source_expense_id'], $result['target_exercise_id']);
            $result['exercise_id'] = $validated['exercise_id'] ?? $validated['target_exercise_id'];
            $item = $lockedProposal->items()->create(['proposal_item_id' => (string) Str::uuid(), 'company_id' => $lockedProposal->company_id, 'source_type' => ProposalSourceType::Expense, 'copied_from_origin_key' => $copiedFrom, 'baseline' => ['plan_baseline' => [], 'actual_context' => ['has_actuals' => false, 'actual_total' => '0.00', 'actual_lines' => []]], 'result' => $result, 'readiness_state' => 'aligned', 'readiness_reasons' => [], 'read_only_source' => false, 'last_aligned_at' => now(), 'last_aligned_by_id' => $actor->id]);

            return $this->append($actor, $lockedProposal, $item, $type, $validated, $reason, $operationId, []);
        });
    }

    /** @return array{Proposal, ProposalAction|null} */
    private function lockCommand(User $actor, Proposal $proposal, ProposalActionType $type, string $operationId): array
    {
        if (! Str::isUuid($operationId)) {
            throw ValidationException::withMessages(['operation_id' => 'Identificativo operazione non valido.']);
        }
        Company::query()->lockForUpdate()->findOrFail($proposal->company_id);
        $locked = Proposal::query()->with('company')->lockForUpdate()->findOrFail($proposal->id);
        Gate::forUser($actor)->authorize('update', $locked);
        $existing = ProposalAction::query()->where('operation_id', $operationId)->first();
        if ($existing !== null && ($existing->proposal_id !== $locked->id || $existing->action_type !== $type)) {
            throw ValidationException::withMessages(['operation_id' => 'Identificativo operazione già utilizzato.']);
        }

        return [$locked, $existing];
    }

    /**
     * @param  array<string, mixed>  $payload
     * @param  array<string, mixed>  $previous
     */
    private function append(User $actor, Proposal $proposal, ProposalItem $item, ProposalActionType $type, array $payload, ?string $reason, string $operationId, array $previous): ProposalAction
    {
        $sequence = ((int) $proposal->actions()->max('sequence')) + 1;
        $action = $proposal->actions()->create(['company_id' => $proposal->company_id, 'proposal_item_id' => $item->id, 'sequence' => $sequence, 'action_type' => $type, 'payload_version' => 1, 'payload' => $payload, 'reason' => $reason, 'created_by_id' => $actor->id, 'operation_id' => $operationId]);
        $proposal->increment('revision');
        $impacts = ProposalImpactPlan::build($proposal->fresh(['company', 'exercise', 'items.actions', 'items.expense', 'items.project', 'items.contract']));
        $exerciseIds = collect($impacts)->pluck('exercise_id')->all();
        AuditEvent::query()->create(['operation_id' => $operationId, 'company_id' => $proposal->company_id, 'actor_id' => $actor->id, 'event_type' => AuditEventType::ProposalActionPlanned, 'subject_type' => Proposal::class, 'subject_id' => $proposal->id, 'affected_exercise_ids' => $exerciseIds, 'effective_from' => now($proposal->company->timezone)->toDateString(), 'previous_value' => ['proposal_item_id' => $item->proposal_item_id, 'plan' => $previous], 'new_value' => ['proposal_item_id' => $item->proposal_item_id, 'action_type' => $type->value, 'payload_version' => 1, 'payload' => $payload, 'result' => $item->refresh()->result], 'allocated_impact_by_exercise' => collect($impacts)->mapWithKeys(fn (array $impact): array => [(string) $impact['exercise_id'] => $impact['allocation_delta']])->all(), 'actual_impact_by_exercise' => collect($exerciseIds)->mapWithKeys(fn (int $id): array => [(string) $id => '0.00'])->all(), 'reason' => $reason]);

        return $action;
    }
}
