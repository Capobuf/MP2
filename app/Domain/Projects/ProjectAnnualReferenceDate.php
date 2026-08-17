<?php

namespace App\Domain\Projects;

use Carbon\CarbonImmutable;

final class ProjectAnnualReferenceDate
{
    public static function forYear(int $year, CarbonImmutable $today): CarbonImmutable
    {
        if ($year < $today->year) {
            return CarbonImmutable::create($year, 12, 31, 0, 0, 0, $today->timezone);
        }

        if ($year > $today->year) {
            return CarbonImmutable::create($year, 1, 1, 0, 0, 0, $today->timezone);
        }

        return $today->startOfDay();
    }
}
