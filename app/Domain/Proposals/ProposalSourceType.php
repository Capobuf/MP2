<?php

namespace App\Domain\Proposals;

enum ProposalSourceType: string
{
    case Expense = 'expense';
    case Project = 'project';
    case Contract = 'contract';

    public function label(): string
    {
        return match ($this) {
            self::Expense => 'Spesa',
            self::Project => 'Progetto',
            self::Contract => 'Contratto',
        };
    }
}
