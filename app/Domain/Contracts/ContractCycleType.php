<?php

namespace App\Domain\Contracts;

enum ContractCycleType: string
{
    case Monthly = 'monthly';
    case Quarterly = 'quarterly';
    case Semiannual = 'semiannual';
    case Annual = 'annual';

    public function months(): int
    {
        return match ($this) {
            self::Monthly => 1,
            self::Quarterly => 3,
            self::Semiannual => 6,
            self::Annual => 12,
        };
    }

    public function label(): string
    {
        return match ($this) {
            self::Monthly => 'Mensile',
            self::Quarterly => 'Trimestrale',
            self::Semiannual => 'Semestrale',
            self::Annual => 'Annuale',
        };
    }

    /** @return array<string, string> */
    public static function options(): array
    {
        return array_column(array_map(
            fn (self $cycle): array => ['value' => $cycle->value, 'label' => $cycle->label()],
            self::cases(),
        ), 'label', 'value');
    }
}
