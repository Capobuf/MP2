<?php

namespace App\Domain\Proposals;

enum ProposalPurpose: string
{
    case InitialBudget = 'initial_budget';
    case Revision = 'revision';

    public function label(): string
    {
        return match ($this) {
            self::InitialBudget => 'Budget iniziale',
            self::Revision => 'Revisione',
        };
    }
}
