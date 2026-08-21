<?php

namespace App\Domain\Proposals;

enum ProposalReadinessState: string
{
    case Aligned = 'aligned';
    case ToReview = 'to_review';
    case ToRealign = 'to_realign';
    case Inconsistent = 'inconsistent';

    public function label(): string
    {
        return match ($this) {
            self::Aligned => 'Allineato',
            self::ToReview => 'Da prendere in visione',
            self::ToRealign => 'Da riallineare',
            self::Inconsistent => 'Incoerente',
        };
    }
}
