<?php

namespace App\Domain\Company;

enum Setting: string
{
    case OverspendNoteRequired = 'overspend_note_required';
    case UnclassifiedClosingPolicy = 'unclassified_closing_policy';
    case Timezone = 'timezone';

    public function label(): string
    {
        return match ($this) {
            self::OverspendNoteRequired => 'Nota di sovraspesa obbligatoria',
            self::UnclassifiedClosingPolicy => 'Policy Non classificato alla Chiusura',
            self::Timezone => 'Fuso orario aziendale',
        };
    }
}
