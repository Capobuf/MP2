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
            self::OverspendNoteRequired => 'Nota di Sovraspesa Obbligatoria',
            self::UnclassifiedClosingPolicy => 'Policy Non Classificato alla Chiusura',
            self::Timezone => 'Fuso Orario Aziendale',
        };
    }
}
