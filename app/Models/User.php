<?php

namespace App\Models;

use App\Domain\Company\Capability;
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
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Collection;

#[Fillable(['name', 'email', 'password', 'is_platform_admin'])]
#[Hidden(['password', 'remember_token'])]
class User extends Authenticatable implements FilamentUser, HasAppAuthentication, HasAppAuthenticationRecovery, HasTenants
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, InteractsWithAppAuthentication, InteractsWithAppAuthenticationRecovery, Notifiable;

    public function canAccessPanel(Panel $panel): bool
    {
        if ($panel->getId() === 'platform') {
            return $this->is_platform_admin;
        }

        if ($panel->getId() !== 'admin') {
            return false;
        }

        return $this->is_platform_admin || $this->capabilities()
            ->where('capability', Capability::View->value)
            ->whereHas('company.tenantCompany', fn ($query) => $query
                ->where('status', TenantCompanyStatus::Active->value))
            ->exists();
    }

    /** @return HasMany<CompanyCapability, $this> */
    public function capabilities(): HasMany
    {
        return $this->hasMany(CompanyCapability::class);
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
        return TenantCompany::query()
            ->select('tenant_companies.*')
            ->join('companies', 'companies.id', '=', 'tenant_companies.company_id')
            ->where('tenant_companies.status', TenantCompanyStatus::Active->value)
            ->whereHas('company.capabilities', fn ($query) => $query
                ->where('user_id', $this->getKey())
                ->where('capability', Capability::View->value))
            ->with('company')
            ->orderBy('companies.name')
            ->get();
    }

    public function canAccessTenant(Model $tenant): bool
    {
        return $tenant instanceof TenantCompany
            && $tenant->status() === TenantCompanyStatus::Active
            && $tenant->company instanceof Company
            && $this->hasCapability($tenant->company, Capability::View);
    }

    public function hasCapability(Company $company, Capability $capability): bool
    {
        return $company->tenantCompany()
            ->where('status', TenantCompanyStatus::Active->value)
            ->exists()
            && $this->capabilities()
                ->where('company_id', $company->getKey())
                ->where('capability', $capability->value)
                ->exists();
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
            'is_platform_admin' => 'boolean',
            'password' => 'hashed',
        ];
    }
}
