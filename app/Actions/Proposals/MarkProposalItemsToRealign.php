<?php

namespace App\Actions\Proposals;

use App\Domain\Proposals\ProposalReadinessReason;
use App\Models\Proposal;
use App\Models\ProposalItem;

final class MarkProposalItemsToRealign
{
    /**
     * @param  list<int>  $expenseIds
     * @param  list<int>  $projectIds
     * @param  list<int>  $contractIds
     */
    public function execute(int $companyId, array $expenseIds = [], array $projectIds = [], array $contractIds = [], ?int $exceptProposalId = null): void
    {
        if ($expenseIds === [] && $projectIds === [] && $contractIds === []) {
            return;
        }

        $query = ProposalItem::query()
            ->where('company_id', $companyId)
            ->when($exceptProposalId !== null, fn ($builder) => $builder->where('proposal_id', '!=', $exceptProposalId))
            ->whereHas('proposal', fn ($builder) => $builder->where('status', 'draft'))
            ->where(function ($builder) use ($expenseIds, $projectIds, $contractIds): void {
                if ($expenseIds !== []) {
                    $builder->orWhereIn('expense_id', $expenseIds);
                }
                if ($projectIds !== []) {
                    $builder->orWhereIn('project_id', $projectIds);
                }
                if ($contractIds !== []) {
                    $builder->orWhereIn('contract_id', $contractIds);
                }
            });
        $proposalIds = (clone $query)->pluck('proposal_id')->unique();
        if ($proposalIds->isEmpty()) {
            return;
        }

        $query->update([
            'readiness_state' => 'to_realign',
            'readiness_reasons' => json_encode([[
                'code' => ProposalReadinessReason::SourceChanged->value,
                'message' => ProposalReadinessReason::SourceChanged->message(),
            ]], JSON_THROW_ON_ERROR),
            'updated_at' => now(),
        ]);
        Proposal::query()->whereIn('id', $proposalIds)->increment('revision');
    }
}
