<?php

namespace App\Domain\Proposals;

use App\Models\ProposalAction;
use App\Models\ProposalItem;
use Illuminate\Support\Collection;

final class ProposalActionReplay
{
    /**
     * @param  array<string, mixed>  $freshSnapshot
     * @param  iterable<int, ProposalAction>|null  $actions
     * @return array<string, mixed>
     */
    public function replay(ProposalItem $item, array $freshSnapshot, ?iterable $actions = null): array
    {
        $item->loadMissing(['proposal', 'expense', 'project', 'contract']);
        $working = clone $item;
        $working->setRelation('proposal', $item->proposal);
        $working->setRelation('expense', $item->expense);
        $working->setRelation('project', $item->project);
        $working->setRelation('contract', $item->contract);
        $working->baseline = $freshSnapshot;
        $working->result = (array) data_get($freshSnapshot, 'plan_baseline', []);

        $selected = $actions === null
            ? $this->touchingActions($item, $item->proposal->actions()->get())
            : $this->touchingActions($item, $actions);

        foreach ($selected as $action) {
            $payload = ProposalActionPayload::validate($action->action_type, $action->payload);

            if ($action->action_type === ProposalActionType::LinkProjectContract) {
                ProposalRelationPlan::validate($item->proposal, $payload);

                continue;
            }

            $working->result = match ($working->source_type) {
                ProposalSourceType::Expense => ExpensePlan::apply($working, $action->action_type, $payload),
                ProposalSourceType::Project => ProjectPlan::apply($item->proposal, $working, $action->action_type, $payload),
                ProposalSourceType::Contract => ContractPlan::apply($working, $action->action_type, $payload),
            };
        }

        match ($working->source_type) {
            ProposalSourceType::Expense => ExpensePlan::validateForApproval($working),
            ProposalSourceType::Project => ProjectPlan::validateForApproval($working),
            ProposalSourceType::Contract => ContractPlan::validateForApproval($working),
        };

        return $working->result;
    }

    /**
     * @param  iterable<int, ProposalAction>  $actions
     * @return Collection<int, ProposalAction>
     */
    public function touchingActions(ProposalItem $item, iterable $actions): Collection
    {
        $originKey = match ($item->source_type) {
            ProposalSourceType::Expense => $item->expense_id === null ? null : 'expense:'.$item->expense_id,
            ProposalSourceType::Project => $item->project_id === null ? null : 'project:'.$item->project_id,
            ProposalSourceType::Contract => $item->contract_id === null ? null : 'contract:'.$item->contract_id,
        };

        return collect($actions)
            ->filter(fn (ProposalAction $action): bool => $action->status === ProposalActionStatus::Active)
            ->filter(function (ProposalAction $action) use ($item, $originKey): bool {
                if ($action->proposal_item_id === $item->id) {
                    return true;
                }

                return collect($action->payload)->contains(
                    fn (mixed $value): bool => (is_string($value) || $value instanceof \Stringable)
                        && ((string) $value === (string) $item->proposal_item_id
                            || ($originKey !== null && (string) $value === $originKey)),
                );
            })
            ->sortBy('sequence')
            ->values();
    }
}
