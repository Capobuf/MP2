<?php

namespace App\Actions\BusinessBackup;

use App\BusinessBackup\BusinessBackupBundle;
use App\BusinessBackup\V1\BusinessBackupCollector;
use App\BusinessBackup\V1\BusinessBackupWorkbook;
use App\Domain\Company\TenantCompanyStatus;
use App\Models\Company;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Maatwebsite\Excel\Excel;
use Maatwebsite\Excel\Facades\Excel as ExcelFacade;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

final class ExportBusinessBackup
{
    public function __construct(private readonly BusinessBackupCollector $collector) {}

    /** @return array{path: string, filename: string, package_id: string} */
    public function execute(Company $company, ?User $actor = null, bool $includeLogo = true, bool $includeFiles = true): array
    {
        if ($actor !== null) {
            Gate::forUser($actor)->authorize('view', $company);
        }
        if (DB::connection()->getDriverName() === 'mysql' && DB::connection()->transactionLevel() === 0) {
            DB::statement('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ');
        }
        $package = DB::transaction(function () use ($company): array {
            if (! $company->tenantCompany()->where('status', TenantCompanyStatus::Active->value)->exists()) {
                throw new NotFoundHttpException('Il Tenant non è operativo.');
            }

            return $this->collector->collect($company);
        });
        $bundle = app(BusinessBackupBundle::class);
        $workbookPath = $bundle->temporaryFile();
        $path = $bundle->temporaryFile();
        try {
            $relativePath = 'business-backups/'.basename($workbookPath);
            if (! ExcelFacade::store(new BusinessBackupWorkbook($package), $relativePath, 'local', Excel::XLSX)) {
                throw new \RuntimeException('Impossibile creare data.xlsx.');
            }
            $storedPath = Storage::disk('local')->path($relativePath);
            $resolvedStoredPath = realpath($storedPath);
            $resolvedWorkbookPath = realpath($workbookPath);
            if ($resolvedStoredPath === false || $resolvedWorkbookPath === false) {
                throw new \RuntimeException('Impossibile risolvere il percorso di data.xlsx.');
            }
            if ($resolvedStoredPath !== $resolvedWorkbookPath && ! copy($storedPath, $workbookPath)) {
                throw new \RuntimeException('Impossibile preparare data.xlsx.');
            }
            $bundle->build($package, $workbookPath, $path, $includeLogo, $includeFiles);
        } catch (\Throwable $exception) {
            @unlink($path);
            throw $exception;
        } finally {
            @unlink($workbookPath);
            if (isset($relativePath)) {
                Storage::disk('local')->delete($relativePath);
            }
        }
        $safeName = Str::slug($package['company']['name']);

        return [
            'path' => $path,
            'filename' => sprintf(
                'MP2-%s-%s-%s.zip',
                $safeName === '' ? 'Azienda' : $safeName,
                now($package['company']['timezone'])->format('Y-m-d'),
                $package['package_id'],
            ),
            'package_id' => $package['package_id'],
        ];
    }
}
