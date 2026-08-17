<?php

namespace App\Domain\Projects;

use Carbon\CarbonImmutable;
use DateTimeInterface;

enum ProjectTransitionStatus: string
{
    case Planned = 'planned';
    case Effective = 'effective';
    case Annulled = 'annulled';

    public static function for(string|DateTimeInterface $effectiveDate, string|DateTimeInterface|null $annulledAt, CarbonImmutable $today): self
    {
        if ($annulledAt !== null) {
            return self::Annulled;
        }

        return CarbonImmutable::parse($effectiveDate)->startOfDay()->greaterThan($today->startOfDay())
            ? self::Planned
            : self::Effective;
    }

    public function label(): string
    {
        return match ($this) {
            self::Planned => 'Pianificata',
            self::Effective => 'Efficace',
            self::Annulled => 'Annullata',
        };
    }
}
