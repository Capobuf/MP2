<?php

namespace App\Domain\Proposals;

use App\Models\BudgetSnapshot;
use App\Models\Contract;
use App\Models\Expense;
use App\Models\Project;
use App\Models\Proposal;
use App\Models\ProposalItem;
use Illuminate\Validation\ValidationException;

final class ProposalReadiness
{
    public function __construct(private ProposalSourceCatalog $catalog) {}

    public function stateForNewItem(ProposalItem $item): ProposalReadinessState
    {
        try {
            BudgetPayloadGuard::assertPlanOnly($item->result);
        } catch (ValidationException) {
            return ProposalReadinessState::Inconsistent;
        }

        return ProposalReadinessState::Aligned;
    }

    /** @return array{state: ProposalReadinessState, reasons: array<int, array{code: string, message: string}>} */
    public function assessItem(ProposalItem $item): array
    {
        if ($item->readiness_state === ProposalReadinessState::ToReview) {
            return $this->result(ProposalReadinessState::ToReview, ProposalReadinessReason::NewSource);
        }
        $source = $item->expense ?? $item->project ?? $item->contract;
        if ($source === null) {
            if ($item->expense_id !== null || $item->project_id !== null || $item->contract_id !== null) {
                return $this->result(ProposalReadinessState::Inconsistent, ProposalReadinessReason::SourceMissing);
            }
            try {
                $this->validateSemanticItem($item);
            } catch (\Throwable) {
                return $this->result(ProposalReadinessState::Inconsistent, ProposalReadinessReason::InvalidAction);
            }

            return ['state' => ProposalReadinessState::Aligned, 'reasons' => []];
        }
        $snapshot = match (true) {
            $source instanceof Expense => ProposalSourceSnapshot::expense($source), $source instanceof Project => ProposalSourceSnapshot::project($source, $item->proposal->exercise_id), $source instanceof Contract => ProposalSourceSnapshot::contract($source, $item->proposal->exercise_id)
        };
        if ((int) $source->revision !== $item->baseline_revision || ProposalSourceSnapshot::fingerprint($snapshot) !== $item->baseline_fingerprint) {
            return $this->result(ProposalReadinessState::ToRealign, ProposalReadinessReason::SourceChanged);
        }
        try {
            $this->validateSemanticItem($item);
        } catch (\Throwable) {
            return $this->result(ProposalReadinessState::Inconsistent, ProposalReadinessReason::InvalidAction);
        }

        return ['state' => ProposalReadinessState::Aligned, 'reasons' => []];
    }

    /** @return array{ready: bool, membership_keys: list<string>, missing_keys: list<string>, impacts: array<int, array<string, mixed>>, blocks: array<int, array{code: string, message: string}>} */
    public function assessProposal(Proposal $proposal): array
    {
        $proposal->loadMissing(['exercise', 'items.expense', 'items.project', 'items.contract', 'items.actions']);
        $catalogKeys = $this->catalog->forExercise($proposal->exercise)->pluck('origin_key')->sort()->values();
        $itemKeys = $proposal->items->map(fn (ProposalItem $item): ?string => match ($item->source_type) {
            ProposalSourceType::Expense => $item->expense_id === null ? null : 'expense:'.$item->expense_id, ProposalSourceType::Project => $item->project_id === null ? null : 'project:'.$item->project_id, ProposalSourceType::Contract => $item->contract_id === null ? null : 'contract:'.$item->contract_id
        })->filter()->sort()->values();
        $missing = $catalogKeys->diff($itemKeys)->values()->all();
        $blocks = [];
        if (! $proposal->exercise->isOpen()) {
            $blocks[] = $this->reason(ProposalReadinessReason::ExerciseClosed);
        }
        if (BudgetSnapshot::query()->where('exercise_id', $proposal->exercise_id)->exists()) {
            $blocks[] = $this->reason(ProposalReadinessReason::BudgetAlreadyExists);
        }
        if ($missing !== []) {
            $blocks[] = $this->reason(ProposalReadinessReason::NewSource);
        }
        foreach ($proposal->items as $item) {
            $assessment = $this->assessItem($item);
            if ($assessment['state'] !== ProposalReadinessState::Aligned) {
                array_push($blocks, ...$assessment['reasons']);
            }
        }
        foreach ($proposal->actions->where('action_type', ProposalActionType::LinkProjectContract) as $action) {
            try {
                ProposalRelationPlan::validate($proposal, $action->payload);
            } catch (\Throwable) {
                $blocks[] = $this->reason(ProposalReadinessReason::InvalidRelation);
            }
        }
        $impacts = ProposalImpactPlan::build($proposal);
        if (collect($impacts)->contains(fn (array $impact): bool => ! $impact['is_open'])) {
            $blocks[] = $this->reason(ProposalReadinessReason::ExerciseClosed);
        }
        $blocks = collect($blocks)->unique('code')->values()->all();

        return ['ready' => $blocks === [], 'membership_keys' => $catalogKeys->all(), 'missing_keys' => $missing, 'impacts' => $impacts, 'blocks' => $blocks];
    }

    private function validateSemanticItem(ProposalItem $item): void
    {
        BudgetPayloadGuard::assertPlanOnly($item->result);
        foreach ($item->actions as $action) {
            ProposalActionPayload::validate($action->action_type, $action->payload);
        }
        match ($item->source_type) {
            ProposalSourceType::Expense => ExpensePlan::validateForApproval($item),
            ProposalSourceType::Project => ProjectPlan::validateForApproval($item),
            ProposalSourceType::Contract => ContractPlan::validateForApproval($item),
        };
    }

    /** @return array{state: ProposalReadinessState, reasons: array<int, array{code: string, message: string}>} */
    private function result(ProposalReadinessState $state, ProposalReadinessReason $reason): array
    {
        return ['state' => $state, 'reasons' => [$this->reason($reason)]];
    }

    /** @return array{code: string, message: string} */
    private function reason(ProposalReadinessReason $reason): array
    {
        return ['code' => $reason->value, 'message' => $reason->message()];
    }
}
