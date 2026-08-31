<?php

namespace App\Models;

use App\Domain\Company\TenantCompanyStatus;
// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Database\Factories\UserFactory;
use Filament\Auth\MultiFactor\App\Concerns\InteractsWithAppAuthentication;
use Filament\Auth\MultiFactor\App\Concerns\InteractsWithAppAuthenticationRecovery;
use Filament\Auth\MultiFactor\App\Contracts\HasAppAuthentication;
use Filament\Auth\MultiFactor\App\Contracts\HasAppAuthenticationRecovery;
use Filament\Models\Contracts\FilamentUser;
use Filament\Models\Contracts\HasTenants;
use Filament\Panel;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Collection;
use Spatie\Permission\Traits\HasRoles;

#[Fillable(['name', 'email', 'password', 'company_id'])]
#[Hidden(['password', 'remember_token'])]
class User extends Authenticatable implements FilamentUser, HasAppAuthentication, HasAppAuthenticationRecovery, HasTenants
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, HasRoles, InteractsWithAppAuthentication, InteractsWithAppAuthenticationRecovery, Notifiable;

    public function canAccessPanel(Panel $panel): bool
    {
        if ($panel->getId() === 'platform') {
            return $this->hasRole('super_admin');
        }

        if ($panel->getId() !== 'admin') {
            return false;
        }

        if ($this->hasRole('super_admin')) {
            return true;
        }

        return $this->company_id !== null
            && $this->tenantCompany()
                ->where('status', TenantCompanyStatus::Active->value)
                ->exists();
    }

    /** @return BelongsTo<Company, $this> */
    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    /** @return BelongsTo<TenantCompany, $this> */
    public function tenantCompany(): BelongsTo
    {
        return $this->belongsTo(TenantCompany::class, 'company_id', 'company_id');
    }

    /** @return HasMany<Attachment, $this> */
    public function uploadedAttachments(): HasMany
    {
        return $this->hasMany(Attachment::class, 'uploaded_by_id');
    }

    /** @return HasMany<ContractRenewalConfiguration, $this> */
    public function contractRenewalConfigurations(): HasMany
    {
        return $this->hasMany(ContractRenewalConfiguration::class, 'created_by_id');
    }

    /** @return HasMany<ContractLifecycleFact, $this> */
    public function contractLifecycleFacts(): HasMany
    {
        return $this->hasMany(ContractLifecycleFact::class, 'created_by_id');
    }

    /** @return HasMany<ContractCondition, $this> */
    public function contractConditions(): HasMany
    {
        return $this->hasMany(ContractCondition::class, 'created_by_id');
    }

    /** @return HasMany<Proposal, $this> */
    public function createdProposals(): HasMany
    {
        return $this->hasMany(Proposal::class, 'created_by_id');
    }

    /** @return HasMany<Proposal, $this> */
    public function approvedProposals(): HasMany
    {
        return $this->hasMany(Proposal::class, 'approved_by_id');
    }

    /** @return HasMany<BudgetSnapshot, $this> */
    public function approvedBudgets(): HasMany
    {
        return $this->hasMany(BudgetSnapshot::class, 'approved_by_id');
    }

    /** @return Collection<int, TenantCompany> */
    public function getTenants(Panel $panel): Collection
    {
        $query = TenantCompany::query()
            ->select('tenant_companies.*')
            ->join('companies', 'companies.id', '=', 'tenant_companies.company_id')
            ->where('tenant_companies.status', TenantCompanyStatus::Active->value)
            ->with('company')
            ->orderBy('companies.name');

        if (! $this->hasRole('super_admin')) {
            $query->where('tenant_companies.company_id', $this->company_id);
        }

        return $query->get();
    }

    public function canAccessTenant(Model $tenant): bool
    {
        return $tenant instanceof TenantCompany
            && TenantCompany::query()
                ->whereKey($tenant->getKey())
                ->where('status', TenantCompanyStatus::Active->value)
                ->exists()
            && ($this->hasRole('super_admin') || $tenant->company_id === $this->company_id);
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
        ];
    }
}
