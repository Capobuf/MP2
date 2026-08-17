<?php

namespace App\Domain\Expenses;

final class Decimal
{
    private const INTERNAL_SCALE = 12;

    public static function money(string|int $value): string
    {
        return self::round((string) $value, 2);
    }

    /** @param iterable<int, string|int> $values */
    public static function sum(iterable $values, int $scale = 2): string
    {
        $sum = '0';

        foreach ($values as $value) {
            $sum = bcadd($sum, (string) $value, self::INTERNAL_SCALE);
        }

        return self::round($sum, $scale);
    }

    public static function add(string $left, string $right, int $scale = 2): string
    {
        return self::round(bcadd($left, $right, self::INTERNAL_SCALE), $scale);
    }

    public static function subtract(string $left, string $right, int $scale = 2): string
    {
        return self::round(bcsub($left, $right, self::INTERNAL_SCALE), $scale);
    }

    public static function multiply(string $left, string $right, int $scale = 2): string
    {
        return self::round(bcmul($left, $right, self::INTERNAL_SCALE), $scale);
    }

    public static function compare(string $left, string $right, int $scale = 12): int
    {
        return bccomp($left, $right, $scale);
    }

    public static function round(string $value, int $scale): string
    {
        $increment = '0.'.str_repeat('0', $scale).'5';
        $adjusted = self::compare($value, '0', self::INTERNAL_SCALE) < 0
            ? bcsub($value, $increment, $scale + 1)
            : bcadd($value, $increment, $scale + 1);

        return bcadd($adjusted, '0', $scale);
    }
}
