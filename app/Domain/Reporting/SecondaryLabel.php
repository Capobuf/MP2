<?php

namespace App\Domain\Reporting;

enum SecondaryLabel: string
{
    case Unplanned = 'unplanned';
    case PlannedNotOccurred = 'planned_not_occurred';
    case WithoutActuals = 'without_actuals';
    case Reversed = 'reversed';
    case Cancelled = 'cancelled';
    case Deferred = 'deferred';
    case LateCorrection = 'late_correction';
    case CarryoverChanged = 'carryover_changed';
    case HistoricalAttributionDisputed = 'historical_attribution_disputed';
    case ContractExpiryInSelectedInterval = 'contract_expiry_in_selected_interval';
    case UndefinedExpiry = 'undefined_expiry';

    public function label(): string
    {
        return match ($this) {
            self::Unplanned => 'Non Previsto',
            self::PlannedNotOccurred => 'Previsto e Non Avvenuto',
            self::WithoutActuals => 'Senza Effettivi',
            self::Reversed => 'Stornato',
            self::Cancelled => 'Annullato',
            self::Deferred => 'Rinviato',
            self::LateCorrection => 'Correzione Tardiva',
            self::CarryoverChanged => 'Riporto Variato',
            self::HistoricalAttributionDisputed => 'Imputazione Storica Contestata',
            self::ContractExpiryInSelectedInterval => 'Scadenza Contrattuale entro l’Intervallo Selezionato',
            self::UndefinedExpiry => 'Scadenza Non Definita',
        };
    }
}
