<?php

namespace App\Domain\Proposals;

enum ProposalRealignmentChoice: string
{
    case Reload = 'reload';
    case Keep = 'keep';
    case Manual = 'manual';

    public function label(): string
    {
        return match ($this) {
            self::Reload => 'Ricarica Realtà',
            self::Keep => 'Mantieni Proposta',
            self::Manual => 'Rivedi Manualmente',
        };
    }
}
