<?php

namespace App\Domain\Company;

enum AuditEventType: string
{
    case CompanyCreated = 'company_created';
    case CapabilityAssigned = 'capability_assigned';
    case CapabilityRevoked = 'capability_revoked';
    case SettingChanged = 'setting_changed';

    public function label(): string
    {
        return match ($this) {
            self::CompanyCreated => 'Azienda creata',
            self::CapabilityAssigned => 'Capacità assegnata',
            self::CapabilityRevoked => 'Capacità revocata',
            self::SettingChanged => 'Impostazione modificata',
        };
    }
}
