<?php

namespace App\Models;

use App\Domain\CostCenters\CostCenterHierarchy;
use Database\Factories\CostCenterFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['company_id', 'parent_id', 'name', 'archived_at'])]
class CostCenter extends Model
{
    /** @use HasFactory<CostCenterFactory> */
    use HasFactory;

    protected static function booted(): void
    {
        static::saving(function (self $costCenter): void {
            if (! $costCenter->isDirty('parent_id')) {
                return;
            }

            CostCenterHierarchy::forCompany((int) $costCenter->company_id)->assertCanAssignParent(
                $costCenter->exists ? (int) $costCenter->id : null,
                (int) $costCenter->company_id,
                $costCenter->parent_id === null ? null : (int) $costCenter->parent_id,
            );
        });
        static::deleting(function (): never {
            throw new \LogicException('Persisted master data cannot be deleted.');
        });
    }

    /** @return BelongsTo<Company, $this> */
    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    /** @return BelongsTo<CostCenter, $this> */
    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    /** @return HasMany<CostCenter, $this> */
    public function children(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id');
    }

    /** @return BelongsTo<TenantCompany, $this> */
    public function tenantCompany(): BelongsTo
    {
        return $this->belongsTo(TenantCompany::class, 'company_id', 'company_id');
    }

    /** @param Builder<self> $query */
    public function scopeActive(Builder $query): void
    {
        $query->whereNull('archived_at');
    }

    /** @param Builder<self> $query */
    public function scopeArchived(Builder $query): void
    {
        $query->whereNotNull('archived_at');
    }

    public function isArchived(): bool
    {
        return $this->archived_at !== null;
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['archived_at' => 'datetime'];
    }
}
