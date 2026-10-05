<?php

use App\Actions\BusinessBackup\ExportBusinessBackup;
use App\BusinessBackup\BusinessBackupBundle;
use App\BusinessBackup\V1\BusinessBackupContract;
use App\BusinessBackup\V1\BusinessBackupValidator;
use App\Models\Company;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Tests\Support\TestPermissions;

uses(RefreshDatabase::class);

it('exports the exact current workbook only for a viewer of an active Tenant', function (): void {
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
        expect($artifact['filename'])->toBe(sprintf(
            'MP2-azienda-backup-%s-%s.zip',
            now($company->timezone)->format('Y-m-d'),
            $artifact['package_id'],
        ))
            ->and(is_file($artifact['path']))->toBeTrue();

        $zip = new ZipArchive;
        $zip->open($artifact['path']);
        $dataPath = tempnam(sys_get_temp_dir(), 'mp2-data-');
        file_put_contents($dataPath, $zip->getFromName('data.xlsx'));
        $zip->close();
        expect(fn () => app(BusinessBackupValidator::class)->validate($dataPath))->toThrow(ValidationException::class, 'bundle ZIP completo');
        $workbook = IOFactory::load($dataPath);
        unlink($dataPath);
        expect($workbook->getSheetNames())->toBe([
            ...BusinessBackupContract::visibleSheetsForVersion('3'),
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

it('exports through shared storage reached by a release symlink without copying the workbook onto itself', function (): void {
    $company = Company::factory()->create(['name' => 'Backup Storage Condiviso']);
    $originalStoragePath = app()->storagePath();
    $originalLocalRoot = config('filesystems.disks.local.root');
    $root = storage_path('framework/testing/business-backup-symlink-'.Str::uuid());
    $release = $root.'/releases/current';
    $sharedStorage = $root.'/shared/storage';
    $releaseStorage = $release.'/storage';
    $artifact = null;

    File::ensureDirectoryExists($release);
    File::ensureDirectoryExists($sharedStorage.'/app/private/business-backups');

    try {
        expect(symlink('../../shared/storage', $releaseStorage))->toBeTrue();
        app()->useStoragePath($releaseStorage);
        config()->set('filesystems.disks.local.root', $releaseStorage.'/app/private');
        Storage::forgetDisk('local');

        $diskDirectory = Storage::disk('local')->path('business-backups');
        $physicalDirectory = realpath(storage_path('app/private/business-backups'));
        $temporaryPath = app(BusinessBackupBundle::class)->temporaryFile();
        expect($physicalDirectory)->not->toBeFalse()
            ->and($diskDirectory)->not->toBe($physicalDirectory)
            ->and(realpath($diskDirectory))->toBe($physicalDirectory)
            ->and(dirname($temporaryPath))->toBe($physicalDirectory);
        @unlink($temporaryPath);

        $artifact = app(ExportBusinessBackup::class)->execute($company);

        $zip = new ZipArchive;
        expect($zip->open($artifact['path']))->toBeTrue()
            ->and($zip->locateName('data.xlsx'))->not->toBeFalse();
        $zip->close();

        $package = app(BusinessBackupBundle::class)->validate($artifact['path'], 'zip');
        expect($package['manifest']['format_version'])->toBe('3');
    } finally {
        if ($artifact !== null) {
            @unlink($artifact['path']);
        }
        Storage::forgetDisk('local');
        config()->set('filesystems.disks.local.root', $originalLocalRoot);
        app()->useStoragePath($originalStoragePath);
        @unlink($releaseStorage);
        File::deleteDirectory($root);
    }
});
