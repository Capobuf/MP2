<?php

namespace App\Domain\Projects;

use App\Domain\Expenses\Decimal;

final class ProjectOverspend
{
    public static function detect(string $before, string $after): ProjectOverspendResult
    {
        $beforePositive = Decimal::compare($before, '0.00') > 0;
        $afterPositive = Decimal::compare($after, '0.00') > 0;

        if (! $beforePositive && $afterPositive) {
            return ProjectOverspendResult::Created;
        }

        if ($beforePositive && $afterPositive && Decimal::compare($after, $before) > 0) {
            return ProjectOverspendResult::Increased;
        }

        return ProjectOverspendResult::None;
    }
}
