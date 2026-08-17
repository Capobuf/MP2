<?php

namespace App\Domain\Company;

enum ClosingUnclassifiedPolicy: string
{
    case Warning = 'warning';
    case Blocking = 'blocking';

    public function label(): string
    {
        return match ($this) {
            self::Warning => 'Avviso',
            self::Blocking => 'Blocco',
        };
    }

    /** @return array<string, string> */
    public static function options(): array
    {
        return collect(self::cases())
            ->mapWithKeys(fn (self $policy): array => [$policy->value => $policy->label()])
            ->all();
    }
}
