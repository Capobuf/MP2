<?php

namespace App\Domain\Proposals;

enum ProposalStatus: string
{
    case Draft = 'draft';
    case Approved = 'approved';
    case Discarded = 'discarded';

    public function label(): string
    {
        return match ($this) {
            self::Draft => 'Bozza',
            self::Approved => 'Approvata',
            self::Discarded => 'Scartata',
        };
    }
}
