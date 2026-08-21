<?php

namespace App\Domain\Proposals;

use App\Models\Proposal;
use App\Models\ProposalItem;
use Illuminate\Validation\ValidationException;

final class ProposalItemReference
{
    public static function item(Proposal $proposal, string $proposalItemId, string $sourceType): ProposalItem
    {
        $item = ProposalItem::query()->where('proposal_id', $proposal->id)->where('proposal_item_id', $proposalItemId)->first();
        if ($item === null || $item->source_type->value !== $sourceType) {
            throw ValidationException::withMessages(['proposal_item_id' => 'Riferimento non compatibile o esterno alla Proposta.']);
        }

        return $item;
    }
}
