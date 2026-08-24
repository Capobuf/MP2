<?php

namespace App\Domain\Reporting;

enum ReferenceType: string
{
    case Budget = 'budget';
    case Current = 'current';
    case Closing = 'closing';
    case CurrentKnowledge = 'current_knowledge';

    public function label(): string
    {
        return match ($this) {
            self::Budget => 'Budget',
            self::Current => 'Situazione Corrente',
            self::Closing => 'Chiusura',
            self::CurrentKnowledge => 'Conoscenza Corrente',
        };
    }
}
