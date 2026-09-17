<?php

namespace App\Actions\Tenancy;

use App\Domain\Company\TenantCompanyStatus;
use App\Models\Company;
use App\Models\PlatformLifecycleEvent;
use App\Models\TenantCompany;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class ArchiveTenantCompany
{
    public function execute(User $actor, TenantCompany $tenant): TenantCompany
    {
        Gate::forUser($actor)->authorize('archive', $tenant);

        return DB::transaction(function () use ($actor, $tenant): TenantCompany {
            $company = Company::query()->lockForUpdate()->findOrFail($tenant->getKey());
            $lockedTenant = TenantCompany::query()->lockForUpdate()->findOrFail($company->getKey());

            Gate::forUser($actor)->authorize('archive', $lockedTenant);

            if ($lockedTenant->status() !== TenantCompanyStatus::Active) {
                throw ValidationException::withMessages([
                    'tenant' => 'Il Tenant Azienda non è attivo.',
                ]);
            }

            $lockedTenant->update(['status' => TenantCompanyStatus::Archived]);
            PlatformLifecycleEvent::query()->create([
                'operation_id' => (string) Str::uuid(), 'operation' => 'archive',
                'actor_id' => $actor->id, 'actor_name' => $actor->name,
                'tenant_id' => $lockedTenant->getKey(), 'occurred_at' => now('UTC'), 'outcome' => 'completed',
            ]);

            return $lockedTenant->refresh();
        });
    }
}
