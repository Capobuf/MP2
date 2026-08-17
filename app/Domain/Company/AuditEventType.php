<?php

namespace App\Domain\Company;

enum AuditEventType: string
{
    case CompanyCreated = 'company_created';
    case CapabilityAssigned = 'capability_assigned';
    case CapabilityRevoked = 'capability_revoked';
    case SettingChanged = 'setting_changed';
    case SupplierCreated = 'supplier_created';
    case SupplierUpdated = 'supplier_updated';
    case SupplierArchived = 'supplier_archived';
    case SupplierRestored = 'supplier_restored';
    case SupplierContactCreated = 'supplier_contact_created';
    case SupplierContactUpdated = 'supplier_contact_updated';
    case CostCenterCreated = 'cost_center_created';
    case CostCenterRenamed = 'cost_center_renamed';
    case CostCenterArchived = 'cost_center_archived';
    case CostCenterRestored = 'cost_center_restored';
    case ExerciseCreated = 'exercise_created';
    case ExpenseCreated = 'expense_created';
    case ExpenseUpdated = 'expense_updated';
    case ExpenseMovedOrReclassified = 'expense_moved_or_reclassified';
    case ExpenseReversed = 'expense_reversed';
    case ExpenseRestored = 'expense_restored';
    case ExpenseLineCreated = 'expense_line_created';
    case ExpenseLineUpdated = 'expense_line_updated';
    case ExpenseLineAnnulled = 'expense_line_annulled';
    case ExpenseLineRestored = 'expense_line_restored';
    case ProjectCreated = 'project_created';
    case ProjectUpdated = 'project_updated';
    case ProjectTransitionPlanned = 'project_transition_planned';
    case ProjectTransitionEffective = 'project_transition_effective';
    case ProjectTransitionAnnulled = 'project_transition_annulled';
    case ProjectTransitionReplaced = 'project_transition_replaced';
    case ProjectClassificationChanged = 'project_classification_changed';
    case ProjectOverspendCreated = 'project_overspend_created';
    case ProjectOverspendIncreased = 'project_overspend_increased';
    case ProjectArchived = 'project_archived';
    case ProjectRestored = 'project_restored';

    public function label(): string
    {
        return match ($this) {
            self::CompanyCreated => 'Azienda creata',
            self::CapabilityAssigned => 'Capacità assegnata',
            self::CapabilityRevoked => 'Capacità revocata',
            self::SettingChanged => 'Impostazione modificata',
            self::SupplierCreated => 'Fornitore creato',
            self::SupplierUpdated => 'Fornitore modificato',
            self::SupplierArchived => 'Fornitore archiviato',
            self::SupplierRestored => 'Fornitore ripristinato',
            self::SupplierContactCreated => 'Referente creato',
            self::SupplierContactUpdated => 'Referente modificato',
            self::CostCenterCreated => 'Centro di Costo creato',
            self::CostCenterRenamed => 'Centro di Costo rinominato',
            self::CostCenterArchived => 'Centro di Costo archiviato',
            self::CostCenterRestored => 'Centro di Costo ripristinato',
            self::ExerciseCreated => 'Esercizio creato',
            self::ExpenseCreated => 'Spesa creata',
            self::ExpenseUpdated => 'Spesa modificata',
            self::ExpenseMovedOrReclassified => 'Spesa spostata o riclassificata',
            self::ExpenseReversed => 'Spesa stornata',
            self::ExpenseRestored => 'Spesa ripristinata',
            self::ExpenseLineCreated => 'Riga creata',
            self::ExpenseLineUpdated => 'Riga modificata',
            self::ExpenseLineAnnulled => 'Riga annullata',
            self::ExpenseLineRestored => 'Riga ripristinata',
            self::ProjectCreated => 'Progetto creato',
            self::ProjectUpdated => 'Progetto modificato',
            self::ProjectTransitionPlanned => 'Transizione progetto pianificata',
            self::ProjectTransitionEffective => 'Transizione progetto efficace',
            self::ProjectTransitionAnnulled => 'Transizione progetto annullata',
            self::ProjectTransitionReplaced => 'Transizione progetto sostituita',
            self::ProjectClassificationChanged => 'Classificazione progetto modificata',
            self::ProjectOverspendCreated => 'Sovraspesa progetto creata',
            self::ProjectOverspendIncreased => 'Sovraspesa progetto aumentata',
            self::ProjectArchived => 'Progetto archiviato',
            self::ProjectRestored => 'Progetto ripristinato',
        };
    }
}
