<?php

namespace App\Domain\Reporting;

enum ReportKind: string
{
    case AnnualExecutive = 'annual_executive';
    case BudgetActual = 'budget_actual';
    case BudgetCurrentAllocation = 'budget_current_allocation';
    case OperationalVariance = 'operational_variance';
    case BudgetVersions = 'budget_versions';
    case Exercises = 'exercises';
    case Carryovers = 'carryovers';
    case Contracts = 'contracts';
    case Projects = 'projects';
    case Suppliers = 'suppliers';

    public function label(): string
    {
        return match ($this) {
            self::AnnualExecutive => 'Vista Annuale Esecutiva',
            self::BudgetActual => 'Budget vs Actual',
            self::BudgetCurrentAllocation => 'Budget vs Allocato Corrente',
            self::OperationalVariance => 'Scostamento Operativo',
            self::BudgetVersions => 'Versioni Budget',
            self::Exercises => 'Confronto Esercizi',
            self::Carryovers => 'Riporti',
            self::Contracts => 'Contratti',
            self::Projects => 'Progetti',
            self::Suppliers => 'Fornitori',
        };
    }

    public function isComparison(): bool
    {
        return in_array($this, [self::BudgetActual, self::BudgetCurrentAllocation, self::BudgetVersions, self::Exercises], true);
    }
}
