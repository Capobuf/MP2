<?php

namespace App\Domain\Proposals;

enum ProposalActionStatus: string
{
    case Active = 'active';
    case Withdrawn = 'withdrawn';

    public function label(): string
    {
        return match ($this) {
            self::Active => 'Attiva',
            self::Withdrawn => 'Ritirata',
        };
    }
}
