<?php

namespace App\Domain\LateCorrections;

enum HistoricalErrorKind: string
{
    case CostCenter = 'cost_center';
    case Supplier = 'supplier';
    case Project = 'project';
    case Contract = 'contract';
    case Container = 'container';
    case Exercise = 'exercise';
    case HistoricalState = 'historical_state';
    case Carryover = 'carryover';
    case AccidentalClosing = 'accidental_closing';

    public function label(): string
    {
        return match ($this) {
            self::CostCenter => 'Centro di Costo',
            self::Supplier => 'Fornitore',
            self::Project => 'Progetto',
            self::Contract => 'Contratto',
            self::Container => 'Contenitore',
            self::Exercise => 'Esercizio',
            self::HistoricalState => 'Stato Storico',
            self::Carryover => 'Riporto',
            self::AccidentalClosing => 'Chiusura Accidentale',
        };
    }

    /** @return array<string, string> */
    public static function options(): array
    {
        return collect(self::cases())
            ->mapWithKeys(fn (self $kind): array => [$kind->value => $kind->label()])
            ->all();
    }
}
