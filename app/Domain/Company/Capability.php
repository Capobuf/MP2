<?php

namespace App\Domain\Company;

enum Capability: string
{
    case View = 'visualizza';
    case ManageOperations = 'modifica_operativita';
    case ManageProposals = 'gestisce_proposte';
    case ApproveBudget = 'approva_budget';
    case CloseExercise = 'chiude_esercizio';
    case CorrectClosedExercise = 'corregge_esercizio_chiuso';
    case ManageMasterData = 'gestisce_anagrafiche';
    case ManageSettings = 'gestisce_impostazioni';
    case ManagePermissions = 'gestisce_permessi';

    public function label(): string
    {
        return match ($this) {
            self::View => 'Visualizza',
            self::ManageOperations => 'Modifica operatività',
            self::ManageProposals => 'Gestisce proposte',
            self::ApproveBudget => 'Approva budget',
            self::CloseExercise => 'Chiude esercizio',
            self::CorrectClosedExercise => 'Corregge esercizio chiuso',
            self::ManageMasterData => 'Gestisce anagrafiche',
            self::ManageSettings => 'Gestisce impostazioni',
            self::ManagePermissions => 'Gestisce permessi',
        };
    }

    /** @return array<string, string> */
    public static function options(): array
    {
        return collect(self::cases())
            ->mapWithKeys(fn (self $capability): array => [$capability->value => $capability->label()])
            ->all();
    }
}
