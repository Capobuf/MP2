<?php

namespace App\Actions\Proposals;

use App\Domain\Company\AuditEventType;
use App\Domain\Proposals\ProposalActionReplay;
use App\Domain\Proposals\ProposalImpactPlan;
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

final class AcknowledgeProposalSource
{
    public function __construct(private ProposalActionReplay $replay) {}

    /** @param (callable(): void)|null $checkpoint */
    public function execute(User $actor, Proposal $proposal, ProposalItem $item, string $operationId, int $expectedRevision, ?callable $checkpoint = null): ProposalItem
    {
        if (! Str::isUuid($operationId)) {
            throw ValidationException::withMessages(['operation_id' => 'Identificativo operazione non valido.']);
        }

        return DB::transaction(function () use ($actor, $proposal, $item, $operationId, $expectedRevision, $checkpoint): ProposalItem {
            $company = Company::query()->lockForUpdate()->findOrFail($proposal->company_id);
            $lockedProposal = Proposal::query()->with('company')->lockForUpdate()->findOrFail($proposal->id);
            Gate::forUser($actor)->authorize('update', $lockedProposal);

            $receipt = AuditEvent::query()->where('operation_id', $operationId)->where('event_sequence', 0)->first();
            if ($receipt !== null) {
                if ($receipt->eventType() !== AuditEventType::ProposalSourceAcknowledged
                    || $receipt->subject_type !== ProposalItem::class
                    || $receipt->company_id !== $company->id) {
                    throw ValidationException::withMessages(['operation_id' => 'Identificativo operazione già utilizzato.']);
                }

                return ProposalItem::query()->where('proposal_id', $lockedProposal->id)->findOrFail($receipt->subject_id);
            }
            if ($lockedProposal->revision !== $expectedRevision) {
                throw ValidationException::withMessages(['revision' => 'La Proposta è cambiata: ricaricare prima di continuare.']);
            }

            $lockedItem = ProposalItem::query()->where('proposal_id', $lockedProposal->id)->lockForUpdate()->findOrFail($item->id);
            if ($lockedItem->readiness_state->value !== 'to_review') {
                throw ValidationException::withMessages(['item' => 'La sorgente non è da prendere in visione.']);
            }
            $source = $this->lockSource($lockedItem);
            $snapshot = match (true) {
                $source instanceof Expense => ProposalSourceSnapshot::expense($source),
                $source instanceof Project => ProposalSourceSnapshot::project($source, $lockedProposal->exercise_id),
                $source instanceof Contract => ProposalSourceSnapshot::contract($source, $lockedProposal->exercise_id),
            };
            $activeActions = ProposalAction::query()->where('proposal_id', $lockedProposal->id)->where('status', 'active')->orderBy('sequence')->lockForUpdate()->get();
            $result = $this->replay->replay($lockedItem, $snapshot, $activeActions);
            $previous = [
                'baseline_revision' => $lockedItem->baseline_revision,
                'baseline_fingerprint' => $lockedItem->baseline_fingerprint,
                'baseline' => $lockedItem->baseline,
                'result' => $lockedItem->result,
                'readiness_state' => $lockedItem->readiness_state->value,
            ];

            $lockedItem->update([
                'baseline_revision' => (int) $source->revision,
                'baseline_fingerprint' => ProposalSourceSnapshot::fingerprint($snapshot),
                'baseline' => $snapshot,
                'result' => $result,
                'readiness_state' => 'aligned',
                'readiness_reasons' => [],
                'read_only_source' => $source instanceof Expense ? $source->isReversed() : $source->isArchived(),
                'last_aligned_at' => now(),
                'last_aligned_by_id' => $actor->id,
            ]);
            $lockedProposal->increment('revision');

            if ($checkpoint !== null) {
                $checkpoint();
            }

            $impacts = ProposalImpactPlan::build($lockedProposal->fresh(['company', 'exercise', 'items.actions', 'items.expense', 'items.project', 'items.contract']));
            $exerciseIds = collect($impacts)->pluck('exercise_id')->map(fn (mixed $id): int => (int) $id)->values()->all();
            AuditEvent::query()->create([
                'operation_id' => $operationId,
                'company_id' => $company->id,
                'actor_id' => $actor->id,
                'event_type' => AuditEventType::ProposalSourceAcknowledged,
                'subject_type' => ProposalItem::class,
                'subject_id' => $lockedItem->id,
                'affected_exercise_ids' => $exerciseIds,
                'effective_from' => now($company->timezone)->toDateString(),
                'previous_value' => $previous,
                'new_value' => [
                    'proposal_id' => $lockedProposal->id,
                    'proposal_item_id' => $lockedItem->proposal_item_id,
                    'baseline_revision' => (int) $source->revision,
                    'baseline_fingerprint' => ProposalSourceSnapshot::fingerprint($snapshot),
                    'result' => $result,
                    'active_action_ids' => $this->replay->touchingActions($lockedItem, $activeActions)->pluck('id')->all(),
                    'impacts' => $impacts,
                    'economic_action_created' => false,
                ],
                'allocated_impact_by_exercise' => collect($impacts)->mapWithKeys(fn (array $impact): array => [(string) $impact['exercise_id'] => $impact['allocation_delta']])->all(),
                'actual_impact_by_exercise' => collect($exerciseIds)->mapWithKeys(fn (int $id): array => [(string) $id => '0.00'])->all(),
            ]);

            return $lockedItem->fresh(['actions', 'actionHistory']);
        }, 3);
    }

    private function lockSource(ProposalItem $item): Expense|Project|Contract
    {
        $source = match ($item->source_type) {
            ProposalSourceType::Expense => Expense::query()->where('company_id', $item->company_id)->lockForUpdate()->find($item->expense_id),
            ProposalSourceType::Project => Project::query()->where('company_id', $item->company_id)->lockForUpdate()->find($item->project_id),
            ProposalSourceType::Contract => Contract::query()->where('company_id', $item->company_id)->lockForUpdate()->find($item->contract_id),
        };
        if ($source === null) {
            throw ValidationException::withMessages(['source' => 'La sorgente non è più disponibile.']);
        }

        if ($source instanceof Project) {
            ProjectTransition::query()->where('project_id', $source->id)->orderBy('id')->lockForUpdate()->get();
            ProjectExerciseClassification::query()->where('project_id', $source->id)->orderBy('id')->lockForUpdate()->get();
            ProjectContractLink::query()->where('project_id', $source->id)->orderBy('id')->lockForUpdate()->get();
        }
        if ($source instanceof Contract) {
            ContractCondition::query()->where('contract_id', $source->id)->orderBy('id')->lockForUpdate()->get();
            ContractLifecycleFact::query()->where('contract_id', $source->id)->orderBy('id')->lockForUpdate()->get();
            ContractRenewalConfiguration::query()->where('contract_id', $source->id)->orderBy('id')->lockForUpdate()->get();
            ContractExerciseClassification::query()->where('contract_id', $source->id)->orderBy('id')->lockForUpdate()->get();
            ProjectContractLink::query()->where('contract_id', $source->id)->orderBy('id')->lockForUpdate()->get();
        }

        $expenseIds = $source instanceof Expense
            ? collect([$source->id])
            : Expense::query()->where($source instanceof Project ? 'project_id' : 'contract_id', $source->id)->lockForUpdate()->pluck('id');
        ExpenseLine::query()->whereIn('expense_id', $expenseIds)->orderBy('id')->lockForUpdate()->get();

        return $source;
    }
}
