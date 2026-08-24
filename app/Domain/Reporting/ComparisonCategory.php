<?php

namespace App\Domain\Reporting;

enum ComparisonCategory: string
{
    case Unchanged = 'unchanged';
    case Added = 'added';
    case Removed = 'removed';
    case Modified = 'modified';

    public function label(): string
    {
        return match ($this) {
            self::Unchanged => 'Invariato',
            self::Added => 'Aggiunto',
            self::Removed => 'Rimosso',
            self::Modified => 'Modificato',
        };
    }

    public function definition(): string
    {
        return match ($this) {
            self::Unchanged => 'Presente in entrambi i riferimenti senza dimensioni applicabili cambiate.',
            self::Added => 'Presente soltanto nel riferimento finale.',
            self::Removed => 'Presente soltanto nel riferimento iniziale.',
            self::Modified => 'Presente in entrambi i riferimenti con almeno una dimensione applicabile cambiata.',
        };
    }
}
