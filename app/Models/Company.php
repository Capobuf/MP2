<?php

namespace App\Models;

use App\Domain\Company\ClosingUnclassifiedPolicy;
use Database\Factories\CompanyFactory;
use Filament\Models\Contracts\HasName;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['name', 'timezone', 'overspend_note_required', 'unclassified_closing_policy'])]
class Company extends Model implements HasName
{
    /** @use HasFactory<CompanyFactory> */
    use HasFactory;

    /** @return HasMany<CompanyCapability, $this> */
    public function capabilities(): HasMany
    {
        return $this->hasMany(CompanyCapability::class);
    }

    /** @return HasMany<AuditEvent, $this> */
    public function auditEvents(): HasMany
    {
        return $this->hasMany(AuditEvent::class);
    }

    /** @return HasMany<Supplier, $this> */
    public function suppliers(): HasMany
    {
        return $this->hasMany(Supplier::class);
    }

    /** @return HasMany<CostCenter, $this> */
    public function costCenters(): HasMany
    {
        return $this->hasMany(CostCenter::class);
    }

    public function getFilamentName(): string
    {
        return $this->name;
    }

    public function closingUnclassifiedPolicy(): ClosingUnclassifiedPolicy
    {
        $value = $this->getRawOriginal('unclassified_closing_policy');

        if (! is_string($value)) {
            throw new \UnexpectedValueException('Invalid persisted unclassified closing policy.');
        }

        return ClosingUnclassifiedPolicy::from($value);
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'overspend_note_required' => 'boolean',
            'unclassified_closing_policy' => ClosingUnclassifiedPolicy::class,
        ];
    }
}
