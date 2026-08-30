<?php

use App\Actions\BusinessBackup\ExportBusinessBackup;
use App\BusinessBackup\V1\BusinessBackupContract;
use App\BusinessBackup\V1\BusinessBackupValidator;
use App\BusinessBackup\V1\PortablePayload;
use App\Domain\Company\Capability;
use App\Models\Company;
use App\Models\CompanyCapability;
use App\Models\Exercise;
use App\Models\Expense;
use App\Models\ExpenseLine;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

uses(RefreshDatabase::class);

/** @return list<list<string>> */
function backupValidatorRows(Worksheet $sheet): array
{
    $rows = [];
    $width = Coordinate::columnIndexFromString($sheet->getHighestDataColumn());
    for ($row = 2; $row <= $sheet->getHighestDataRow(); $row++) {
        $values = [];
        for ($column = 1; $column <= $width; $column++) {
            $values[] = (string) ($sheet->getCell([$column, $row])->getValue() ?? '');
        }
        $rows[] = $values;
    }

    return $rows;
}

function refreshBackupValidatorChecksum(Spreadsheet $workbook, string $sheetName): void
{
    $sheet = $workbook->getSheetByName($sheetName);
    $manifest = $workbook->getSheetByName(BusinessBackupContract::MANIFEST);
    expect($sheet)->not->toBeNull()->and($manifest)->not->toBeNull();
    $columns = [];
    $width = Coordinate::columnIndexFromString($sheet->getHighestDataColumn());
    for ($column = 1; $column <= $width; $column++) {
        $columns[] = (string) $sheet->getCell([$column, 1])->getValue();
    }
    $checksum = PortablePayload::checksum($columns, backupValidatorRows($sheet));
    for ($row = 2; $row <= $manifest->getHighestDataRow(); $row++) {
        if ($manifest->getCell([1, $row])->getValue() === 'sha256:'.$sheetName) {
            $manifest->setCellValueExplicit([2, $row], $checksum, DataType::TYPE_STRING);

            return;
        }
    }
    throw new RuntimeException('Checksum manifest row not found.');
}

it('rejects corrupt future orphan duplicate and non-canonical workbooks before writes', function (): void {
    $company = Company::factory()->create();
    $actor = User::factory()->create();
    CompanyCapability::query()->create(['company_id' => $company->id, 'user_id' => $actor->id, 'capability' => Capability::View]);
    $exercise = Exercise::factory()->for($company)->create();
    $first = Expense::factory()->forExercise($exercise)->create();
    ExpenseLine::factory()->for($first)->create(['amount' => '10.00']);
    $second = Expense::factory()->forExercise($exercise)->create();
    ExpenseLine::factory()->for($second)->create(['amount' => '20.00']);
    $artifact = app(ExportBusinessBackup::class)->execute($company, $actor);
    $initialCompanies = Company::query()->count();

    try {
        $mutations = [
            'checksum' => function ($workbook): void {
                $workbook->getSheetByName('_MP2_company')->setCellValueExplicit('B2', 'Alterata', DataType::TYPE_STRING);
            },
            'future' => function ($workbook): void {
                $manifest = $workbook->getSheetByName(BusinessBackupContract::MANIFEST);
                $manifest->setCellValueExplicit('B2', '2', DataType::TYPE_STRING);
                $workbook->getProperties()->setCustomProperty('mp2_format_version', '2');
            },
            'orphan' => function ($workbook): void {
                $workbook->getSheetByName('_MP2_expense_lines')->setCellValueExplicit('B2', 'EXP-9999999999', DataType::TYPE_STRING);
                refreshBackupValidatorChecksum($workbook, '_MP2_expense_lines');
            },
            'duplicate' => function ($workbook): void {
                $sheet = $workbook->getSheetByName('_MP2_expenses');
                $sheet->setCellValueExplicit('A3', (string) $sheet->getCell('A2')->getValue(), DataType::TYPE_STRING);
                refreshBackupValidatorChecksum($workbook, '_MP2_expenses');
            },
            'decimal' => function ($workbook): void {
                $workbook->getSheetByName('_MP2_expense_lines')->setCellValueExplicit('D2', '10.0', DataType::TYPE_STRING);
                refreshBackupValidatorChecksum($workbook, '_MP2_expense_lines');
            },
            'timestamp' => function ($workbook): void {
                $workbook->getSheetByName(BusinessBackupContract::MANIFEST)
                    ->setCellValueExplicit('B4', '2026-02-30T00:00:00+00:00', DataType::TYPE_STRING);
            },
            'timestamp-offset' => function ($workbook): void {
                $workbook->getSheetByName(BusinessBackupContract::MANIFEST)
                    ->setCellValueExplicit('B4', '2026-08-31T00:00:00+25:00', DataType::TYPE_STRING);
            },
            'hidden-visible-sheet' => function ($workbook): void {
                $workbook->getSheetByName(BusinessBackupContract::VISIBLE_SHEETS[0])
                    ->setSheetState(Worksheet::SHEETSTATE_HIDDEN);
            },
            'manifest-company-mismatch' => function ($workbook): void {
                $workbook->getSheetByName(BusinessBackupContract::MANIFEST)
                    ->setCellValueExplicit('B7', 'Azienda diversa', DataType::TYPE_STRING);
            },
            'invalid-company-timezone' => function ($workbook): void {
                $workbook->getSheetByName(BusinessBackupContract::MANIFEST)
                    ->setCellValueExplicit('B8', 'Europe/Nowhere', DataType::TYPE_STRING);
                $workbook->getSheetByName('_MP2_company')
                    ->setCellValueExplicit('C2', 'Europe/Nowhere', DataType::TYPE_STRING);
                refreshBackupValidatorChecksum($workbook, '_MP2_company');
            },
            'reference-prefix' => function ($workbook): void {
                $workbook->getSheetByName('_MP2_expense_lines')
                    ->setCellValueExplicit('A2', 'BAD-0000000001', DataType::TYPE_STRING);
                refreshBackupValidatorChecksum($workbook, '_MP2_expense_lines');
            },
            'exercise-year' => function ($workbook): void {
                $workbook->getSheetByName('_MP2_exercises')
                    ->setCellValueExplicit('B2', 'not-a-year', DataType::TYPE_STRING);
                refreshBackupValidatorChecksum($workbook, '_MP2_exercises');
            },
        ];

        foreach ($mutations as $name => $mutate) {
            $workbook = IOFactory::load($artifact['path']);
            $mutate($workbook);
            $path = storage_path('framework/testing/business-backup-'.$name.'.xlsx');
            IOFactory::createWriter($workbook, 'Xlsx')->save($path);
            $workbook->disconnectWorksheets();
            try {
                expect(fn () => app(BusinessBackupValidator::class)->validate($path))->toThrow(ValidationException::class)
                    ->and(Company::query()->count())->toBe($initialCompanies);
            } finally {
                @unlink($path);
            }
        }
    } finally {
        @unlink($artifact['path']);
    }
});
