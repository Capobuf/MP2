<?php

namespace App\Domain\Reporting;

enum ModificationDimension: string
{
    case AllocationOrEstimate = 'allocation_or_estimate';
    case Actual = 'actual';
    case Carryover = 'carryover';
    case CostCenter = 'cost_center';
    case Supplier = 'supplier';
    case Container = 'container';
    case StateOrTransitions = 'state_or_transitions';
    case ContractEconomics = 'contract_economics';
    case DeadlineRenewalTermination = 'deadline_renewal_termination';
    case ArchiveOrReversal = 'archive_or_reversal';
    case InformativeRelations = 'informative_relations';

    public function label(): string
    {
        return match ($this) {
            self::AllocationOrEstimate => 'Allocato o Stima',
            self::Actual => 'Effettivo',
            self::Carryover => 'Riporto',
            self::CostCenter => 'Centro di Costo',
            self::Supplier => 'Fornitore',
            self::Container => 'Contenitore',
            self::StateOrTransitions => 'Stato o Transizioni',
            self::ContractEconomics => 'Economica Contrattuale',
            self::DeadlineRenewalTermination => 'Scadenza, Rinnovo o Cessazione',
            self::ArchiveOrReversal => 'Archivio o Storno',
            self::InformativeRelations => 'Relazioni Informative',
        };
    }
}
