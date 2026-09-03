<?php

namespace App\Actions\Tenancy;

use App\Domain\Company\TenantCompanyStatus;
use App\Models\Company;
use App\Models\PendingFileDeletion;
use App\Models\TenantCompany;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
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

            if ($company->users()->exists()) {
                throw ValidationException::withMessages([
                    'tenant' => 'Il Tenant Azienda non può essere eliminato finché contiene utenti.',
                ]);
            }

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

            $operationId = (string) Str::uuid();
            $files = $this->exclusiveFiles($company->getKey());
            $now = now();

            if ($files !== []) {
                DB::table('pending_file_deletions')->upsert(
                    array_map(fn (array $file): array => [
                        'operation_id' => $operationId,
                        'storage_disk' => $file['storage_disk'],
                        'storage_path' => $file['storage_path'],
                        'attempts' => 0,
                        'last_attempted_at' => null,
                        'last_error' => null,
                        'created_at' => $now,
                        'updated_at' => $now,
                    ], $files),
                    ['storage_disk', 'storage_path'],
                    ['operation_id', 'updated_at'],
                );
            }

            $deleted = DB::table('companies')->where('id', $company->getKey())->delete();
            if ($deleted !== 1) {
                throw new \RuntimeException('The locked Company could not be deleted.');
            }

            return $operationId;
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

    /** @return list<array{storage_disk: string, storage_path: string}> */
    private function exclusiveFiles(int $companyId): array
    {
        $attachments = DB::table('attachments')
            ->where('company_id', $companyId)
            ->whereNotNull('storage_disk')
            ->whereNotNull('storage_path')
            ->get(['storage_disk', 'storage_path']);
        $evidence = DB::table('budget_evidence')
            ->where('company_id', $companyId)
            ->whereNotNull('storage_disk')
            ->whereNotNull('storage_path')
            ->get(['storage_disk', 'storage_path']);
        $logo = DB::table('companies')
            ->where('id', $companyId)
            ->whereNotNull('logo_disk')
            ->whereNotNull('logo_path')
            ->get(['logo_disk as storage_disk', 'logo_path as storage_path']);

        return $attachments->concat($evidence)->concat($logo)
            ->map(fn (object $file): array => [
                'storage_disk' => $this->requiredFileValue($file->storage_disk),
                'storage_path' => $this->requiredFileValue($file->storage_path),
            ])
            ->unique(fn (array $file): string => $file['storage_disk']."\0".$file['storage_path'])
            ->reject(fn (array $file): bool => $this->isReferencedByAnotherCompany($companyId, $file))
            ->values()
            ->all();
    }

    /** @param array{storage_disk: string, storage_path: string} $file */
    private function isReferencedByAnotherCompany(int $companyId, array $file): bool
    {
        foreach (['attachments', 'budget_evidence'] as $table) {
            if (DB::table($table)
                ->where('company_id', '<>', $companyId)
                ->where('storage_disk', $file['storage_disk'])
                ->where('storage_path', $file['storage_path'])
                ->exists()) {
                return true;
            }
        }

        if (DB::table('companies')
            ->where('id', '<>', $companyId)
            ->where('logo_disk', $file['storage_disk'])
            ->where('logo_path', $file['storage_path'])
            ->exists()) {
            return true;
        }

        return false;
    }

    private function requiredFileValue(mixed $value): string
    {
        if (! is_string($value) || trim($value) === '') {
            throw new \UnexpectedValueException('Invalid persisted file reference.');
        }

        return $value;
    }
}
