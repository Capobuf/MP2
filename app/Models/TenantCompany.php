<?php

namespace App\Models;

use App\Domain\Company\TenantCompanyStatus;
use Filament\Models\Contracts\HasCurrentTenantLabel;
use Filament\Models\Contracts\HasName;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['company_id', 'status'])]
class TenantCompany extends Model implements HasCurrentTenantLabel, HasName
{
    protected $primaryKey = 'company_id';

    public $incrementing = false;

    protected $keyType = 'int';

    /** @return BelongsTo<Company, $this> */
    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class, 'company_id');
    }

    /** @return HasMany<User, $this> */
    public function users(): HasMany
    {
        return $this->hasMany(User::class, 'company_id', 'company_id');
    }

    /** @return HasMany<BudgetSnapshot, $this> */
    public function budgetSnapshots(): HasMany
    {
        return $this->hasMany(BudgetSnapshot::class, 'company_id', 'company_id');
    }

    /** @return HasMany<ClosingSnapshot, $this> */
    public function closingSnapshots(): HasMany
    {
        return $this->hasMany(ClosingSnapshot::class, 'company_id', 'company_id');
    }

    /** @return HasMany<Contract, $this> */
    public function contracts(): HasMany
    {
        return $this->hasMany(Contract::class, 'company_id', 'company_id');
    }

    /** @return HasMany<CostCenter, $this> */
    public function costCenters(): HasMany
    {
        return $this->hasMany(CostCenter::class, 'company_id', 'company_id');
    }

    /** @return HasMany<Exercise, $this> */
    public function exercises(): HasMany
    {
        return $this->hasMany(Exercise::class, 'company_id', 'company_id');
    }

    /** @return HasMany<Expense, $this> */
    public function expenses(): HasMany
    {
        return $this->hasMany(Expense::class, 'company_id', 'company_id');
    }

    /** @return HasMany<Project, $this> */
    public function projects(): HasMany
    {
        return $this->hasMany(Project::class, 'company_id', 'company_id');
    }

    /** @return HasMany<Proposal, $this> */
    public function proposals(): HasMany
    {
        return $this->hasMany(Proposal::class, 'company_id', 'company_id');
    }

    /** @return HasMany<Supplier, $this> */
    public function suppliers(): HasMany
    {
        return $this->hasMany(Supplier::class, 'company_id', 'company_id');
    }

    public function getFilamentName(): string
    {
        return $this->company->name;
    }

    public function getCurrentTenantLabel(): string
    {
        return 'Azienda';
    }

    public function status(): TenantCompanyStatus
    {
        $status = $this->getAttribute('status');

        if (! $status instanceof TenantCompanyStatus) {
            throw new \UnexpectedValueException('Invalid persisted Tenant Company status.');
        }

        return $status;
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['status' => TenantCompanyStatus::class];
    }
}
