<?php

namespace App\Domain\Company;

enum TenantCompanyStatus: string
{
    case Active = 'active';
    case Archived = 'archived';

    public function label(): string
    {
        return match ($this) {
            self::Active => 'Attivo',
            self::Archived => 'Archiviato',
        };
    }
}
