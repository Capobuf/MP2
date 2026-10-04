<?php

namespace App\Actions\Tenancy;

use App\Domain\Company\TenantCompanyStatus;
use App\Models\Company;
use App\Models\PendingFileDeletion;
use App\Models\TenantCompany;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

class DestroyTenantCompany
{
    public function __construct(private readonly DeletePendingTenantFiles $deletePendingTenantFiles) {}

    public function execute(
        User $actor,
        TenantCompany $tenant,
        bool $irreversibilityConfirmed,
        bool $destructionConfirmed,
    ): TenantDestructionResult {
        Gate::forUser($actor)->authorize('destroy', $tenant);

        $operationId = DB::transaction(function () use (
            $actor,
            $tenant,
            $irreversibilityConfirmed,
            $destructionConfirmed,
        ): string {
            $company = Company::query()->lockForUpdate()->findOrFail($tenant->getKey());
            $lockedTenant = TenantCompany::query()->lockForUpdate()->findOrFail($company->getKey());

            Gate::forUser($actor)->authorize('destroy', $lockedTenant);

            if (! in_array($lockedTenant->status(), [TenantCompanyStatus::Active, TenantCompanyStatus::Archived], true)) {
                throw ValidationException::withMessages([
                    'tenant' => 'Lo stato del Tenant Azienda non consente la cancellazione definitiva.',
                ]);
            }

            if (! $irreversibilityConfirmed) {
                throw ValidationException::withMessages([
                    'irreversibility_confirmed' => 'È necessario confermare l’irreversibilità della cancellazione.',
                ]);
            }

            if (! $destructionConfirmed) {
                throw ValidationException::withMessages([
                    'destruction_confirmed' => 'È necessario confermare la distruzione definitiva del Tenant Azienda.',
                ]);
            }

            return app(DestroyTenantCompanyData::class)->execute($actor, $company, $lockedTenant);
        });

        $cleanup = $this->deletePendingTenantFiles->execute($operationId);
        $pending = PendingFileDeletion::query()->where('operation_id', $operationId)->count();

        return new TenantDestructionResult(
            operationId: $operationId,
            filesProcessed: $cleanup['processed'],
            filesCompleted: $cleanup['completed'],
            filesPending: $pending,
        );
    }
}
