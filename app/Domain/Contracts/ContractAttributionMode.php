<?php

namespace App\Domain\Contracts;

enum ContractAttributionMode: string
{
    case CycleStart = 'cycle_start';
    case CycleEnd = 'cycle_end';

    public function label(): string
    {
        return match ($this) {
            self::CycleStart => 'Inizio Ciclo',
            self::CycleEnd => 'Fine Ciclo',
        };
    }

    /** @return array<string, string> */
    public static function options(): array
    {
        return array_column(array_map(
            fn (self $mode): array => ['value' => $mode->value, 'label' => $mode->label()],
            self::cases(),
        ), 'label', 'value');
    }
}
