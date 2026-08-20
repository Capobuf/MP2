<?php

namespace App\Domain\Contracts;

enum ContractState: string
{
    case Planned = 'planned';
    case Active = 'active';
    case Cessated = 'cessated';
    case Cancelled = 'cancelled';

    public function label(): string
    {
        return match ($this) {
            self::Planned => 'Pianificato',
            self::Active => 'Attivo',
            self::Cessated => 'Cessato',
            self::Cancelled => 'Annullato',
        };
    }

    /** @return array<string, string> */
    public static function options(): array
    {
        return array_column(array_map(
            fn (self $state): array => ['value' => $state->value, 'label' => $state->label()],
            self::cases(),
        ), 'label', 'value');
    }
}
