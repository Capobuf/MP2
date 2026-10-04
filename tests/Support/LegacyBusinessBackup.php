<?php

namespace Tests\Support;

use App\BusinessBackup\V1\BusinessBackupCollector;
use App\BusinessBackup\V1\BusinessBackupWorkbook;
use App\Models\Company;
use App\Models\User;
use Maatwebsite\Excel\Excel;
use Maatwebsite\Excel\Facades\Excel as ExcelFacade;

final class LegacyBusinessBackup
{
    /** @return array{path: string, filename: string, package_id: string} */
    public function execute(Company $company, ?User $actor = null): array
    {
        $package = app(BusinessBackupCollector::class)->collect($company, version: '2');
        $path = tempnam(sys_get_temp_dir(), 'mp2-legacy-');
        file_put_contents($path, ExcelFacade::raw(new BusinessBackupWorkbook($package), Excel::XLSX));

        return ['path' => $path, 'filename' => 'legacy.xlsx', 'package_id' => $package['package_id']];
    }
}
