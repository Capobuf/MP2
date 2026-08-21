<?php

namespace App\Actions\Proposals;

use App\Domain\Company\AuditEventType;
use App\Domain\Proposals\BudgetSnapshotPayload;
use App\Domain\Proposals\ProposalActionType;
use App\Domain\Proposals\ProposalImpactPlan;
use App\Domain\Proposals\ProposalPlanData;
use App\Models\Attachment;
use App\Models\AuditEvent;
use App\Models\BudgetSnapshot;
use App\Models\Contract;
use App\Models\Expense;
use App\Models\Project;
use App\Models\Proposal;
use App\Models\User;
use Illuminate\Validation\ValidationException;

final class MaterializeBudgetV1
{
    /**
     * @param  array<string, Expense|Project|Contract>  $identities
     * @param  array<string, mixed>  $evidence
     * @param  list<int>  $attachmentIds
     * @param  (callable(string): void)|null  $checkpoint
     * @param  array<int, array<string, mixed>>|null  $confirmedImpacts
     */
    public function execute(Proposal $proposal, array $identities, User $actor, string $operationId, array $evidence = [], array $attachmentIds = [], ?callable $checkpoint = null, ?array $confirmedImpacts = null): BudgetSnapshot
    {
        $impacts = $confirmedImpacts ?? ProposalImpactPlan::build($proposal);
        $staleProposals = collect($impacts)->flatMap(fn (array $impact): array => ProposalPlanData::rows($impact['stale_proposals'] ?? null, 'stale_proposals'))->unique('proposal_id')->values();
        $eventSequences = [];
        foreach ($proposal->actions->sortBy('sequence') as $action) {
            $eventSequences[$action->id] = count($eventSequences);
        }
        foreach ($staleProposals as $stale) {
            $eventSequences['stale:'.$stale['proposal_id']] = count($eventSequences);
        }
        $eventSequences[-1] = count($eventSequences);
        $eventSequences[-2] = count($eventSequences);
        $payload = BudgetSnapshotPayload::build($proposal, $identities, $eventSequences);
        $approvalEvidence = ['external_subject' => $evidence['external_subject'] ?? null, 'external_venue' => $evidence['external_venue'] ?? null, 'reason' => $evidence['reason'] ?? null, 'attachment_ids' => collect($attachmentIds)->map(fn (mixed $id): int => (int) $id)->unique()->sort()->values()->all()];
        $budget = BudgetSnapshot::query()->create(['company_id' => $proposal->company_id, 'exercise_id' => $proposal->exercise_id, 'proposal_id' => $proposal->id, 'version' => 1, 'purpose' => 'initial_budget', 'approved_at' => now(), 'approved_by_id' => $actor->id, 'previous_budget_id' => null, 'total_approved_allocation' => $payload['total'], 'affected_exercises' => $impacts, 'operation_id' => $operationId]);
        self::checkpoint($checkpoint, 'after_budget_header');

        foreach ($proposal->actions->sortBy('sequence') as $action) {
            $item = $action->proposal_item_id === null ? null : $action->item;
            $live = $item === null ? null : ($identities[$item->proposal_item_id] ?? null);
            $plannedEvent = AuditEvent::query()->where('operation_id', $action->operation_id)->where('event_sequence', 0)->first();
            $affectedExerciseIds = collect($impacts)->pluck('exercise_id')->all();
            $actionAllocatedImpact = collect($impacts)->mapWithKeys(function (array $impact) use ($action): array {
                $sources = ProposalPlanData::rows($impact['sources'] ?? null, 'sources');
                $delta = collect($sources)->firstWhere('proposal_item_id', $action->proposal_item_id === null ? null : $action->item->proposal_item_id)['delta'] ?? '0.00';

                return [(string) $impact['exercise_id'] => $delta];
            })->all();
            AuditEvent::query()->create([
                'operation_id' => $operationId, 'event_sequence' => $eventSequences[$action->id],
                'company_id' => $proposal->company_id, 'actor_id' => $actor->id,
                'event_type' => $this->appliedEventType($action->action_type, $action->payload),
                'subject_type' => $live === null ? Proposal::class : $live->getMorphClass(), 'subject_id' => $live === null ? $proposal->id : $live->id,
                'affected_exercise_ids' => $affectedExerciseIds, 'effective_from' => now($proposal->company->timezone)->toDateString(),
                'previous_value' => ['proposal_id' => $proposal->id, 'proposal_item_id' => $item?->proposal_item_id, 'baseline_revision' => $item?->baseline_revision, 'plan' => data_get($plannedEvent?->previous_value, 'plan', data_get($item?->baseline, 'plan_baseline'))],
                'new_value' => ['proposal_id' => $proposal->id, 'proposal_action_id' => $action->id, 'action_type' => $action->action_type->value, 'action_payload' => $action->payload, 'approved_plan' => data_get($plannedEvent?->new_value, 'result', $item?->result), 'live_type' => $live?->getMorphClass(), 'live_id' => $live?->id, 'budget_id' => $budget->id],
                'allocated_impact_by_exercise' => $actionAllocatedImpact,
                'actual_impact_by_exercise' => collect($affectedExerciseIds)->mapWithKeys(fn (int $exerciseId): array => [(string) $exerciseId => '0.00'])->all(),
                'reason' => $action->reason, 'reference_type' => BudgetSnapshot::class, 'reference_id' => $budget->id,
            ]);
        }
        $sequence = count($proposal->actions);
        foreach ($staleProposals as $stale) {
            AuditEvent::query()->create([
                'operation_id' => $operationId, 'event_sequence' => $sequence++, 'company_id' => $proposal->company_id, 'actor_id' => $actor->id,
                'event_type' => AuditEventType::ProposalMarkedToRealign, 'subject_type' => Proposal::class, 'subject_id' => $stale['proposal_id'],
                'affected_exercise_ids' => [$stale['exercise_id']], 'effective_from' => now($proposal->company->timezone)->toDateString(),
                'previous_value' => ['readiness_state' => 'aligned'], 'new_value' => ['readiness_state' => 'to_realign', 'caused_by_proposal_id' => $proposal->id, 'budget_id' => $budget->id],
                'allocated_impact_by_exercise' => [(string) $stale['exercise_id'] => '0.00'], 'actual_impact_by_exercise' => [(string) $stale['exercise_id'] => '0.00'],
                'reason' => 'La sorgente viva è cambiata durante l’approvazione di un’altra Proposta.', 'reference_type' => BudgetSnapshot::class, 'reference_id' => $budget->id,
            ]);
        }
        $affectedExerciseIds = collect($impacts)->pluck('exercise_id')->all();
        $zeroActualImpact = collect($affectedExerciseIds)->mapWithKeys(fn (int $id): array => [(string) $id => '0.00'])->all();
        AuditEvent::query()->create(['operation_id' => $operationId, 'event_sequence' => $sequence++, 'company_id' => $proposal->company_id, 'actor_id' => $actor->id, 'event_type' => AuditEventType::ProposalApproved, 'subject_type' => Proposal::class, 'subject_id' => $proposal->id, 'affected_exercise_ids' => $affectedExerciseIds, 'effective_from' => now($proposal->company->timezone)->toDateString(), 'previous_value' => ['status' => 'draft'], 'new_value' => ['status' => 'approved', 'budget_id' => $budget->id, 'version' => 1, 'approval_evidence' => $approvalEvidence, 'applied_impacts' => $impacts], 'allocated_impact_by_exercise' => collect($impacts)->mapWithKeys(fn (array $impact): array => [(string) $impact['exercise_id'] => $impact['allocation_delta']])->all(), 'actual_impact_by_exercise' => $zeroActualImpact, 'reason' => $evidence['reason'] ?? null, 'reference_type' => BudgetSnapshot::class, 'reference_id' => $budget->id]);
        AuditEvent::query()->create([
            'operation_id' => $operationId,
            'event_sequence' => $sequence,
            'company_id' => $proposal->company_id,
            'actor_id' => $actor->id,
            'event_type' => AuditEventType::BudgetCreated,
            'subject_type' => BudgetSnapshot::class,
            'subject_id' => $budget->id,
            'affected_exercise_ids' => collect($impacts)->pluck('exercise_id')->all(),
            'effective_from' => now($proposal->company->timezone)->toDateString(),
            'new_value' => ['proposal_id' => $proposal->id, 'budget_id' => $budget->id, 'version' => 1, 'total_approved_allocation' => $payload['total'], 'approval_evidence' => $approvalEvidence, 'affected_exercises' => $impacts],
            'allocated_impact_by_exercise' => collect($impacts)->mapWithKeys(fn (array $impact): array => [(string) $impact['exercise_id'] => $impact['allocation_delta']])->all(),
            'actual_impact_by_exercise' => $zeroActualImpact,
        ]);
        self::checkpoint($checkpoint, 'after_audit_events');

        foreach ($payload['rows'] as $row) {
            $budget->rows()->create($row);
        }
        self::checkpoint($checkpoint, 'after_budget_rows');
        $budget->evidence()->create(['company_id' => $proposal->company_id, 'external_subject' => $evidence['external_subject'] ?? null, 'external_venue' => $evidence['external_venue'] ?? null, 'reason' => $evidence['reason'] ?? null]);
        foreach ($attachmentIds as $attachmentId) {
            $attachment = Attachment::query()->lockForUpdate()->find($attachmentId);
            if ($attachment === null || $attachment->company_id !== $proposal->company_id || $attachment->detached_at !== null) {
                throw ValidationException::withMessages(['attachments' => 'Allegato non disponibile in questa Azienda.']);
            }
            $budget->evidence()->create(['company_id' => $proposal->company_id, 'attachment_id' => $attachment->id, 'storage_disk' => $attachment->storage_disk, 'storage_path' => $attachment->storage_path, 'original_name' => $attachment->original_name, 'media_type' => $attachment->media_type, 'size_bytes' => $attachment->size_bytes, 'sha256' => $attachment->sha256]);
        }
        self::checkpoint($checkpoint, 'after_evidence');
        $proposal->update(['status' => 'approved', 'approved_by_id' => $actor->id, 'approved_at' => $budget->approved_at, 'approval_operation_id' => $operationId, 'revision' => $proposal->revision + 1]);
        self::checkpoint($checkpoint, 'after_proposal_status');

        return $budget->load(['rows', 'evidence']);
    }

    /** @param array<string, mixed> $payload */
    private function appliedEventType(ProposalActionType $type, array $payload): AuditEventType
    {
        return match ($type) {
            ProposalActionType::CreateExpense, ProposalActionType::CopyExpense => AuditEventType::ExpenseCreated,
            ProposalActionType::SetExpenseEstimates => AuditEventType::ExpenseLineUpdated,
            ProposalActionType::SetExpenseOwner, ProposalActionType::SetExpenseCostCenter => AuditEventType::ExpenseMovedOrReclassified,
            ProposalActionType::SetExpenseSupplier => AuditEventType::ExpenseUpdated,
            ProposalActionType::ReverseExpense => AuditEventType::ExpenseReversed,
            ProposalActionType::RestoreExpense => AuditEventType::ExpenseRestored,
            ProposalActionType::CreateProject => AuditEventType::ProjectCreated,
            ProposalActionType::PlanProjectChildExpenses => AuditEventType::ExpenseLineUpdated,
            ProposalActionType::SetProjectCostCenter => AuditEventType::ProjectClassificationChanged,
            ProposalActionType::PlanProjectTransition => AuditEventType::ProjectTransitionPlanned,
            ProposalActionType::CreateContract => AuditEventType::ContractCreated,
            ProposalActionType::AddContractCondition => AuditEventType::ContractConditionCreated,
            ProposalActionType::ChangeContractEconomics => AuditEventType::ContractConditionChanged,
            ProposalActionType::PlanContractLifecycle => ($payload['type'] ?? null) === 'reactivation' ? AuditEventType::ContractReactivated : AuditEventType::ContractCessated,
            ProposalActionType::SetContractRenewal => AuditEventType::ContractRenewalChanged,
            ProposalActionType::SetContractCostCenter => AuditEventType::ContractClassificationChanged,
            ProposalActionType::LinkProjectContract => isset($payload['restore_link_id']) ? AuditEventType::ProjectContractLinkRestored : AuditEventType::ProjectContractLinked,
        };
    }

    /** @param (callable(string): void)|null $checkpoint */
    private static function checkpoint(?callable $checkpoint, string $name): void
    {
        if ($checkpoint !== null) {
            $checkpoint($name);
        }
    }
}
