<?php

namespace App\Actions\BusinessBackup;

use App\BusinessBackup\V1\BusinessBackupCollector;
use App\BusinessBackup\V1\BusinessBackupWorkbook;
use App\Domain\Company\TenantCompanyStatus;
use App\Models\Company;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Maatwebsite\Excel\Excel;
use Maatwebsite\Excel\Facades\Excel as ExcelFacade;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

final class ExportBusinessBackup
{
    public function __construct(private readonly BusinessBackupCollector $collector) {}

    /** @return array{path: string, filename: string, package_id: string} */
    public function execute(Company $company, ?User $actor = null): array
    {
        if ($actor !== null) {
            Gate::forUser($actor)->authorize('view', $company);
        }
        if (! $company->tenantCompany()->where('status', TenantCompanyStatus::Active->value)->exists()) {
            throw new NotFoundHttpException('Il Tenant non è operativo.');
        }

        if (DB::connection()->getDriverName() === 'mysql' && DB::connection()->transactionLevel() === 0) {
            DB::statement('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ');
        }
        $package = DB::transaction(fn (): array => $this->collector->collect($company));
        $bytes = ExcelFacade::raw(new BusinessBackupWorkbook($package), Excel::XLSX);

        $directory = storage_path('app/private/business-backups');
        File::ensureDirectoryExists($directory);
        $path = tempnam($directory, 'mp2-');
        if ($path === false || file_put_contents($path, $bytes, LOCK_EX) === false) {
            throw new \RuntimeException('Impossibile creare il file temporaneo del backup.');
        }
        $safeName = Str::slug($company->name);

        return [
            'path' => $path,
            'filename' => sprintf('MP2-%s-%s.xlsx', $safeName === '' ? 'Azienda' : $safeName, now($company->timezone)->format('Y-m-d')),
            'package_id' => $package['package_id'],
        ];
    }
}
