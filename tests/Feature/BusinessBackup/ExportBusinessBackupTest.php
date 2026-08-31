<?php

use App\Actions\BusinessBackup\ExportBusinessBackup;
use App\BusinessBackup\V1\BusinessBackupContract;
use App\Models\Company;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Tests\Support\TestPermissions;

uses(RefreshDatabase::class);

it('exports the exact V1 workbook only for a viewer of an active Tenant', function (): void {
    $company = Company::factory()->create(['name' => 'Azienda Backup']);
    $viewer = User::factory()->create();
    $outsider = User::factory()->create();
    grantTestPermissions([
        'company_id' => $company->id,
        'user' => $viewer,
        'permissions' => TestPermissions::VIEW,
    ]);

    expect(fn () => app(ExportBusinessBackup::class)->execute($company, $outsider))
        ->toThrow(AuthorizationException::class);

    $artifact = app(ExportBusinessBackup::class)->execute($company, $viewer);
    try {
        expect($artifact['filename'])->toMatch('/^MP2-azienda-backup-\d{4}-\d{2}-\d{2}\.xlsx$/')
            ->and(is_file($artifact['path']))->toBeTrue();

        $workbook = IOFactory::load($artifact['path']);
        expect($workbook->getSheetNames())->toBe([
            ...BusinessBackupContract::VISIBLE_SHEETS,
            BusinessBackupContract::MANIFEST,
            ...BusinessBackupContract::machineSheets(),
        ]);
        foreach ([BusinessBackupContract::MANIFEST, ...BusinessBackupContract::machineSheets()] as $sheet) {
            expect($workbook->getSheetByName($sheet)?->getSheetState())->toBe(Worksheet::SHEETSTATE_VERYHIDDEN);
        }
        expect($workbook->getSheetByName('_MP2_company')?->getCell('B2')->getDataType())->toBe(DataType::TYPE_STRING)
            ->and($workbook->getSheetByName('Informazioni')?->getCell('B5')->getValue())->toContain('modifica invalida il restore');
        $workbook->disconnectWorksheets();
    } finally {
        @unlink($artifact['path']);
    }

    $company->tenantCompany()->update(['status' => 'archived']);
    expect(fn () => app(ExportBusinessBackup::class)->execute($company, $viewer))
        ->toThrow(AuthorizationException::class)
        ->and(fn () => app(ExportBusinessBackup::class)->execute($company))
        ->toThrow(NotFoundHttpException::class);
});
