<?php

namespace App\Models;

use App\Domain\Company\Capability;
// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Database\Factories\UserFactory;
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
class User extends Authenticatable implements FilamentUser, HasTenants
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable;

    public function canAccessPanel(Panel $panel): bool
    {
        return $this->is_platform_admin || $this->capabilities()
            ->where('capability', Capability::View->value)
            ->exists();
    }

    /** @return HasMany<CompanyCapability, $this> */
    public function capabilities(): HasMany
    {
        return $this->hasMany(CompanyCapability::class);
    }

    /** @return Collection<int, Company> */
    public function getTenants(Panel $panel): Collection
    {
        return Company::query()
            ->whereHas('capabilities', fn ($query) => $query
                ->where('user_id', $this->getKey())
                ->where('capability', Capability::View->value))
            ->orderBy('name')
            ->get();
    }

    public function canAccessTenant(Model $tenant): bool
    {
        return $tenant instanceof Company
            && $this->hasCapability($tenant, Capability::View);
    }

    public function hasCapability(Company $company, Capability $capability): bool
    {
        return $this->capabilities()
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
