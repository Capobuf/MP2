<?php

use App\Actions\BusinessBackup\ExportBusinessBackup;
use App\Actions\BusinessBackup\ImportBusinessBackup;
use App\BusinessBackup\V1\BusinessBackupContract;
use App\BusinessBackup\V1\BusinessBackupValidator;
use App\BusinessBackup\V1\PortablePayload;
use App\Models\BudgetSnapshot;
use App\Models\BudgetSourceRow;
use App\Models\Company;
use App\Models\Contract;
use App\Models\CostCenter;
use App\Models\Exercise;
use App\Models\Expense;
use App\Models\ExpenseLine;
use App\Models\Proposal;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use Tests\Support\TestPermissions;

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

function refreshBackupValidatorChecksum(Spreadsheet $workbook, string $sheetName, string $manifestPrefix = 'sha256:'): void
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
        if ($manifest->getCell([1, $row])->getValue() === $manifestPrefix.$sheetName) {
            $manifest->setCellValueExplicit([2, $row], $checksum, DataType::TYPE_STRING);

            return;
        }
    }
    throw new RuntimeException('Checksum manifest row not found.');
}

it('rejects corrupt future orphan duplicate and non-canonical workbooks before writes', function (): void {
    $company = Company::factory()->create();
    $actor = User::factory()->create();
    grantTestPermissions(['company_id' => $company->id, 'user' => $actor, 'permissions' => TestPermissions::VIEW]);
    $exercise = Exercise::factory()->for($company)->create();
    $first = Expense::factory()->forExercise($exercise)->create();
    ExpenseLine::factory()->for($first)->create(['amount' => '10.00']);
    $second = Expense::factory()->forExercise($exercise)->create();
    ExpenseLine::factory()->for($second)->create(['amount' => '20.00']);
    $childCenter = CostCenter::factory()->for($company)->create(['name' => 'Figlio']);
    $parentCenter = CostCenter::factory()->for($company)->create(['name' => 'Padre']);
    $childCenter->update(['parent_id' => $parentCenter->id]);
    Contract::factory()->create(['company_id' => $company->id]);
    $proposal = Proposal::factory()->for($company)->for($exercise)->for($actor, 'creator')->create(['status' => 'approved']);
    $budget = BudgetSnapshot::factory()->for($proposal)->create([
        'company_id' => $company->id,
        'exercise_id' => $exercise->id,
        'approved_by_id' => $actor->id,
        'total_approved_allocation' => '10.00',
    ]);
    BudgetSourceRow::factory()->for($budget, 'budget')->create([
        'company_id' => $company->id,
        'origin_id' => $first->id,
        'origin_key' => $first->originKey(),
        'approved_allocation' => '10.00',
        'detail' => [],
    ]);
    $artifact = app(ExportBusinessBackup::class)->execute($company, $actor);
    $initialCompanies = Company::query()->count();

    try {
        $mutations = [
            'checksum' => function ($workbook): void {
                $workbook->getSheetByName('_MP2_company')->setCellValueExplicit('B2', 'Alterata', DataType::TYPE_STRING);
            },
            'future' => function ($workbook): void {
                $manifest = $workbook->getSheetByName(BusinessBackupContract::MANIFEST);
                $manifest->setCellValueExplicit('B2', '3', DataType::TYPE_STRING);
                $workbook->getProperties()->setCustomProperty('mp2_format_version', '3');
            },
            'orphan' => function ($workbook): void {
                $workbook->getSheetByName('_MP2_expense_lines')->setCellValueExplicit('B2', 'EXP-9999999999', DataType::TYPE_STRING);
                refreshBackupValidatorChecksum($workbook, '_MP2_expense_lines');
            },
            'orphan-cost-center-parent' => function ($workbook): void {
                $workbook->getSheetByName('_MP2_cost_centers')->setCellValueExplicit('C2', 'CDC-9999999999', DataType::TYPE_STRING);
                refreshBackupValidatorChecksum($workbook, '_MP2_cost_centers');
            },
            'cost-center-cycle' => function ($workbook): void {
                $sheet = $workbook->getSheetByName('_MP2_cost_centers');
                $firstRef = (string) $sheet->getCell('A2')->getValue();
                $secondRef = (string) $sheet->getCell('A3')->getValue();
                $sheet->setCellValueExplicit('C2', $secondRef, DataType::TYPE_STRING);
                $sheet->setCellValueExplicit('C3', $firstRef, DataType::TYPE_STRING);
                refreshBackupValidatorChecksum($workbook, '_MP2_cost_centers');
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
            'renewal-duration' => function ($workbook): void {
                $workbook->getSheetByName('_MP2_contracts')
                    ->setCellValueExplicit('I2', 'not-a-duration', DataType::TYPE_STRING);
                refreshBackupValidatorChecksum($workbook, '_MP2_contracts');
            },
            'local-id-in-json' => function ($workbook): void {
                $workbook->getSheetByName('_MP2_budget_rows')
                    ->setCellValueExplicit('S2', '{"company_id":123}', DataType::TYPE_STRING);
                refreshBackupValidatorChecksum($workbook, '_MP2_budget_rows');
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

it('accepts a previous V1 workbook without hierarchy and restores every cost center as a root', function (): void {
    $company = Company::factory()->create();
    $admin = User::factory()->platformAdmin()->create();
    grantTestPermissions(['company_id' => $company->id, 'user' => $admin, 'permissions' => TestPermissions::VIEW]);
    CostCenter::factory()->for($company)->count(2)->create();
    $artifact = app(ExportBusinessBackup::class)->execute($company, $admin);
    $legacyPath = storage_path('framework/testing/business-backup-v1.xlsx');

    try {
        $workbook = IOFactory::load($artifact['path']);
        $workbook->getSheetByName('_MP2_cost_centers')->removeColumn('C');
        $workbook->getSheetByName('Centri di Costo')->removeColumn('B');
        $workbook->getSheetByName(BusinessBackupContract::MANIFEST)->setCellValueExplicit('B2', '1', DataType::TYPE_STRING);
        $workbook->getProperties()->setCustomProperty('mp2_format_version', '1');
        refreshBackupValidatorChecksum($workbook, '_MP2_cost_centers');
        refreshBackupValidatorChecksum($workbook, 'Centri di Costo', 'view_sha256:');
        IOFactory::createWriter($workbook, 'Xlsx')->save($legacyPath);
        $workbook->disconnectWorksheets();

        $package = app(BusinessBackupValidator::class)->validate($legacyPath);
        $restored = app(ImportBusinessBackup::class)->execute($admin, $package);

        expect($package['preview']->formatVersion)->toBe(1)
            ->and($package['machine']['_MP2_cost_centers']['columns'])->toBe(BusinessBackupContract::SCHEMAS['_MP2_cost_centers'])
            ->and($restored->costCenters()->whereNotNull('parent_id')->count())->toBe(0)
            ->and($restored->costCenters()->count())->toBe(2);
    } finally {
        @unlink($artifact['path']);
        @unlink($legacyPath);
    }
});
