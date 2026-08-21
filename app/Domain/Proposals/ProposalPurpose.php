<?php

namespace App\Domain\Proposals;

enum ProposalPurpose: string
{
    case InitialBudget = 'initial_budget';

    public function label(): string
    {
        return 'Budget iniziale';
    }
}
