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
        };
    }
}
