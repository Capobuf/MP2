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
            self::Unplanned => 'Non previsto',
            self::PlannedNotOccurred => 'Previsto e non avvenuto',
            self::WithoutActuals => 'Senza Effettivi',
            self::Reversed => 'Stornato',
            self::Cancelled => 'Annullato',
            self::Deferred => 'Rinviato',
            self::LateCorrection => 'Correzione tardiva',
            self::CarryoverChanged => 'Riporto variato',
            self::HistoricalAttributionDisputed => 'Imputazione storica contestata',
            self::ContractExpiryInSelectedInterval => 'Scadenza contrattuale entro l’intervallo selezionato',
            self::UndefinedExpiry => 'Scadenza non definita',
        };
    }
}
