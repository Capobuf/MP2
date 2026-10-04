<?php

namespace App\BusinessBackup\V1;

use Maatwebsite\Excel\Concerns\Export;
use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\WithCustomValueBinder;
use Maatwebsite\Excel\Concerns\WithEvents;
use Maatwebsite\Excel\Concerns\WithMultipleSheets;
use Maatwebsite\Excel\Concerns\WithTitle;
use Maatwebsite\Excel\Events\AfterSheet;
use Maatwebsite\Excel\Events\BeforeExport;
use PhpOffice\PhpSpreadsheet\Cell\StringValueBinder;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

final class BusinessBackupWorkbook implements Export, WithEvents, WithMultipleSheets
{
    /** @var array<string, array{columns: list<string>, rows: list<list<string>>}> */
    private array $machine;

    /** @var array<string, string> */
    private array $checksums;

    /** @param array{
     *   format_version?: string,
     *   source_tenant_uuid?: string,
     *   package_id: string,
     *   exported_at: string,
     *   company: array{name: string, timezone: string},
     *   machine: array<string, array{columns: list<string>, rows: list<list<string>>}>,
     *   visible: array<string, array{columns: list<string>, rows: list<list<string>>}>
     * } $package
     */
    public function __construct(private readonly array $package)
    {
        $prepared = PortablePayload::prepare($package['machine']);
        $this->machine = $prepared['stored'];
        $this->checksums = $prepared['checksums'];
    }

    /** @return list<object> */
    public function sheets(): array
    {
        $sheets = [];
        foreach (BusinessBackupContract::visibleSheetsForVersion($this->version()) as $name) {
            $view = $this->package['visible'][$name];
            $sheets[] = new BusinessBackupSheet($name, $view['columns'], $view['rows'], false);
        }

        $sheets[] = new BusinessBackupSheet(BusinessBackupContract::MANIFEST, ['key', 'value'], $this->manifestRows(), true);
        foreach (BusinessBackupContract::schemasForVersion($this->version()) as $name => $columns) {
            $sheets[] = new BusinessBackupSheet($name, $columns, $this->machine[$name]['rows'], true);
        }

        return $sheets;
    }

    /** @return array<class-string, callable> */
    public function registerEvents(): array
    {
        return [
            BeforeExport::class => function (BeforeExport $event): void {
                $properties = $event->writer->getDelegate()->getProperties();
                $properties->setCreator('MP2');
                $properties->setTitle('MP2 Business Data Backup');
                $properties->setSubject('Portable business backup format v'.$this->version());
                $properties->setCustomProperty('mp2_format_version', $this->version());
                $properties->setCustomProperty('mp2_package_id', $this->package['package_id']);
            },
        ];
    }

    private function version(): string
    {
        return $this->package['format_version'] ?? '2';
    }

    /** @return list<list<string>> */
    private function manifestRows(): array
    {
        $rows = [
            ['format_version', $this->version()],
            ['package_id', $this->package['package_id']],
            ['exported_at', $this->package['exported_at']],
            ['application_revision', (string) config('app.revision', '')],
            ['company_ref', 'COM-0000000001'],
            ['company_name', $this->package['company']['name']],
            ['company_timezone', $this->package['company']['timezone']],
            ['currency', 'EUR'],
            ['vat_basis', 'net'],
            ['machine_sheet_count', (string) count(BusinessBackupContract::schemasForVersion($this->version()))],
        ];
        if ($this->version() === '3') {
            $rows[] = ['source_tenant_uuid', $this->package['source_tenant_uuid']];
        }
        foreach (BusinessBackupContract::schemasForVersion($this->version()) as $sheet => $_columns) {
            $rows[] = ['row_count:'.$sheet, (string) count($this->machine[$sheet]['rows'])];
            $rows[] = ['sha256:'.$sheet, $this->checksums[$sheet]];
        }
        foreach (BusinessBackupContract::visibleSheetsForVersion($this->version()) as $sheet) {
            $view = $this->package['visible'][$sheet];
            $rows[] = ['view_sha256:'.$sheet, PortablePayload::checksum($view['columns'], $view['rows'])];
        }

        return $rows;
    }
}

final class BusinessBackupSheet extends StringValueBinder implements FromArray, WithCustomValueBinder, WithEvents, WithTitle
{
    /** @param list<string> $columns
     * @param  list<list<string>>  $rows
     */
    public function __construct(
        private readonly string $name,
        private readonly array $columns,
        private readonly array $rows,
        private readonly bool $hidden,
    ) {}

    /** @return list<list<string>> */
    public function array(): array
    {
        return [$this->columns, ...$this->rows];
    }

    public function title(): string
    {
        return $this->name;
    }

    /** @return array<class-string, callable> */
    public function registerEvents(): array
    {
        return [
            AfterSheet::class => function (AfterSheet $event): void {
                $sheet = $event->sheet->getDelegate();
                if ($this->hidden) {
                    $sheet->setSheetState(Worksheet::SHEETSTATE_VERYHIDDEN);

                    return;
                }
                $lastColumn = $sheet->getHighestColumn();
                $sheet->freezePane('A2');
                $sheet->setAutoFilter("A1:{$lastColumn}1");
                $sheet->getStyle("A1:{$lastColumn}1")->getFont()->setBold(true)->getColor()->setARGB('FFFFFFFF');
                $sheet->getStyle("A1:{$lastColumn}1")->getFill()->setFillType('solid')->getStartColor()->setARGB('FF0F766E');
                $sheet->getStyle($sheet->calculateWorksheetDimension())->getAlignment()->setVertical(Alignment::VERTICAL_TOP)->setWrapText(true);
                foreach (range('A', $lastColumn) as $column) {
                    $sheet->getColumnDimension($column)->setWidth(24);
                }
            },
        ];
    }
}
