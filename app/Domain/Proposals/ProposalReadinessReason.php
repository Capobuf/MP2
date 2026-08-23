<?php

namespace App\Domain\Proposals;

use Illuminate\Validation\ValidationException;

enum ProposalReadinessReason: string
{
    case NewSource = 'new_source';
    case SourceChanged = 'source_changed';
    case BudgetAlreadyExists = 'budget_already_exists';
    case CarryoverAboveLimit = 'carryover_above_limit';
    case ReprogrammingAboveAvailable = 'reprogramming_above_available';
    case ReprogrammingUnbalanced = 'reprogramming_unbalanced';
    case DeferralModesConflict = 'deferral_modes_conflict';
    case ActualMutation = 'actual_mutation';
    case ExpenseDualOwner = 'expense_dual_owner';
    case ManualContractEstimate = 'manual_contract_estimate';
    case InvalidContractCondition = 'invalid_contract_condition';
    case IncompatibleTransition = 'incompatible_transition';
    case ClosedExerciseAction = 'closed_exercise_action';
    case ArchivedSourceWithoutRestore = 'archived_source_without_restore';
    case MissingRequiredData = 'missing_required_data';
    case StaleConcurrentAction = 'stale_concurrent_action';
    case InvalidRelation = 'invalid_relation';
    case PartialMultiExerciseEffect = 'partial_multi_exercise_effect';

    /** @return list<self> */
    public static function inconsistencies(): array
    {
        return [
            self::CarryoverAboveLimit,
            self::ReprogrammingAboveAvailable,
            self::ReprogrammingUnbalanced,
            self::DeferralModesConflict,
            self::ActualMutation,
            self::ExpenseDualOwner,
            self::ManualContractEstimate,
            self::InvalidContractCondition,
            self::IncompatibleTransition,
            self::ClosedExerciseAction,
            self::ArchivedSourceWithoutRestore,
            self::MissingRequiredData,
            self::StaleConcurrentAction,
            self::InvalidRelation,
            self::PartialMultiExerciseEffect,
        ];
    }

    /** @return list<self> */
    public static function fromValidation(ValidationException $exception): array
    {
        $matches = [];

        foreach ($exception->errors() as $field => $messages) {
            $text = mb_strtolower($field.' '.implode(' ', $messages));
            $reasons = match (true) {
                str_contains($text, 'riporto') => [self::CarryoverAboveLimit],
                str_contains($text, 'riprogram') && str_contains($text, 'bilanc') => [self::ReprogrammingUnbalanced],
                str_contains($text, 'riprogram') => [self::ReprogrammingAboveAvailable],
                str_contains($text, 'differ') || str_contains($text, 'rinvio') => [self::DeferralModesConflict],
                str_contains($text, 'effettiv') || str_contains($text, 'actual') => [self::ActualMutation],
                str_contains($text, 'un solo progetto') || str_contains($text, 'doppio contenitore') => [self::ExpenseDualOwner],
                str_contains($text, 'stima') && str_contains($text, 'contratt') => [self::ManualContractEstimate],
                str_contains($text, 'condizion') || str_contains($text, 'prorata') || in_array($field, ['valid_from', 'valid_to'], true) => [self::InvalidContractCondition],
                str_contains($text, 'transizion') || in_array($field, ['from_state', 'to_state', 'transitions'], true) => [self::IncompatibleTransition],
                str_contains($text, 'esercizio') && (str_contains($text, 'aperto') || str_contains($text, 'chiuso')) => [self::ClosedExerciseAction],
                str_contains($text, 'archiv') || $field === 'archived' => [self::ArchivedSourceWithoutRestore],
                str_contains($text, 'obbligatori') || str_contains($text, 'mancant') => [self::MissingRequiredData],
                str_contains($text, 'collegamento') || str_contains($text, 'relation') || str_contains($field, 'origin_key') => [self::InvalidRelation],
                str_contains($text, 'parzial') => [self::PartialMultiExerciseEffect],
                default => [self::StaleConcurrentAction],
            };
            array_push($matches, ...$reasons);
        }

        return collect(self::inconsistencies())
            ->filter(fn (self $reason): bool => in_array($reason, $matches, true))
            ->values()
            ->all();
    }

    public function message(): string
    {
        return match ($this) {
            self::NewSource => 'Nuova fonte da prendere in visione.',
            self::SourceChanged => 'La realtà effettiva è cambiata: riallineare l’intera sorgente.',
            self::BudgetAlreadyExists => 'L’Esercizio possiede già un Budget.',
            self::CarryoverAboveLimit => 'Il Riporto supera il limite disponibile.',
            self::ReprogrammingAboveAvailable => 'La Riprogrammazione supera l’importo disponibile.',
            self::ReprogrammingUnbalanced => 'La Riprogrammazione non è bilanciata.',
            self::DeferralModesConflict => 'Le modalità di rinvio sono incompatibili.',
            self::ActualMutation => 'La decisione modificherebbe valori o classificazioni con Effettivi.',
            self::ExpenseDualOwner => 'La Spesa avrebbe più di un contenitore economico.',
            self::ManualContractEstimate => 'La Stima derivata dal Contratto non può essere modificata manualmente.',
            self::InvalidContractCondition => 'La condizione contrattuale proposta non è valida.',
            self::IncompatibleTransition => 'La transizione proposta non è compatibile con lo stato corrente.',
            self::ClosedExerciseAction => 'La decisione tenterebbe di modificare un Esercizio Chiuso.',
            self::ArchivedSourceWithoutRestore => 'La sorgente archiviata richiede un ripristino esplicito.',
            self::MissingRequiredData => 'Mancano dati obbligatori per applicare la decisione.',
            self::StaleConcurrentAction => 'Una modifica concorrente rende la decisione non più applicabile.',
            self::InvalidRelation => 'Il collegamento informativo proposto non è valido.',
            self::PartialMultiExerciseEffect => 'L’effetto multi-Esercizio non può essere applicato integralmente.',
        };
    }
}
