<?php

namespace App\Actions\Proposals;

use App\Domain\Company\AuditEventType;
use App\Domain\Proposals\ProposalActionReplay;
use App\Domain\Proposals\ProposalActionType;
use App\Domain\Proposals\ProposalImpactPlan;
use App\Domain\Proposals\ProposalReadiness;
use App\Domain\Proposals\ProposalRealignmentChoice;
use App\Domain\Proposals\ProposalSourceSnapshot;
use App\Domain\Proposals\ProposalSourceType;
use App\Models\AuditEvent;
use App\Models\Company;
use App\Models\Contract;
use App\Models\ContractCondition;
use App\Models\ContractExerciseClassification;
use App\Models\ContractLifecycleFact;
use App\Models\ContractRenewalConfiguration;
use App\Models\Expense;
use App\Models\ExpenseLine;
use App\Models\Project;
use App\Models\ProjectContractLink;
use App\Models\ProjectExerciseClassification;
use App\Models\ProjectTransition;
use App\Models\Proposal;
use App\Models\ProposalAction;
use App\Models\ProposalItem;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

final class RealignProposalItem
{
    public function __construct(private ProposalActionReplay $replay, private ProposalReadiness $readiness) {}

    /**
     * @param  list<int>  $retainedActionIds
     * @param  (callable(): void)|null  $checkpoint
     */
    public function execute(User $actor, Proposal $proposal, ProposalItem $item, ProposalRealignmentChoice $choice, ?string $reason, array $retainedActionIds, string $operationId, int $expectedRevision, ?callable $checkpoint = null): ProposalItem
    {
        if (! Str::isUuid($operationId)) {
            throw ValidationException::withMessages(['operation_id' => 'Identificativo operazione non valido.']);
        }
        $reason = filled($reason) ? trim((string) $reason) : null;
        if ($choice === ProposalRealignmentChoice::Keep && $reason === null) {
            throw ValidationException::withMessages(['reason' => 'La motivazione è obbligatoria per mantenere la proposta.']);
        }

        return DB::transaction(function () use ($actor, $proposal, $item, $choice, $reason, $retainedActionIds, $operationId, $expectedRevision, $checkpoint): ProposalItem {
            $company = Company::query()->lockForUpdate()->findOrFail($proposal->company_id);
            $lockedProposal = Proposal::query()->with('company')->lockForUpdate()->findOrFail($proposal->id);
            Gate::forUser($actor)->authorize('update', $lockedProposal);

            $receipt = AuditEvent::query()->where('operation_id', $operationId)->where('event_sequence', 0)->first();
            if ($receipt !== null) {
                if (! in_array($receipt->eventType(), $this->eventTypes(), true)
                    || $receipt->subject_type !== ProposalItem::class
                    || $receipt->company_id !== $company->id) {
                    throw ValidationException::withMessages(['operation_id' => 'Identificativo operazione già utilizzato.']);
                }

                return ProposalItem::query()->where('proposal_id', $lockedProposal->id)->findOrFail($receipt->subject_id);
            }
            if ($lockedProposal->revision !== $expectedRevision) {
                throw ValidationException::withMessages(['revision' => 'La Proposta è cambiata: ricaricare prima di riallineare.']);
            }

            $lockedItem = ProposalItem::query()
                ->where('proposal_id', $lockedProposal->id)
                ->lockForUpdate()
                ->findOrFail($item->id);
            $source = $this->lockSource($lockedItem);
            $snapshot = match (true) {
                $source instanceof Expense => ProposalSourceSnapshot::expense($source),
                $source instanceof Project => ProposalSourceSnapshot::project($source, $lockedProposal->exercise_id),
                $source instanceof Contract => ProposalSourceSnapshot::contract($source, $lockedProposal->exercise_id),
            };
            $fingerprint = ProposalSourceSnapshot::fingerprint($snapshot);
            if ($lockedItem->readiness_state->value !== 'to_realign'
                && $fingerprint === $lockedItem->baseline_fingerprint
                && (int) $source->revision === $lockedItem->baseline_revision) {
                throw ValidationException::withMessages(['item' => 'La sorgente non richiede riallineamento.']);
            }

            $lockedActions = ProposalAction::query()
                ->where('proposal_id', $lockedProposal->id)
                ->orderBy('sequence')
                ->lockForUpdate()
                ->get();
            $touching = $this->replay->touchingActions($lockedItem, $lockedActions);
            $retained = match ($choice) {
                ProposalRealignmentChoice::Reload => collect(),
                ProposalRealignmentChoice::Keep => $touching,
                ProposalRealignmentChoice::Manual => $touching->whereIn('id', array_map('intval', $retainedActionIds))->values(),
            };
            if ($choice === ProposalRealignmentChoice::Manual
                && collect($retainedActionIds)->map(fn (mixed $id): int => (int) $id)->diff($touching->pluck('id'))->isNotEmpty()) {
                throw ValidationException::withMessages(['retained_actions' => 'Una decisione selezionata non appartiene alla sorgente.']);
            }

            $retainsDeferral = $lockedItem->source_type === ProposalSourceType::Project
                && $retained->contains(fn (ProposalAction $action): bool => $action->action_type === ProposalActionType::PlanProjectDeferral);
            $result = $this->replay->replay($lockedItem, $snapshot, $retained, ! $retainsDeferral);
            $withdrawn = $touching->whereNotIn('id', $retained->pluck('id'))->values();
            foreach ($withdrawn as $action) {
                $action->update([
                    'status' => 'withdrawn',
                    'withdrawn_by_id' => $actor->id,
                    'withdrawn_at' => now(),
                    'withdraw_operation_id' => $operationId,
                    'withdraw_reason' => $reason ?? $choice->label(),
                ]);
            }

            $previous = [
                'baseline_revision' => $lockedItem->baseline_revision,
                'baseline_fingerprint' => $lockedItem->baseline_fingerprint,
                'baseline' => $lockedItem->baseline,
                'result' => $lockedItem->result,
            ];
            $lockedItem->update([
                'baseline_revision' => (int) $source->revision,
                'baseline_fingerprint' => $fingerprint,
                'baseline' => $snapshot,
                'result' => $result,
                'readiness_state' => 'aligned',
                'readiness_reasons' => [],
                'read_only_source' => $source instanceof Expense ? $source->isReversed() : $source->isArchived(),
                'last_aligned_at' => now(),
                'last_aligned_by_id' => $actor->id,
            ]);
            if ($retainsDeferral) {
                $assessment = $this->readiness->assessItem($lockedItem->fresh(['proposal', 'project', 'actions']));
                $lockedItem->update([
                    'readiness_state' => $assessment['state'],
                    'readiness_reasons' => $assessment['reasons'],
                ]);
            }
            $lockedProposal->increment('revision');

            if ($checkpoint !== null) {
                $checkpoint();
            }

            $impacts = ProposalImpactPlan::build($lockedProposal->fresh(['company', 'exercise', 'items.actions', 'items.expense', 'items.project', 'items.contract']));
            $affectedExerciseIds = collect($impacts)->pluck('exercise_id')->map(fn (mixed $id): int => (int) $id)->values()->all();
            AuditEvent::query()->create([
                'operation_id' => $operationId,
                'event_sequence' => 0,
                'company_id' => $company->id,
                'actor_id' => $actor->id,
                'event_type' => $this->eventType($choice),
                'subject_type' => ProposalItem::class,
                'subject_id' => $lockedItem->id,
                'affected_exercise_ids' => $affectedExerciseIds,
                'effective_from' => now($company->timezone)->toDateString(),
                'previous_value' => $previous,
                'new_value' => [
                    'choice' => $choice->value,
                    'baseline_revision' => (int) $source->revision,
                    'baseline_fingerprint' => $fingerprint,
                    'baseline' => $snapshot,
                    'result' => $result,
                    'retained_action_ids' => $retained->pluck('id')->all(),
                    'withdrawn_action_ids' => $withdrawn->pluck('id')->all(),
                    'impacts' => $impacts,
                ],
                'allocated_impact_by_exercise' => collect($impacts)->mapWithKeys(fn (array $impact): array => [(string) $impact['exercise_id'] => $impact['allocation_delta']])->all(),
                'actual_impact_by_exercise' => collect($affectedExerciseIds)->mapWithKeys(fn (int $id): array => [(string) $id => '0.00'])->all(),
                'reason' => $reason,
            ]);
            foreach ($withdrawn as $index => $action) {
                AuditEvent::query()->create([
                    'operation_id' => $operationId,
                    'event_sequence' => $index + 1,
                    'company_id' => $company->id,
                    'actor_id' => $actor->id,
                    'event_type' => AuditEventType::ProposalActionWithdrawn,
                    'subject_type' => ProposalAction::class,
                    'subject_id' => $action->id,
                    'affected_exercise_ids' => $affectedExerciseIds,
                    'effective_from' => now($company->timezone)->toDateString(),
                    'previous_value' => ['status' => 'active'],
                    'new_value' => ['status' => 'withdrawn', 'proposal_item_id' => $lockedItem->proposal_item_id],
                    'allocated_impact_by_exercise' => collect($affectedExerciseIds)->mapWithKeys(fn (int $id): array => [(string) $id => '0.00'])->all(),
                    'actual_impact_by_exercise' => collect($affectedExerciseIds)->mapWithKeys(fn (int $id): array => [(string) $id => '0.00'])->all(),
                    'reason' => $reason ?? $choice->label(),
                ]);
            }

            return $lockedItem->fresh(['actions', 'actionHistory']);
        }, 3);
    }

    private function lockSource(ProposalItem $item): Expense|Project|Contract
    {
        if ($item->source_type === ProposalSourceType::Expense) {
            $source = Expense::query()->where('company_id', $item->company_id)->lockForUpdate()->find($item->expense_id);
            if ($source !== null) {
                ExpenseLine::query()->where('expense_id', $source->id)->orderBy('id')->lockForUpdate()->get();
            }
        } elseif ($item->source_type === ProposalSourceType::Project) {
            $source = Project::query()->where('company_id', $item->company_id)->lockForUpdate()->find($item->project_id);
            if ($source !== null) {
                ProjectTransition::query()->where('project_id', $source->id)->orderBy('id')->lockForUpdate()->get();
                ProjectExerciseClassification::query()->where('project_id', $source->id)->orderBy('id')->lockForUpdate()->get();
                $expenseIds = Expense::query()->where('project_id', $source->id)->lockForUpdate()->pluck('id');
                ExpenseLine::query()->whereIn('expense_id', $expenseIds)->orderBy('id')->lockForUpdate()->get();
                ProjectContractLink::query()->where('project_id', $source->id)->orderBy('id')->lockForUpdate()->get();
            }
        } else {
            $source = Contract::query()->where('company_id', $item->company_id)->lockForUpdate()->find($item->contract_id);
            if ($source !== null) {
                ContractCondition::query()->where('contract_id', $source->id)->orderBy('id')->lockForUpdate()->get();
                ContractLifecycleFact::query()->where('contract_id', $source->id)->orderBy('id')->lockForUpdate()->get();
                ContractRenewalConfiguration::query()->where('contract_id', $source->id)->orderBy('id')->lockForUpdate()->get();
                ContractExerciseClassification::query()->where('contract_id', $source->id)->orderBy('id')->lockForUpdate()->get();
                $expenseIds = Expense::query()->where('contract_id', $source->id)->lockForUpdate()->pluck('id');
                ExpenseLine::query()->whereIn('expense_id', $expenseIds)->orderBy('id')->lockForUpdate()->get();
                ProjectContractLink::query()->where('contract_id', $source->id)->orderBy('id')->lockForUpdate()->get();
            }
        }

        if ($source === null) {
            throw ValidationException::withMessages(['source' => 'La sorgente non è più disponibile.']);
        }

        return $source;
    }

    private function eventType(ProposalRealignmentChoice $choice): AuditEventType
    {
        return match ($choice) {
            ProposalRealignmentChoice::Reload => AuditEventType::ProposalRealityReloaded,
            ProposalRealignmentChoice::Keep => AuditEventType::ProposalPlanKept,
            ProposalRealignmentChoice::Manual => AuditEventType::ProposalManuallyRealigned,
        };
    }

    /** @return list<AuditEventType> */
    private function eventTypes(): array
    {
        return [AuditEventType::ProposalRealityReloaded, AuditEventType::ProposalPlanKept, AuditEventType::ProposalManuallyRealigned];
    }
}
