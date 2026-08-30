<?php

namespace App\BusinessBackup\V1;

use App\BusinessBackup\BackupPreview;
use App\Models\Company;
use Carbon\CarbonImmutable;
use Illuminate\Validation\ValidationException;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Document\Properties;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

final class BusinessBackupValidator
{
    /** @return array{
     *   manifest: array<string, string>,
     *   machine: array<string, array{columns: list<string>, rows: list<list<string>>}>,
     *   preview: BackupPreview
     * } */
    public function validate(string $path): array
    {
        try {
            $reader = IOFactory::createReaderForFile($path);
            $reader->setReadDataOnly(false);
            $workbook = $reader->load($path);
        } catch (\Throwable) {
            throw ValidationException::withMessages(['backup' => 'Il file non è un workbook XLSX MP2 leggibile.']);
        }

        try {
            $expectedSheets = [...BusinessBackupContract::VISIBLE_SHEETS, BusinessBackupContract::MANIFEST, ...BusinessBackupContract::machineSheets()];
            $this->assert($workbook->getSheetNames() === $expectedSheets, 'L’elenco o l’ordine dei fogli non coincide con il formato V1.');

            $visible = [];
            foreach (BusinessBackupContract::VISIBLE_SHEETS as $name) {
                $visible[$name] = $this->readUnknownSchema($workbook->getSheetByName($name), false);
            }

            $manifestData = $this->readExact($workbook->getSheetByName(BusinessBackupContract::MANIFEST), ['key', 'value'], true);
            $manifest = [];
            foreach ($manifestData['rows'] as $row) {
                $this->assert($row[0] !== '' && ! array_key_exists($row[0], $manifest), 'Il manifest contiene una chiave vuota o duplicata.');
                $manifest[$row[0]] = $row[1];
            }
            $this->assertManifest($manifest, $workbook->getProperties());

            $stored = [];
            foreach (BusinessBackupContract::SCHEMAS as $name => $columns) {
                $stored[$name] = $this->readExact($workbook->getSheetByName($name), $columns, true);
            }
            $machine = $this->expandPayloads($stored);

            foreach (BusinessBackupContract::SCHEMAS as $name => $columns) {
                $rowsForChecksum = $name === BusinessBackupContract::LONG_PAYLOADS ? $stored[$name]['rows'] : $machine[$name]['rows'];
                $this->assert(($manifest['row_count:'.$name] ?? null) === (string) count($stored[$name]['rows']), "Conteggio non valido per [$name].");
                $this->assert(hash_equals($manifest['sha256:'.$name] ?? '', PortablePayload::checksum($columns, $rowsForChecksum)), "Checksum non valido per [$name].");
            }
            foreach ($visible as $name => $view) {
                $this->assert(hash_equals($manifest['view_sha256:'.$name] ?? '', PortablePayload::checksum($view['columns'], $view['rows'])), "Il foglio visibile [$name] è stato modificato.");
            }

            $this->assertStructure($machine);
            $this->assertCompanyIdentity($manifest, $machine['_MP2_company']['rows'][0]);
            $preview = $this->preview($manifest, $machine);

            return ['manifest' => $manifest, 'machine' => $machine, 'preview' => $preview];
        } finally {
            $workbook->disconnectWorksheets();
        }
    }

    /** @param array<string, string> $manifest */
    private function assertManifest(array $manifest, Properties $properties): void
    {
        $required = ['format_version', 'package_id', 'exported_at', 'application_revision', 'company_ref', 'company_name', 'company_timezone', 'currency', 'vat_basis', 'machine_sheet_count'];
        foreach ($required as $key) {
            $this->assert(array_key_exists($key, $manifest), "Chiave manifest mancante [$key].");
        }
        $this->assert($manifest['format_version'] === BusinessBackupContract::FORMAT_VERSION, 'Versione backup non supportata.');
        $this->assert((bool) preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[1-5][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i', $manifest['package_id']), 'package_id non è un UUID valido.');
        $this->assert($this->validTimestamp($manifest['exported_at']), 'exported_at non è un timestamp ISO 8601 valido.');
        $this->assert($manifest['company_ref'] === 'COM-0000000001', 'Riferimento Azienda V1 non valido.');
        $this->assert($manifest['currency'] === 'EUR' && $manifest['vat_basis'] === 'net', 'Valuta o base IVA non supportata.');
        $this->assert($manifest['machine_sheet_count'] === (string) count(BusinessBackupContract::SCHEMAS), 'Conteggio fogli macchina non valido.');

        $expectedKeys = $required;
        foreach (BusinessBackupContract::SCHEMAS as $sheet => $_columns) {
            $expectedKeys[] = 'row_count:'.$sheet;
            $expectedKeys[] = 'sha256:'.$sheet;
        }
        foreach (BusinessBackupContract::VISIBLE_SHEETS as $sheet) {
            $expectedKeys[] = 'view_sha256:'.$sheet;
        }
        $this->assert(array_keys($manifest) === $expectedKeys, 'Il manifest contiene chiavi mancanti, aggiuntive o fuori ordine.');
        $this->assert($properties->getCustomPropertyValue('mp2_format_version') === BusinessBackupContract::FORMAT_VERSION, 'Metadata formato MP2 mancante o incoerente.');
        $this->assert($properties->getCustomPropertyValue('mp2_package_id') === $manifest['package_id'], 'Metadata package MP2 mancante o incoerente.');
    }

    /**
     * @param  array<string, string>  $manifest
     * @param  list<string>  $company
     */
    private function assertCompanyIdentity(array $manifest, array $company): void
    {
        $this->assert(
            $manifest['company_ref'] === $company[0]
            && $manifest['company_name'] === $company[1]
            && $manifest['company_timezone'] === $company[2],
            'Azienda del manifest incoerente con i dati macchina.',
        );
        $this->assert($company[1] !== '' && mb_strlen($company[1]) <= 255, 'Nome Azienda non valido.');
        $this->assert(in_array($company[2], \DateTimeZone::listIdentifiers(), true), 'Fuso orario Azienda non valido.');
    }

    /** @param array<string, array{columns: list<string>, rows: list<list<string>>}> $stored
     * @return array<string, array{columns: list<string>, rows: list<list<string>>}>
     */
    private function expandPayloads(array $stored): array
    {
        $groups = [];
        foreach ($stored[BusinessBackupContract::LONG_PAYLOADS]['rows'] as $row) {
            [$ref, $sheet, $targetRef, $column, $index, $count, $checksum, $text] = $row;
            $this->assert((bool) preg_match('/^PAY-\d{10}$/', $ref), 'Riferimento payload lungo non valido.');
            $this->assert(isset(BusinessBackupContract::SCHEMAS[$sheet]) && $sheet !== BusinessBackupContract::LONG_PAYLOADS, 'Foglio target payload non valido.');
            $this->assert(in_array($column, BusinessBackupContract::SCHEMAS[$sheet], true), 'Colonna target payload non valida.');
            $this->assert(ctype_digit($index) && ctype_digit($count) && (int) $index >= 1 && (int) $count >= 1, 'Indici payload non validi.');
            $this->assert(strlen($text) <= PortablePayload::CHUNK_BYTES && hash_equals($checksum, hash('sha256', $text)), 'Chunk payload non valido.');
            $groups[$ref][] = compact('sheet', 'targetRef', 'column', 'index', 'count', 'text');
        }

        $payloads = [];
        foreach ($groups as $ref => $chunks) {
            usort($chunks, fn (array $a, array $b): int => (int) $a['index'] <=> (int) $b['index']);
            $expectedCount = (int) $chunks[0]['count'];
            $this->assert(count($chunks) === $expectedCount, "Chunk mancanti per [$ref].");
            foreach ($chunks as $index => $chunk) {
                $this->assert((int) $chunk['index'] === $index + 1 && (int) $chunk['count'] === $expectedCount, "Sequenza chunk non valida per [$ref].");
                $this->assert($chunk['sheet'] === $chunks[0]['sheet'] && $chunk['targetRef'] === $chunks[0]['targetRef'] && $chunk['column'] === $chunks[0]['column'], "Target chunk incoerente per [$ref].");
            }
            $payloads[$ref] = [
                'value' => implode('', array_column($chunks, 'text')),
                'sheet' => $chunks[0]['sheet'],
                'target_ref' => $chunks[0]['targetRef'],
                'column' => $chunks[0]['column'],
                'used' => false,
            ];
        }

        $expanded = $stored;
        foreach ($expanded as $sheet => &$data) {
            if ($sheet === BusinessBackupContract::LONG_PAYLOADS) {
                continue;
            }
            foreach ($data['rows'] as &$row) {
                foreach ($row as $columnIndex => &$value) {
                    if (! str_starts_with($value, '@payload:')) {
                        continue;
                    }
                    $ref = substr($value, 9);
                    $payload = $payloads[$ref] ?? null;
                    $this->assert($payload !== null && ! $payload['used'], "Payload [$ref] mancante o riutilizzato.");
                    $this->assert($payload['sheet'] === $sheet && $payload['target_ref'] === $row[0] && $payload['column'] === $data['columns'][$columnIndex], "Target payload [$ref] non coincide.");
                    $value = $payload['value'];
                    $payloads[$ref]['used'] = true;
                }
                unset($value);
            }
            unset($row);
        }
        unset($data);
        foreach ($payloads as $ref => $payload) {
            $this->assert($payload['used'], "Payload [$ref] non referenziato.");
        }

        return $expanded;
    }

    /** @param array<string, array{columns: list<string>, rows: list<list<string>>}> $m */
    private function assertStructure(array $m): void
    {
        $this->assert(count($m['_MP2_company']['rows']) === 1, 'Il package deve contenere esattamente una Azienda.');
        $refs = [];
        foreach ($m as $sheet => $data) {
            if ($sheet === BusinessBackupContract::LONG_PAYLOADS) {
                continue;
            }
            $prefix = BusinessBackupContract::PREFIXES[BusinessBackupContract::SHEET_REFERENCE_TYPES[$sheet]];
            foreach ($data['rows'] as $row) {
                $ref = $row[0];
                $this->assert((bool) preg_match('/^'.preg_quote($prefix, '/').'-\d{10}$/D', $ref), "Riferimento non valido in [$sheet].");
                $this->assert(! isset($refs[$ref]), "Riferimento duplicato [$ref].");
                $refs[$ref] = $sheet;
            }
        }

        $foreign = [
            '_MP2_supplier_contacts' => [1 => 'SUP'], '_MP2_project_transitions' => [1 => 'PRJ'],
            '_MP2_project_classes' => [1 => 'PRJ', 2 => 'EXE', 3 => 'CDC'],
            '_MP2_contracts' => [1 => 'SUP'], '_MP2_contract_renewals' => [1 => 'CTR'],
            '_MP2_contract_lifecycle' => [1 => 'CTR', 6 => 'RCF'], '_MP2_contract_conditions' => [1 => 'CTR'],
            '_MP2_contract_classes' => [1 => 'CTR', 2 => 'EXE', 3 => 'CDC'],
            '_MP2_project_contract_links' => [1 => 'PRJ', 2 => 'CTR'],
            '_MP2_expenses' => [1 => 'EXE', 2 => 'PRJ', 3 => 'CTR', 4 => 'SUP', 5 => 'CDC', 7 => 'EXP'],
            '_MP2_expense_lines' => [1 => 'EXP'], '_MP2_project_deferrals' => [1 => 'PRJ', 2 => 'EXE', 3 => 'EXE'],
            '_MP2_budgets' => [1 => 'EXE', 5 => 'BUD'], '_MP2_budget_rows' => [1 => 'BUD', 3 => null, 4 => null, 7 => 'SUP', 9 => 'CDC'],
            '_MP2_budget_evidence' => [1 => 'BUD', 5 => 'ATT'], '_MP2_closings' => [1 => 'EXE', 5 => 'BUD', 6 => 'BUD', 14 => 'EXE'],
            '_MP2_closing_rows' => [1 => 'CLS', 3 => null, 4 => null, 7 => 'SUP', 9 => 'CDC'],
            '_MP2_late_corrections' => [1 => 'EXE', 2 => 'CLS', 3 => 'EXP', 4 => 'LIN', 5 => 'LIN', 10 => null],
            '_MP2_error_annotations' => [1 => 'EXE', 2 => 'CLS'], '_MP2_attachments' => [2 => null],
        ];
        foreach ($foreign as $sheet => $columns) {
            foreach ($m[$sheet]['rows'] as $row) {
                foreach ($columns as $index => $prefix) {
                    $value = $row[$index];
                    if ($value === '') {
                        continue;
                    }
                    $expected = $prefix ?? $this->dynamicPrefix($sheet, $row, $index);
                    $this->assert(str_starts_with($value, $expected.'-') && isset($refs[$value]), "Riferimento orfano o di tipo errato [$value].");
                }
            }
        }

        foreach (BusinessBackupContract::ENUMS as $key => $allowed) {
            [$sheet, $column] = explode('.', $key, 2);
            $index = array_search($column, $m[$sheet]['columns'], true);
            foreach ($m[$sheet]['rows'] as $row) {
                $this->assert($row[$index] === '' || in_array($row[$index], $allowed, true), "Valore enum non valido per [$key].");
            }
        }
        foreach (BusinessBackupContract::DECIMALS as $key => $scale) {
            [$sheet, $column] = explode('.', $key, 2);
            $index = array_search($column, $m[$sheet]['columns'], true);
            foreach ($m[$sheet]['rows'] as $row) {
                $this->assert($row[$index] === '' || (bool) preg_match('/^-?\d+\.\d{'.$scale.'}$/', $row[$index]), "Decimal non canonico per [$key].");
            }
        }
        foreach ([
            '_MP2_company' => [3], '_MP2_contracts' => [7], '_MP2_contract_renewals' => [3],
            '_MP2_closing_rows' => [12], '_MP2_late_corrections' => [8],
        ] as $sheet => $columns) {
            foreach ($m[$sheet]['rows'] as $row) {
                foreach ($columns as $index) {
                    $this->assert(in_array($row[$index], ['0', '1'], true), "Boolean non canonico in [$sheet].");
                }
            }
        }

        $this->assertJsonColumns($m);
        $this->assertDates($m);
        $this->assertContractRenewals($m);
        $this->assertExpenses($m);
        $this->assertBudgets($m);
        $this->assertClosings($m);
        $this->assertDeferrals($m, $refs);
        $this->assertCorrections($m);
    }

    /** @param array<string, array{columns: list<string>, rows: list<list<string>>}> $m */
    private function assertContractRenewals(array $m): void
    {
        foreach ([
            '_MP2_contracts' => [5, 7, 8, 9],
            '_MP2_contract_renewals' => [4, 3, 5, 6],
        ] as $sheet => [$expiryIndex, $automaticIndex, $durationIndex, $noticeIndex]) {
            foreach ($m[$sheet]['rows'] as $row) {
                $duration = $row[$durationIndex];
                $notice = $row[$noticeIndex];
                $this->assert($duration === '' || $this->validUnsignedInteger($duration, 1), "Durata rinnovo non valida in [$sheet].");
                $this->assert($notice === '' || $this->validUnsignedInteger($notice), "Preavviso non valido in [$sheet].");
                $this->assert($row[$automaticIndex] !== '1' || $row[$expiryIndex] === '' || $duration !== '', "Durata rinnovo mancante in [$sheet].");
            }
        }
    }

    /** @param array<string, array{columns: list<string>, rows: list<list<string>>}> $m */
    private function assertJsonColumns(array $m): void
    {
        $columns = [
            '_MP2_supplier_contacts' => [7], '_MP2_project_deferrals' => [8], '_MP2_budgets' => [7],
            '_MP2_budget_rows' => [18], '_MP2_closings' => [11, 12], '_MP2_closing_rows' => [19],
            '_MP2_late_corrections' => [12, 13], '_MP2_error_annotations' => [6, 7, 8],
        ];
        foreach ($columns as $sheet => $indexes) {
            foreach ($m[$sheet]['rows'] as $row) {
                foreach ($indexes as $index) {
                    if ($row[$index] === '' && in_array("$sheet.$index", ['_MP2_project_deferrals.8', '_MP2_late_corrections.13'], true)) {
                        continue;
                    }
                    try {
                        $value = json_decode($row[$index], true, flags: JSON_THROW_ON_ERROR);
                    } catch (\Throwable) {
                        $this->fail("JSON non valido in [$sheet].");
                    }
                    $this->assertPortableJsonKeys($value, $sheet);
                }
            }
        }
    }

    private function assertPortableJsonKeys(mixed $value, string $sheet): void
    {
        if (! is_array($value)) {
            return;
        }
        $forbidden = [
            'origin_key', 'copied_from_origin_key', 'source_expense_origin_key',
            'approval_event_sequences', 'event_references', 'approved_actions',
            'revision', 'storage_disk', 'storage_path', 'created_at', 'updated_at', 'deleted_at',
        ];
        foreach ($value as $key => $item) {
            if (is_string($key)) {
                $invalid = in_array($key, $forbidden, true)
                    || str_ends_with($key, '_id')
                    || str_ends_with($key, '_ids')
                    || str_ends_with($key, '_revision');
                $this->assert(! $invalid, "Chiave locale non ammessa nel JSON di [$sheet].");
            }
            $this->assertPortableJsonKeys($item, $sheet);
        }
    }

    /** @param array<string, array{columns: list<string>, rows: list<list<string>>}> $m */
    private function assertExpenses(array $m): void
    {
        $seenSystem = [];
        foreach ($m['_MP2_expenses']['rows'] as $row) {
            $this->assert(! ($row[2] !== '' && $row[3] !== ''), 'Una Spesa non può appartenere insieme a Progetto e Contratto.');
            $this->assert(($row[2] === '' && $row[3] === '') || $row[5] === '', 'Una Spesa contenuta non può avere CdC diretto.');
            if ($row[6] === 'system') {
                $this->assert($row[3] !== '', 'Una Spesa di sistema richiede un Contratto.');
                $key = $row[3].'|'.$row[1];
                $this->assert(! isset($seenSystem[$key]), 'Due Stime di sistema riferiscono lo stesso Contratto/Esercizio.');
                $seenSystem[$key] = true;
            }
        }
    }

    /** @param array<string, array{columns: list<string>, rows: list<list<string>>}> $m */
    private function assertDates(array $m): void
    {
        foreach ($m['_MP2_exercises']['rows'] as $row) {
            $this->assert((bool) preg_match('/^[1-9]\d{0,3}$/D', $row[1]), 'Anno Esercizio non valido.');
        }

        $dates = [
            '_MP2_projects' => [5 => false], '_MP2_project_transitions' => [4 => false],
            '_MP2_contracts' => [4 => false, 5 => true, 6 => true],
            '_MP2_contract_renewals' => [2 => false, 4 => true],
            '_MP2_contract_lifecycle' => [3 => false, 4 => true, 5 => true],
            '_MP2_contract_conditions' => [5 => false, 6 => true],
        ];
        foreach ($dates as $sheet => $columns) {
            foreach ($m[$sheet]['rows'] as $row) {
                foreach ($columns as $index => $nullable) {
                    $this->assert(($nullable && $row[$index] === '') || $this->validDate($row[$index]), "Data non valida in [$sheet].");
                }
            }
        }

        $timestamps = [
            '_MP2_suppliers' => [4 => true], '_MP2_cost_centers' => [2 => true], '_MP2_projects' => [6 => true],
            '_MP2_project_transitions' => [6 => true], '_MP2_contracts' => [10 => true],
            '_MP2_contract_lifecycle' => [8 => true], '_MP2_contract_conditions' => [8 => true],
            '_MP2_project_contract_links' => [4 => true], '_MP2_expenses' => [10 => true],
            '_MP2_expense_lines' => [8 => true], '_MP2_budgets' => [4 => false],
            '_MP2_closings' => [4 => false], '_MP2_late_corrections' => [6 => false],
            '_MP2_error_annotations' => [3 => false],
        ];
        foreach ($timestamps as $sheet => $columns) {
            foreach ($m[$sheet]['rows'] as $row) {
                foreach ($columns as $index => $nullable) {
                    $this->assert(($nullable && $row[$index] === '') || $this->validTimestamp($row[$index]), "Timestamp non valido in [$sheet].");
                }
            }
        }
    }

    /** @param array<string, array{columns: list<string>, rows: list<list<string>>}> $m */
    private function assertBudgets(array $m): void
    {
        $byExercise = [];
        foreach ($m['_MP2_budgets']['rows'] as $row) {
            $byExercise[$row[1]][] = $row;
        }
        foreach ($byExercise as $rows) {
            usort($rows, fn (array $left, array $right): int => (int) $left[2] <=> (int) $right[2]);
            foreach ($rows as $index => $row) {
                $this->assert((int) $row[2] === $index + 1, 'Versioni Budget non contigue.');
                $this->assert($row[5] === ($index === 0 ? '' : $rows[$index - 1][0]), 'Lineage Budget incoerente.');
                $this->assert($row[3] === ($index === 0 ? 'initial_budget' : 'revision'), 'Purpose Budget incoerente con la versione.');
            }
        }
        $budgetOwners = [];
        foreach ($m['_MP2_budgets']['rows'] as $budget) {
            $budgetOwners[$budget[0]] = $budget[1];
            if ($budget[5] !== '') {
                $this->assert(($budgetOwners[$budget[5]] ?? null) === $budget[1], 'Il predecessore Budget appartiene a un altro Esercizio o non precede la revisione.');
            }
        }
        $expenseOwners = [];
        foreach ($m['_MP2_expenses']['rows'] as $row) {
            $expenseOwners[$row[0]] = $row;
        }
        $rowsByBudget = [];
        foreach ($m['_MP2_budget_rows']['rows'] as $row) {
            $rowsByBudget[$row[1]][] = $row;
        }
        foreach ($m['_MP2_budgets']['rows'] as $budget) {
            $parts = [];
            foreach ($rowsByBudget[$budget[0]] ?? [] as $row) {
                if (in_array($row[2], ['project', 'contract'], true)) {
                    $parts[] = $row[14];

                    continue;
                }
                $expense = $expenseOwners[$row[3]] ?? null;
                if ($expense !== null && $expense[2] === '' && $expense[3] === '') {
                    $parts[] = $row[14];
                }
            }
            $this->assert(bccomp($budget[6], $this->sum($parts), 2) === 0, 'Totale Budget non riconciliato con le righe di primo livello.');
        }
    }

    /** @param array<string, array{columns: list<string>, rows: list<list<string>>}> $m */
    private function assertClosings(array $m): void
    {
        $closed = [];
        foreach ($m['_MP2_exercises']['rows'] as $row) {
            if ($row[2] === 'closed') {
                $closed[] = $row[0];
            }
        }
        $closingExercises = array_column($m['_MP2_closings']['rows'], 1);
        sort($closed);
        sort($closingExercises);
        $this->assert($closed === $closingExercises, 'Ogni Esercizio Chiuso deve avere esattamente la propria Snapshot di Chiusura.');
        $rowsByClosing = [];
        foreach ($m['_MP2_closing_rows']['rows'] as $row) {
            $rowsByClosing[$row[1]][] = $row;
        }
        foreach ($m['_MP2_closings']['rows'] as $closing) {
            $exercise = null;
            foreach ($m['_MP2_exercises']['rows'] as $candidate) {
                if ($candidate[0] === $closing[1]) {
                    $exercise = $candidate;
                    break;
                }
            }
            $this->assert($exercise !== null && $closing[3] === $exercise[1], 'Anno della Chiusura incoerente con l’Esercizio.');
            $requiresNextExercise = in_array($closing[13], ['created', 'already_existed'], true);
            $this->assert($requiresNextExercise === ($closing[14] !== ''), 'Decisione N+1 della Chiusura incoerente con il riferimento Esercizio.');
            foreach ([$closing[5], $closing[6]] as $budgetRef) {
                if ($budgetRef !== '') {
                    $budget = null;
                    foreach ($m['_MP2_budgets']['rows'] as $candidate) {
                        if ($candidate[0] === $budgetRef) {
                            $budget = $candidate;
                            break;
                        }
                    }
                    $this->assert($budget !== null && $budget[1] === $closing[1], 'Budget della Chiusura riferito a un altro Esercizio.');
                }
            }
            $rows = $rowsByClosing[$closing[0]] ?? [];
            $this->assert(bccomp($closing[7], $this->sum(array_column($rows, 15)), 2) === 0, 'Totale allocato di Chiusura non riconciliato.');
            $this->assert(bccomp($closing[8], $this->sum(array_column($rows, 16)), 2) === 0, 'Totale Effettivi di Chiusura non riconciliato.');
            $this->assert(bccomp($closing[9], $this->sum(array_column($rows, 17)), 2) === 0, 'Varianza di Chiusura non riconciliata.');
            $this->assert(bccomp($closing[9], bcsub($closing[8], $closing[7], 2), 2) === 0, 'Totali header della Chiusura incoerenti.');
        }
    }

    /** @param array<string, array{columns: list<string>, rows: list<list<string>>}> $m
     * @param  array<string, string>  $refs
     */
    private function assertDeferrals(array $m, array $refs): void
    {
        $expenses = [];
        foreach ($m['_MP2_expenses']['rows'] as $expense) {
            $expenses[$expense[0]] = $expense;
        }
        $lines = [];
        foreach ($m['_MP2_expense_lines']['rows'] as $line) {
            $lines[$line[0]] = $line;
        }
        foreach ($m['_MP2_project_deferrals']['rows'] as $row) {
            if ($row[4] !== 'reprogramming') {
                $this->assert($row[8] === '', 'Solo una Riprogrammazione può avere effects.');
                $this->assert($row[7] === '0.00', 'Un rinvio non riprogrammato deve avere importo riprogrammato zero.');
                $this->assert($row[4] === 'carryover' || ($row[5] === '0.00' && $row[6] === ''), 'La modalità none non può conservare un Riporto.');
                $this->assert($row[4] !== 'carryover' || ($row[6] !== '' && bccomp($row[5], '0.00', 2) >= 0), 'Riporto privo di stato o con importo negativo.');

                continue;
            }
            $effects = json_decode($row[8], true, flags: JSON_THROW_ON_ERROR);
            $this->assert(is_array($effects) && is_array($effects['source_lines'] ?? null) && is_array($effects['destination_expenses'] ?? null), 'Riprogrammazione con effects non validi.');
            $this->assert($effects['source_lines'] !== [] && $effects['destination_expenses'] !== [], 'Riprogrammazione priva di effects completi.');
            $sourceTotal = '0.00';
            foreach ($effects['source_lines'] as $effect) {
                $expenseRef = is_array($effect) ? ($effect['expense_ref'] ?? '') : '';
                $lineRef = is_array($effect) ? ($effect['line_ref'] ?? '') : '';
                $this->assert(isset($refs[$expenseRef], $refs[$lineRef], $expenses[$expenseRef], $lines[$lineRef]), 'Riprogrammazione con riferimenti origine orfani.');
                $this->assert($expenses[$expenseRef][1] === $row[2] && $expenses[$expenseRef][2] === $row[1] && $lines[$lineRef][1] === $expenseRef, 'Riprogrammazione con origine fuori Progetto/Esercizio.');
                $this->assert($lines[$lineRef][3] === ($effect['amount_after'] ?? null), 'Riprogrammazione non riconciliata con la Riga origine corrente.');
                $amountBefore = $effect['amount_before'] ?? null;
                $amountAfter = $effect['amount_after'] ?? null;
                $this->assert(is_string($amountBefore) && is_string($amountAfter) && $this->validMoney($amountBefore) && $this->validMoney($amountAfter), 'Importo origine della Riprogrammazione non valido.');
                $sourceTotal = bcadd($sourceTotal, bcsub($amountBefore, $amountAfter, 2), 2);
            }
            $destinationTotal = '0.00';
            foreach ($effects['destination_expenses'] as $effect) {
                $expenseRef = is_array($effect) ? ($effect['expense_ref'] ?? '') : '';
                $this->assert(isset($refs[$expenseRef], $expenses[$expenseRef]) && $expenses[$expenseRef][1] === $row[3] && $expenses[$expenseRef][2] === $row[1], 'Riprogrammazione con Spesa destinazione fuori Progetto/Esercizio.');
                $estimateLines = is_array($effect) ? ($effect['estimate_lines'] ?? null) : null;
                $this->assert(is_array($estimateLines) && $estimateLines !== [], 'Riprogrammazione con piano destinazione incompleto.');
                foreach ($estimateLines as $line) {
                    $lineRef = is_array($line) ? ($line['line_ref'] ?? '') : '';
                    $this->assert(isset($refs[$lineRef], $lines[$lineRef]) && $lines[$lineRef][1] === $expenseRef, 'Riprogrammazione con Riga destinazione orfana o fuori Spesa.');
                    $this->assert($lines[$lineRef][2] === 'estimate' && $lines[$lineRef][3] === ($line['amount'] ?? null), 'Piano destinazione non riconciliato con la Riga importata.');
                    $amount = $line['amount'] ?? null;
                    $this->assert(is_string($amount) && $this->validMoney($amount), 'Importo destinazione della Riprogrammazione non valido.');
                    $destinationTotal = bcadd($destinationTotal, $amount, 2);
                }
            }
            $this->assert(bccomp($row[7], $sourceTotal, 2) === 0 && bccomp($row[7], $destinationTotal, 2) === 0, 'Importo della Riprogrammazione non riconciliato con gli effetti.');
        }
    }

    /** @param array<string, array{columns: list<string>, rows: list<list<string>>}> $m */
    private function assertCorrections(array $m): void
    {
        $exercises = [];
        foreach ($m['_MP2_exercises']['rows'] as $row) {
            $exercises[$row[0]] = $row;
        }
        $closings = [];
        foreach ($m['_MP2_closings']['rows'] as $row) {
            $closings[$row[0]] = $row;
        }
        $expenses = [];
        foreach ($m['_MP2_expenses']['rows'] as $row) {
            $expenses[$row[0]] = $row;
        }
        $lines = [];
        foreach ($m['_MP2_expense_lines']['rows'] as $line) {
            $lines[$line[0]] = $line;
        }
        foreach ($m['_MP2_late_corrections']['rows'] as $row) {
            $sourceMatches = match ($row[9]) {
                'expense' => $row[10] === $row[3],
                'project' => ($expenses[$row[3]][2] ?? null) === $row[10],
                'contract' => ($expenses[$row[3]][3] ?? null) === $row[10],
                default => false,
            };
            $this->assert(
                $row[8] === '1' && ($closings[$row[2]][1] ?? null) === $row[1] && ($expenses[$row[3]][1] ?? null) === $row[1]
                && ($lines[$row[4]][1] ?? null) === $row[3] && ($lines[$row[4]][2] ?? null) === 'actual' && $sourceMatches,
                'Correzione tardiva fuori dal proprio contesto storico.',
            );
            if ($row[5] !== '') {
                $this->assert(($lines[$row[5]][1] ?? null) === $row[3] && ($lines[$row[5]][2] ?? null) === 'actual', 'Riga Effettivo originale della Correzione non valida.');
            }
        }
        foreach ($m['_MP2_error_annotations']['rows'] as $row) {
            $this->assert(($exercises[$row[1]][2] ?? null) === 'closed' && ($closings[$row[2]][1] ?? null) === $row[1], 'Annotazione fuori dal proprio contesto storico.');
            $versions = json_decode($row[6], true, flags: JSON_THROW_ON_ERROR);
            $facts = json_decode($row[7], true, flags: JSON_THROW_ON_ERROR);
            $this->assert(
                is_array($versions) && ($versions['recorded_facts'] ?? null) === '1'
                && ($versions['believed_correct_facts'] ?? null) === '1' && ($versions['affected_sources'] ?? null) === '1',
                'Versioni dell’Annotazione non supportate.',
            );
            $this->assert(is_array($facts) && is_array($facts['recorded'] ?? null) && $facts['recorded'] !== [] && is_array($facts['believed_correct'] ?? null) && $facts['believed_correct'] !== [], 'Fatti dell’Annotazione mancanti.');
            $sources = json_decode($row[8], true, flags: JSON_THROW_ON_ERROR);
            $this->assert(is_array($sources) && $sources !== [], 'Annotazione senza sorgenti interessate.');
            $sourceSheets = [
                'expense' => '_MP2_expenses', 'project' => '_MP2_projects', 'contract' => '_MP2_contracts',
                'supplier' => '_MP2_suppliers', 'cost_center' => '_MP2_cost_centers', 'exercise' => '_MP2_exercises',
                'closing_snapshot' => '_MP2_closings',
            ];
            foreach ($sources as $source) {
                $type = is_array($source) ? ($source['type'] ?? null) : null;
                $ref = is_array($source) ? ($source['ref'] ?? null) : null;
                $sheet = is_string($type) ? ($sourceSheets[$type] ?? null) : null;
                $exists = $sheet !== null && is_string($ref) && collect($m[$sheet]['rows'])->contains(fn (array $candidate): bool => $candidate[0] === $ref);
                $this->assert($exists && is_string($source['label'] ?? null) && $source['label'] !== '', 'Annotazione con sorgente non valida.');
            }
        }
    }

    /** @param array<string, string> $manifest
     * @param  array<string, array{columns: list<string>, rows: list<list<string>>}>  $m
     */
    private function preview(array $manifest, array $m): BackupPreview
    {
        $counts = [];
        foreach ($m as $sheet => $data) {
            $counts[$sheet] = count($data['rows']);
        }
        $attachmentCount = count($m['_MP2_attachments']['rows']);
        $nameCollision = Company::query()->where('name', $manifest['company_name'])->exists();
        $warnings = [];
        if ($attachmentCount > 0) {
            $warnings[] = "{$attachmentCount} allegati non saranno ripristinati. Il backup ne conserva l’inventario, ma il formato V1 non contiene i file originali.";
        }
        if ($nameCollision) {
            $warnings[] = "Esiste già un’Azienda denominata “{$manifest['company_name']}”. Il ripristino creerà una nuova Azienda indipendente. L’Azienda esistente non verrà modificata né unita ai dati importati.";
        }

        return new BackupPreview(
            $manifest['package_id'], 1, $manifest['company_name'], $manifest['company_timezone'], $manifest['exported_at'],
            $counts,
            array_map(fn (array $row): array => ['year' => (int) $row[1], 'status' => $row[2]], $m['_MP2_exercises']['rows']),
            $this->sum(array_column($m['_MP2_budgets']['rows'], 6)),
            $this->sum(array_column($m['_MP2_closings']['rows'], 8)),
            $attachmentCount,
            $nameCollision,
            $warnings,
        );
    }

    /** @param list<string> $row */
    private function dynamicPrefix(string $sheet, array $row, int $index): string
    {
        return match ($sheet) {
            '_MP2_budget_rows', '_MP2_closing_rows', '_MP2_late_corrections' => match ($row[$index === 4 ? 2 : ($sheet === '_MP2_late_corrections' ? 9 : 2)]) {
                'expense' => 'EXP', 'project' => 'PRJ', 'contract' => 'CTR', default => 'INVALID',
            },
            '_MP2_attachments' => match ($row[1]) {
                'expense' => 'EXP', 'expense_line' => 'LIN', 'contract' => 'CTR', 'historical_error_annotation' => 'ANN', default => 'INVALID',
            },
            default => 'INVALID',
        };
    }

    /** @return array{columns: list<string>, rows: list<list<string>>} */
    private function readUnknownSchema(?Worksheet $sheet, bool $hidden): array
    {
        $this->assert($sheet !== null, 'Foglio mancante.');
        $expectedState = $hidden ? Worksheet::SHEETSTATE_VERYHIDDEN : Worksheet::SHEETSTATE_VISIBLE;
        $this->assert($sheet->getSheetState() === $expectedState, "Visibilità non valida per il foglio [{$sheet->getTitle()}].");
        $lastColumn = $sheet->getHighestDataColumn();
        $columns = [];
        for ($column = 1; $column <= Coordinate::columnIndexFromString($lastColumn); $column++) {
            $columns[] = $this->cell($sheet, $column, 1);
        }
        $rows = [];
        for ($row = 2; $row <= $sheet->getHighestDataRow(); $row++) {
            $values = [];
            foreach (array_keys($columns) as $column) {
                $values[] = $this->cell($sheet, $column + 1, $row);
            }
            $rows[] = $values;
        }

        return ['columns' => $columns, 'rows' => $rows];
    }

    /** @param list<string> $columns
     * @return array{columns: list<string>, rows: list<list<string>>}
     */
    private function readExact(?Worksheet $sheet, array $columns, bool $hidden): array
    {
        $this->assert($sheet !== null, 'Foglio macchina mancante.');
        $data = $this->readUnknownSchema($sheet, $hidden);
        $this->assert($data['columns'] === $columns, "Header non valido in [{$sheet->getTitle()}].");

        return $data;
    }

    private function cell(Worksheet $sheet, int $column, int $row): string
    {
        $cell = $sheet->getCell([$column, $row]);
        $this->assert($cell->getDataType() !== DataType::TYPE_FORMULA, "Formula non ammessa in [{$sheet->getTitle()}].");
        $this->assert(in_array($cell->getDataType(), [DataType::TYPE_STRING, DataType::TYPE_INLINE, DataType::TYPE_NULL], true), "Cella non testuale in [{$sheet->getTitle()}].");

        return $cell->getValue() === null ? '' : (string) $cell->getValue();
    }

    /** @param list<string> $values */
    private function sum(array $values): string
    {
        return array_reduce($values, fn (string $carry, string $value): string => bcadd($carry, $value, 2), '0.00');
    }

    private function validTimestamp(string $value): bool
    {
        if (! preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:Z|[+-](?:[01]\d|2[0-3]):[0-5]\d)$/D', $value)) {
            return false;
        }

        $normalized = str_ends_with($value, 'Z') ? substr($value, 0, -1).'+00:00' : $value;
        $timestamp = \DateTimeImmutable::createFromFormat('!Y-m-d\TH:i:sP', $normalized);

        return $timestamp !== false && $timestamp->format('Y-m-d\TH:i:sP') === $normalized;
    }

    private function validDate(string $value): bool
    {
        if (! preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
            return false;
        }

        try {
            return CarbonImmutable::createFromFormat('!Y-m-d', $value)?->format('Y-m-d') === $value;
        } catch (\Throwable) {
            return false;
        }
    }

    private function validMoney(string $value): bool
    {
        return (bool) preg_match('/^-?\d+\.\d{2}$/', $value);
    }

    private function validUnsignedInteger(string $value, int $minimum = 0): bool
    {
        return (bool) preg_match('/^(0|[1-9]\d*)$/D', $value)
            && (int) $value >= $minimum
            && (int) $value <= 4_294_967_295;
    }

    private function assert(bool $condition, string $message): void
    {
        if (! $condition) {
            $this->fail($message);
        }
    }

    private function fail(string $message): never
    {
        throw ValidationException::withMessages(['backup' => $message]);
    }
}
