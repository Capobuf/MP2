<?php

namespace App\Domain\Reporting;

enum ActualReference: string
{
    case Current = 'current';
    case Closing = 'closing';
    case CurrentKnowledge = 'current_knowledge';

    public function label(): string
    {
        return match ($this) {
            self::Current => 'Effettivo Corrente',
            self::Closing => 'Effettivo alla Chiusura',
            self::CurrentKnowledge => 'Effettivo a Conoscenza Corrente',
        };
    }
}
