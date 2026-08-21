<?php

namespace App\Domain\Proposals;

enum ProposalReadinessReason: string
{
    case NewSource = 'new_source';
    case SourceChanged = 'source_changed';
    case SourceMissing = 'source_missing';
    case InvalidAction = 'invalid_action';
    case InvalidRelation = 'invalid_relation';
    case ExerciseClosed = 'exercise_closed';
    case BudgetAlreadyExists = 'budget_already_exists';

    public function message(): string
    {
        return match ($this) {
            self::NewSource => 'Nuova fonte da prendere in visione.',
            self::SourceChanged => 'La realtà effettiva è cambiata: il riallineamento appartiene alla slice S7.',
            self::SourceMissing => 'La fonte originaria non è più disponibile.',
            self::InvalidAction => 'Una decisione di piano non è più valida.',
            self::InvalidRelation => 'Un collegamento Progetto–Contratto non è più valido.',
            self::ExerciseClosed => 'Un Esercizio interessato non è più aperto.',
            self::BudgetAlreadyExists => 'L’Esercizio possiede già un Budget.',
        };
    }
}
