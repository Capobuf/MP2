<?php

namespace App\Support\Reporting;

use App\Domain\Expenses\Decimal;
use App\Domain\Reporting\ComparisonCategory;
use App\Domain\Reporting\ReportKind;
use App\Domain\Reporting\ReportResult;
use App\Domain\Reporting\ReportSource;
use App\Models\Company;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Number;
use Illuminate\Support\Str;

final class ReportPdfComposer
{
    /**
     * @param  array<string, mixed>  $configuration
     * @return array<string, mixed>
     */
    public function compose(ReportResult $result, Company $company, array $configuration = []): array
    {
        $sources = array_map(fn (ReportSource $source): array => $this->source($source), $result->sources);
        $comparisons = array_map(fn (array $row): array => $this->comparison($row), $result->comparisons);
        $sections = array_map(fn (array $section): array => [
            'id' => 'section:'.Str::slug((string) $section['title']),
            'title' => (string) $section['title'],
            'rows' => array_map(fn (mixed $row): mixed => $row instanceof ReportSource ? $this->source($row) : $this->normalizeValue($row), $section['rows']),
        ], $result->sections);
        $kpis = $this->kpiDefinitions($result, $sections);
        $charts = $this->staticCharts($this->chartDefinitions($result));
        $logo = $this->logoDataUri($company);

        $availableBlocks = [];
        if ($logo !== null) {
            $availableBlocks[] = $this->option('logo', 'Logo aziendale', 'logo');
        }
        foreach ($kpis as $kpi) {
            $availableBlocks[] = $this->option($kpi['id'], $kpi['label'], 'kpi');
        }
        foreach ($charts as $chart) {
            $availableBlocks[] = $this->option('chart:'.$chart['id'], $chart['heading'], 'chart');
        }
        if ($sources !== []) {
            $availableBlocks[] = $this->option('table:sources', 'Dettaglio e riconciliazione', 'table');
            $availableBlocks[] = $this->option('details:sources', 'Approfondimenti delle sorgenti', 'detail');
        }
        if ($comparisons !== []) {
            $availableBlocks[] = $this->option('table:comparisons', 'Confronto', 'table');
        }
        foreach ($sections as $section) {
            if ($section['rows'] !== []) {
                $availableBlocks[] = $this->option($section['id'], $section['title'], 'section');
            }
        }

        $availableColumns = [];
        if ($sources !== []) {
            foreach ([
                'cost_center' => 'Centro di costo', 'supplier' => 'Fornitore', 'state' => 'Stato',
                'allocation' => 'Allocato', 'actual' => 'Effettivo', 'operational_variance' => 'Scostamento',
                'carryover' => 'Riporto',
            ] as $key => $label) {
                $availableColumns[] = $this->option('column:sources:'.$key, $label, 'sources');
            }
        }
        if ($comparisons !== []) {
            foreach ([
                'initial_value' => 'Iniziale', 'final_value' => 'Finale', 'delta' => 'Delta',
                'category' => 'Categoria', 'dimensions' => 'Dimensioni', 'labels' => 'Etichette',
            ] as $key => $label) {
                $availableColumns[] = $this->option('column:comparisons:'.$key, $label, 'comparisons');
            }
        }

        $selectedBlocks = $this->selection($configuration, 'blocks', array_column($availableBlocks, 'id'));
        $selectedColumns = $this->selection($configuration, 'columns', array_column($availableColumns, 'id'));

        return [
            'definition' => $result->definition->toArray(),
            'header' => $result->header,
            'category_definitions' => array_map(fn (ComparisonCategory $category): array => [
                'label' => $category->label(), 'definition' => $category->definition(),
            ], ComparisonCategory::cases()),
            'kpis' => $kpis,
            'charts' => $charts,
            'sources' => $sources,
            'comparisons' => $comparisons,
            'sections' => $sections,
            'logo' => $logo,
            'available_blocks' => $availableBlocks,
            'available_columns' => $availableColumns,
            'selected_blocks' => $selectedBlocks,
            'selected_columns' => $selectedColumns,
        ];
    }

    /** @return array{id: string, label: string, group: string} */
    private function option(string $id, string $label, string $group): array
    {
        return compact('id', 'label', 'group');
    }

    /** @param array<string, mixed> $configuration
     * @param  array<int, string>  $available
     * @return array<int, string>
     */
    private function selection(array $configuration, string $key, array $available): array
    {
        if (! array_key_exists($key, $configuration) || $configuration[$key] === null) {
            return $available;
        }

        if (! is_array($configuration[$key])) {
            return [];
        }

        $requested = array_values(array_unique(array_filter($configuration[$key], 'is_string')));

        return array_values(array_intersect($available, $requested));
    }

    /** @return array<string, mixed> */
    private function source(ReportSource $source): array
    {
        return [
            'origin_key' => $source->originKey,
            'label' => $source->label,
            'summary' => $source->summary,
            'cost_center' => $source->costCenterLabel ?? 'Non classificato',
            'supplier' => $source->supplierLabel ?? 'Senza fornitore',
            'state' => $source->state,
            'allocation' => $source->allocation,
            'actual' => $source->actual,
            'operational_variance' => Decimal::subtract($source->actual, $source->allocation),
            'carryover' => $source->carryover,
            'detail' => $this->normalizeValue($source->detail),
            'corrections' => $this->normalizeValue($source->corrections),
            'annotations' => $this->normalizeValue($source->annotations),
        ];
    }

    /**
     * @param  array<int, array<string, mixed>>  $sections
     * @return array<int, array{id: string, label: string, value: string|int|float, formatted: string}>
     */
    private function kpiDefinitions(ReportResult $result, array $sections): array
    {
        $items = [];
        $kind = $result->definition->kind;
        $totals = $result->totals;
        $availability = $result->header['availability'];

        if ($kind === ReportKind::AnnualExecutive) {
            $items = [
                ['current_budget', 'Budget Approvato Corrente', $availability['current_budget'] ? $totals['current_budget'] : 'Non disponibile', true],
                ['current_allocation', 'Allocato Corrente', $totals['current_allocation'], true],
                ['selected_actual', (string) $result->header['actual_reference'], $totals['selected_actual'], true],
                ['current_operational_variance', 'Scostamento Operativo', $totals['current_operational_variance'], true],
                ['current_actual', 'Effettivo Corrente', $totals['current_actual'], true],
            ];
            if ($availability['selected_budget']) {
                $items[] = ['allocation_vs_selected_budget', 'Variazione Allocato vs Budget Selezionato', $totals['allocation_vs_selected_budget'], true];
                $items[] = ['selected_budget_actual_variance', 'Varianza Budget vs Actual Selezionato', $totals['selected_budget_actual_variance'], true];
            }
            if ($availability['closing']) {
                foreach ([
                    ['closing_actual', 'Effettivo alla Chiusura'],
                    ['late_corrections_positive', 'Correzioni Tardive Positive'],
                    ['late_corrections_negative', 'Correzioni Tardive Negative'],
                    ['late_corrections_net', 'Correzioni Tardive Nette'],
                    ['current_knowledge_actual', 'Effettivo a Conoscenza Corrente'],
                ] as [$key, $label]) {
                    $items[] = [$key, $label, $totals[$key], true];
                }
            }
            $items[] = ['unclassified', 'Non Classificato', $totals['unclassified'], true];
            $items[] = ['source_count', 'Sorgenti Primarie', $totals['source_count'], false];
            $items[] = ['annotation_count', 'Annotazioni di Errore Storico', $totals['annotation_count'], false];
        } elseif (in_array($kind, [
            ReportKind::BudgetActual, ReportKind::BudgetCurrentAllocation,
            ReportKind::BudgetVersions, ReportKind::Exercises,
        ], true)) {
            $comparison = $this->comparisonTotals($result->comparisons);
            $deltaLabel = match ($kind) {
                ReportKind::BudgetActual => 'Varianza Budget vs Actual',
                ReportKind::BudgetCurrentAllocation => 'Variazione Allocato vs Budget',
                ReportKind::BudgetVersions => 'Variazione fra Budget',
                default => 'Delta Complessivo',
            };
            $items = [
                ['comparison_initial', (string) $result->header['initial_reference_label'], $comparison['initial'], true],
                ['comparison_final', (string) $result->header['final_reference_label'], $comparison['final'], true],
                ['comparison_delta', $deltaLabel, $comparison['delta'], true],
                ['comparison_source_count', 'Sorgenti Confrontate', $comparison['source_count'], false],
            ];
        } elseif ($kind === ReportKind::OperationalVariance) {
            $items = [
                ['allocation', 'Allocato Corrente', $totals['allocation'], true],
                ['actual', 'Effettivo Corrente', $totals['actual'], true],
                ['operational_variance', 'Scostamento Operativo', $totals['operational_variance'], true],
                ['source_count', 'Sorgenti Primarie', $totals['source_count'], false],
            ];
        } else {
            $specialist = $this->specialistTotals($kind, $sections);
            if ($kind === ReportKind::Suppliers) {
                $items = [
                    ['specialist_allocation', 'Allocato Aggregato', $specialist['allocation'], true],
                    ['specialist_actual', 'Effettivo Aggregato', $specialist['actual'], true],
                    ['specialist_variance', 'Scostamento Operativo', $specialist['operational_variance'], true],
                    ['specialist_count', 'Bucket Fornitore', $specialist['item_count'], false],
                ];
            } elseif (in_array($kind, [ReportKind::Projects, ReportKind::Contracts], true)) {
                $items = [
                    ['specialist_allocation', 'Allocato', $specialist['allocation'], true],
                    ['specialist_actual', 'Effettivo', $specialist['actual'], true],
                    ['specialist_variance', 'Scostamento Operativo', $specialist['operational_variance'], true],
                    ['specialist_count', $kind === ReportKind::Projects ? 'Progetti' : 'Contratti', $specialist['item_count'], false],
                ];
            } elseif ($kind === ReportKind::Carryovers) {
                $items = [
                    ['specialist_carryover', 'Riporto', $specialist['carryover'], true],
                    ['specialist_allocation', 'Allocato', $specialist['allocation'], true],
                    ['specialist_actual', 'Effettivo', $specialist['actual'], true],
                    ['specialist_count', 'Progetti con Riporto', $specialist['item_count'], false],
                ];
            }
        }

        return array_map(fn (array $item): array => [
            'id' => 'kpi:'.$item[0],
            'label' => $item[1],
            'value' => $item[2],
            'formatted' => $item[3] && is_numeric($item[2])
                ? Number::currency((float) $item[2], in: 'EUR', locale: 'it')
                : (string) $item[2],
        ], $items);
    }

    /** @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function comparison(array $row): array
    {
        return [
            'origin_key' => $row['origin_key'], 'label' => $row['label'],
            'initial_value' => $row['initial_value'], 'final_value' => $row['final_value'], 'delta' => $row['delta'],
            'category' => $row['category']->label(),
            'dimensions' => array_map(fn ($value): string => $value->label(), $row['dimensions']),
            'labels' => array_map(fn ($value): string => $value->label(), $row['labels']),
            'insufficiently_explained' => $row['insufficiently_explained'],
        ];
    }

    /**
     * @param  array<int, array<string, mixed>>  $comparisons
     * @return array{initial: string, final: string, delta: string, source_count: int}
     */
    public function comparisonTotals(array $comparisons): array
    {
        $initial = Decimal::sum(array_column($comparisons, 'initial_value'));
        $final = Decimal::sum(array_column($comparisons, 'final_value'));

        return [
            'initial' => $initial,
            'final' => $final,
            'delta' => Decimal::subtract($final, $initial),
            'source_count' => count($comparisons),
        ];
    }

    /**
     * @param  array<int, array{title: string, rows: array<int, array<string, mixed>>}>  $sections
     * @return array<string, string|int>
     */
    public function specialistTotals(ReportKind $kind, array $sections): array
    {
        if (! in_array($kind, [ReportKind::Suppliers, ReportKind::Contracts, ReportKind::Projects, ReportKind::Carryovers], true)) {
            return [];
        }

        $rows = $sections[0]['rows'] ?? [];

        return [
            'allocation' => Decimal::sum(array_column($rows, 'allocation')),
            'actual' => Decimal::sum(array_column($rows, 'actual')),
            'operational_variance' => Decimal::sum(array_column($rows, 'operational_variance')),
            'carryover' => Decimal::sum(array_column($rows, 'carryover')),
            'item_count' => count($rows),
        ];
    }

    /** @return array<int, array<string, mixed>> */
    public function chartDefinitions(ReportResult $result): array
    {
        $charts = [];
        $kind = $result->definition->kind;

        if ($kind === ReportKind::AnnualExecutive) {
            $labels = [];
            $values = [];
            $availability = $result->header['availability'];
            foreach ([
                ['initial_budget', 'Budget Iniziale', (bool) $availability['initial_budget']],
                ['current_budget', 'Budget Corrente', (bool) $availability['current_budget']],
                ['current_allocation', 'Allocato Corrente', true],
                ['selected_actual', (string) $result->header['actual_reference'], true],
            ] as [$key, $label, $available]) {
                if ($available) {
                    $labels[] = $label;
                    $values[] = (float) $result->totals[$key];
                }
            }
            $charts[] = $this->currencyBarChart(
                'annual-summary', 'Sintesi Economica',
                'Riferimenti Economici Esplicitamente Disponibili per l’Esercizio.', $labels, $values,
            );

            $costCenters = [];
            foreach ($result->sources as $source) {
                $key = $source->costCenterId === null ? 'unclassified' : 'cost-center:'.$source->costCenterId;
                $costCenters[$key] ??= ['label' => $source->costCenterLabel ?? 'Non classificato', 'allocation' => '0.00', 'actual' => '0.00'];
                $costCenters[$key]['allocation'] = Decimal::add($costCenters[$key]['allocation'], $source->allocation);
                $costCenters[$key]['actual'] = Decimal::add($costCenters[$key]['actual'], $source->actual);
            }
            if ($costCenters !== []) {
                $charts[] = $this->groupedBarChart(
                    'annual-cost-centers', 'Allocato ed Effettivo per Centro di Costo',
                    'Tutte le Sorgenti del Risultato; Non Classificato Resta un Bucket Esplicito.',
                    array_column($costCenters, 'label'),
                    [
                        ['label' => 'Allocato', 'data' => array_map('floatval', array_column($costCenters, 'allocation')), 'color' => '#39D5C4'],
                        ['label' => (string) $result->header['actual_reference'], 'data' => array_map('floatval', array_column($costCenters, 'actual')), 'color' => '#60A5FA'],
                    ],
                );
            }
            $categoryChart = $this->categoryChart($result);
            if ($categoryChart !== null) {
                $charts[] = $categoryChart;
            }
        } elseif (in_array($kind, [
            ReportKind::BudgetActual, ReportKind::BudgetCurrentAllocation,
            ReportKind::BudgetVersions, ReportKind::Exercises,
        ], true)) {
            $initial = Decimal::sum(array_column($result->comparisons, 'initial_value'));
            $final = Decimal::sum(array_column($result->comparisons, 'final_value'));
            $charts[] = $this->currencyBarChart(
                'comparison-totals', 'Confronto Complessivo',
                'Somma Esatta dei Valori Iniziali e Finali delle Sorgenti Confrontate.',
                [
                    (string) ($result->header['initial_reference_label'] ?? $result->header['initial_reference']),
                    (string) ($result->header['final_reference_label'] ?? $result->header['final_reference']),
                ],
                [(float) $initial, (float) $final],
            );
            $categoryChart = $this->categoryChart($result);
            if ($categoryChart !== null) {
                $charts[] = $categoryChart;
            }
        } elseif ($kind === ReportKind::OperationalVariance && $result->sources !== []) {
            $charts[] = [
                'id' => 'operational-variance', 'heading' => 'Scostamento Operativo per Sorgente',
                'description' => 'Effettivo Corrente meno Allocato Corrente.',
                'type' => 'bar', 'variant' => 'variance-horizontal',
                'data' => [
                    'labels' => array_map(fn (ReportSource $source): string => $source->label, $result->sources),
                    'datasets' => [[
                        'label' => 'Scostamento Operativo',
                        'data' => array_map(fn (ReportSource $source): float => (float) Decimal::subtract($source->actual, $source->allocation), $result->sources),
                        'backgroundColor' => array_map(fn (ReportSource $source): string => match (Decimal::compare(Decimal::subtract($source->actual, $source->allocation), '0.00')) {
                            1 => '#EF4444', -1 => '#60A5FA', default => '#91A3A8',
                        }, $result->sources),
                        'borderRadius' => 5, 'borderSkipped' => false,
                    ]],
                ],
            ];
        } elseif (in_array($kind, [ReportKind::Projects, ReportKind::Contracts], true)) {
            $type = $kind === ReportKind::Projects ? 'project' : 'contract';
            $sources = array_values(array_filter($result->sources, fn (ReportSource $source): bool => $source->sourceType === $type));
            if ($sources !== []) {
                $charts[] = $this->groupedBarChart(
                    $type.'-values', ($kind === ReportKind::Projects ? 'Progetti' : 'Contratti').' · Allocato ed Effettivo',
                    'Valori delle Sorgenti Pertinenti Presenti nel Risultato.',
                    array_map(fn (ReportSource $source): string => $source->label, $sources),
                    [
                        ['label' => 'Allocato', 'data' => array_map(fn (ReportSource $source): float => (float) $source->allocation, $sources), 'color' => '#39D5C4'],
                        ['label' => 'Effettivo', 'data' => array_map(fn (ReportSource $source): float => (float) $source->actual, $sources), 'color' => '#60A5FA'],
                    ],
                );
            }
        } elseif ($kind === ReportKind::Suppliers && ($result->sections[0]['rows'] ?? []) !== []) {
            $rows = $result->sections[0]['rows'];
            $charts[] = $this->groupedBarChart(
                'supplier-values', 'Allocato ed Effettivo per Fornitore',
                'Aggregazione Canonica Già Prodotta dal Report Fornitori.', array_column($rows, 'label'),
                [
                    ['label' => 'Allocato', 'data' => array_map('floatval', array_column($rows, 'allocation')), 'color' => '#39D5C4'],
                    ['label' => 'Effettivo', 'data' => array_map('floatval', array_column($rows, 'actual')), 'color' => '#60A5FA'],
                ],
            );
        } elseif ($kind === ReportKind::Carryovers) {
            $sources = array_values(array_filter($result->sources, fn (ReportSource $source): bool => $source->sourceType === 'project'));
            if ($sources !== []) {
                $charts[] = $this->groupedBarChart(
                    'carryover-values', 'Riporti per Progetto',
                    'Riporto Insieme ad Allocato ed Effettivo Già Disponibili nel Risultato.',
                    array_map(fn (ReportSource $source): string => $source->label, $sources),
                    [
                        ['label' => 'Riporto', 'data' => array_map(fn (ReportSource $source): float => (float) $source->carryover, $sources), 'color' => '#F59E0B'],
                        ['label' => 'Allocato', 'data' => array_map(fn (ReportSource $source): float => (float) $source->allocation, $sources), 'color' => '#39D5C4'],
                        ['label' => 'Effettivo', 'data' => array_map(fn (ReportSource $source): float => (float) $source->actual, $sources), 'color' => '#60A5FA'],
                    ],
                );
            }
        }

        return $charts;
    }

    /** @param array<int, string> $labels
     * @param  array<int, float>  $values
     * @return array<string, mixed>
     */
    private function currencyBarChart(string $id, string $heading, string $description, array $labels, array $values): array
    {
        return [
            'id' => $id, 'heading' => $heading, 'description' => $description,
            'type' => 'bar', 'variant' => 'currency-bar',
            'data' => ['labels' => $labels, 'datasets' => [[
                'label' => 'Importo', 'data' => $values,
                'backgroundColor' => ['#91A3A8', '#39D5C4', '#60A5FA', '#F59E0B'],
                'borderRadius' => 6, 'borderSkipped' => false,
            ]]],
        ];
    }

    /** @param array<int, string> $labels
     * @param  array<int, array{label: string, data: array<int, float>, color: string}>  $series
     * @return array<string, mixed>
     */
    private function groupedBarChart(string $id, string $heading, string $description, array $labels, array $series): array
    {
        return [
            'id' => $id, 'heading' => $heading, 'description' => $description,
            'type' => 'bar', 'variant' => 'grouped-horizontal',
            'data' => [
                'labels' => $labels,
                'datasets' => array_map(fn (array $item): array => [
                    'label' => $item['label'], 'data' => $item['data'], 'backgroundColor' => $item['color'],
                    'borderRadius' => 4, 'borderSkipped' => false,
                ], $series),
            ],
        ];
    }

    /** @return array<string, mixed>|null */
    private function categoryChart(ReportResult $result): ?array
    {
        if ($result->comparisons === [] || array_sum($result->categoryCounts) === 0) {
            return null;
        }
        $categories = ComparisonCategory::cases();

        return [
            'id' => 'comparison-categories', 'heading' => 'Classificazione delle Variazioni',
            'description' => count($result->comparisons).' Sorgenti Primarie Confrontate.',
            'type' => 'doughnut', 'variant' => 'category-doughnut',
            'data' => [
                'labels' => array_map(fn (ComparisonCategory $category): string => $category->label(), $categories),
                'datasets' => [[
                    'label' => 'Sorgenti',
                    'data' => array_map(fn (ComparisonCategory $category): int => (int) ($result->categoryCounts[$category->value] ?? 0), $categories),
                    'backgroundColor' => ['#39D5C4', '#60A5FA', '#EF4444', '#F59E0B'],
                    'borderColor' => '#0B1D25', 'borderWidth' => 3, 'hoverOffset' => 8,
                ]],
            ],
        ];
    }

    /**
     * @param  array<int, array<string, mixed>>  $definitions
     * @return array<int, array{id: string, heading: string, description: string, image: string}>
     */
    private function staticCharts(array $definitions): array
    {
        return array_map(function (array $chart): array {
            $datasets = array_map(function (array $dataset): array {
                $colors = $dataset['backgroundColor'];

                return [
                    'label' => (string) $dataset['label'],
                    'values' => array_map('floatval', $dataset['data']),
                    'colors' => is_array($colors) ? array_values($colors) : [(string) $colors],
                ];
            }, $chart['data']['datasets']);

            return $this->chart(
                (string) $chart['id'],
                (string) $chart['heading'],
                (string) $chart['description'],
                array_map('strval', $chart['data']['labels']),
                $datasets,
            );
        }, $definitions);
    }

    /**
     * @param  array<int, string>  $labels
     * @param  array<int, array{label: string, values: array<int, float>, colors: array<int, string>}>  $series
     * @return array{id: string, heading: string, description: string, image: string}
     */
    private function chart(string $id, string $heading, string $description, array $labels, array $series): array
    {
        $rowHeight = 34;
        $height = max(130, 58 + count($labels) * $rowHeight);
        $plotX = 190;
        $plotWidth = 520;
        $max = max(1.0, ...array_map('abs', array_merge(...array_column($series, 'values'))));
        $svg = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 760 '.$height.'">';
        $svg .= '<rect width="760" height="'.$height.'" fill="#ffffff"/>';
        foreach ($series as $index => $dataset) {
            $svg .= '<rect x="'.($plotX + $index * 125).'" y="12" width="12" height="12" rx="2" fill="'.$dataset['colors'][0].'"/>';
            $svg .= '<text x="'.($plotX + 18 + $index * 125).'" y="22" font-family="sans-serif" font-size="11" fill="#33484b">'.$this->escape($dataset['label']).'</text>';
        }
        foreach ($labels as $row => $label) {
            $y = 44 + $row * $rowHeight;
            $svg .= '<text x="6" y="'.($y + 13).'" font-family="sans-serif" font-size="10" fill="#33484b">'.$this->escape(mb_strimwidth($label, 0, 30, '…')).'</text>';
            foreach ($series as $index => $dataset) {
                $value = $dataset['values'][$row] ?? 0.0;
                $width = abs($value) / $max * ($plotWidth - 145);
                $barY = $y + $index * 13;
                $color = $dataset['colors'][$row] ?? $dataset['colors'][0];
                $svg .= '<rect x="'.$plotX.'" y="'.$barY.'" width="'.round($width, 2).'" height="10" rx="2" fill="'.$color.'"/>';
                $svg .= '<text x="'.($plotX + $width + 5).'" y="'.($barY + 9).'" font-family="sans-serif" font-size="9" fill="#33484b">'.$this->escape((string) $value).'</text>';
            }
        }
        $svg .= '</svg>';

        return compact('id', 'heading', 'description') + ['image' => 'data:image/svg+xml;base64,'.base64_encode($svg)];
    }

    private function logoDataUri(Company $company): ?string
    {
        $disk = $company->getAttribute('logo_disk');
        $path = $company->getAttribute('logo_path');
        $mediaType = $company->getAttribute('logo_media_type');
        if (! is_string($disk) || ! is_string($path) || ! in_array($mediaType, ['image/png', 'image/jpeg'], true)) {
            return null;
        }
        $contents = Storage::disk($disk)->get($path);
        if (! is_string($contents)) {
            return null;
        }

        return 'data:'.$mediaType.';base64,'.base64_encode($contents);
    }

    private function normalizeValue(mixed $value): mixed
    {
        if (is_array($value)) {
            return array_map(fn (mixed $item): mixed => $this->normalizeValue($item), $value);
        }
        if ($value instanceof \BackedEnum) {
            return method_exists($value, 'label') ? $value->label() : $value->value;
        }
        if ($value instanceof \Stringable) {
            return (string) $value;
        }

        return $value;
    }

    private function escape(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE | ENT_XML1, 'UTF-8');
    }
}
