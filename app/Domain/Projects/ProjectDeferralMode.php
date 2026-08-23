<?php

namespace App\Domain\Projects;

enum ProjectDeferralMode: string
{
    case None = 'none';
    case Carryover = 'carryover';
    case Reprogramming = 'reprogramming';

    public function label(): string
    {
        return match ($this) {
            self::None => 'Nessuna',
            self::Carryover => 'Riporto',
            self::Reprogramming => 'Riprogrammazione',
        };
    }
}
