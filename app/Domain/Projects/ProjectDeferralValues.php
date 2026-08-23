<?php

namespace App\Domain\Projects;

use App\Domain\Expenses\Decimal;

final class ProjectDeferralValues
{
    public static function residual(string $allocation, string $actual): string
    {
        $difference = Decimal::subtract($allocation, $actual);

        return Decimal::compare($difference, '0.00') < 0 ? '0.00' : $difference;
    }

    public static function maximumTransferable(string $allocation, string $actual): string
    {
        $residual = self::residual($allocation, $actual);

        return Decimal::compare($residual, $allocation) > 0
            ? Decimal::money($allocation)
            : $residual;
    }
}
