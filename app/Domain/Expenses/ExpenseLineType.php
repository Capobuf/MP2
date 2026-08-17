<?php

namespace App\Domain\Expenses;

enum ExpenseLineType: string
{
    case Estimate = 'estimate';
    case Actual = 'actual';

    public function label(): string
    {
        return match ($this) {
            self::Estimate => 'Stima',
            self::Actual => 'Effettivo',
        };
    }

    /** @return array<string, string> */
    public static function options(): array
    {
        return collect(self::cases())
            ->mapWithKeys(fn (self $type): array => [$type->value => $type->label()])
            ->all();
    }
}
