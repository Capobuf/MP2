<?php

namespace App\Support\Reporting;

use App\Domain\Contracts\ContractAttributionMode;
use App\Domain\Contracts\ContractCycleType;
use App\Domain\Contracts\ContractState;
use App\Domain\Expenses\Decimal;
use App\Domain\Projects\ProjectState;
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
    public function __construct(private readonly ReportChartDefinitions $definitions) {}

    /**
     * @param  array<string, mixed>  $configuration
     * @return array<string, mixed>
     */
    public function compose(ReportResult $result, Company $company, array $configuration = []): array
    {
        $orientation = $this->orientation($configuration);
        $kind = $result->definition->kind;
        $sources = array_map(fn (ReportSource $source): array => $this->source($source), $result->sources);
        $comparisons = array_map(fn (array $row): array => $this->comparison($row, $kind), $result->comparisons);
        $sections = array_map(fn (array $section): array => [
            'id' => 'section:'.Str::slug((string) $section['title']),
            'title' => (string) $section['title'],
            'rows' => array_map(fn (mixed $row): mixed => $row instanceof ReportSource ? $this->source($row) : $this->normalizeValue($row), $section['rows']),
        ], $result->sections);
        $isContracts = $result->definition->kind === ReportKind::Contracts;
        $contracts = $isContracts
            ? array_map(fn (array $row): array => $this->contract($row), $sections[0]['rows'] ?? [])
            : [];
        $stateCounts = collect($contracts)->countBy('state');
        $contractStateCounts = $isContracts
            ? array_map(fn (ContractState $state): array => [
                'state' => $state->value,
                'label' => $state->label(),
                'count' => (int) $stateCounts->get($state->value, 0),
            ], ContractState::cases())
            : [];
        $kpis = $this->kpiDefinitions($result, $sections);
        $charts = $this->staticCharts($this->chartDefinitions($result, $orientation), $orientation);
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
        if ($isContracts && $contracts !== []) {
            $availableBlocks[] = $this->option('table:contracts', 'Elenco contratti', 'table');
            $availableBlocks[] = $this->option('details:contracts', 'Approfondimenti contratti', 'detail');
        } elseif (! $isContracts && $sources !== []) {
            $sourceTableLabel = match ($kind) {
                ReportKind::AnnualExecutive => 'Riconciliazione delle sorgenti',
                ReportKind::OperationalVariance => 'Scostamento per sorgente',
                default => 'Dettaglio e riconciliazione',
            };
            $availableBlocks[] = $this->option('table:sources', $sourceTableLabel, 'table');
            $availableBlocks[] = $this->option('details:sources', 'Approfondimenti delle sorgenti', 'detail');
        }
        if ($kind === ReportKind::AnnualExecutive && $result->costCenters !== []) {
            $availableBlocks[] = $this->option('table:cost-centers', 'Analisi per Centro di Costo', 'table');
        }
        if ($comparisons !== []) {
            $availableBlocks[] = $this->option('table:comparisons', 'Confronto', 'table');
        }
        foreach ($sections as $section) {
            if (! $isContracts && $section['rows'] !== []) {
                $availableBlocks[] = $this->option($section['id'], $section['title'], 'section');
            }
        }

        $availableColumns = [];
        if ($isContracts) {
            foreach ([
                'supplier' => 'Fornitore',
                'state' => 'Stato',
                'cost_center' => 'Centro di costo',
                'deadline' => 'Scadenza',
                'notice_limit_date' => 'Limite preavviso',
                'renewal' => 'Rinnovo',
                'allocation' => 'Allocato',
                'actual' => 'Effettivo',
                'operational_variance' => 'Scostamento',
            ] as $key => $label) {
                $availableColumns[] = $this->option('column:contracts:'.$key, $label, 'contracts');
            }
        } elseif ($sources !== []) {
            $sourceColumns = $kind === ReportKind::OperationalVariance
                ? [
                    'state' => 'Stato', 'allocation' => 'Allocato Corrente',
                    'actual' => 'Effettivo Corrente', 'operational_variance' => 'Scostamento Operativo',
                ]
                : [
                    'cost_center' => 'Centro di costo', 'supplier' => 'Fornitore', 'state' => 'Stato',
                    'allocation' => 'Allocato', 'actual' => 'Effettivo', 'operational_variance' => 'Scostamento',
                    'carryover' => 'Riporto',
                ];
            foreach ($sourceColumns as $key => $label) {
                $availableColumns[] = $this->option('column:sources:'.$key, $label, 'sources');
            }
        }
        if ($comparisons !== []) {
            [$initialColumnLabel, $finalColumnLabel] = match ($kind) {
                ReportKind::BudgetActual => ['Budget', (string) ($result->header['actual_reference'] ?? 'Actual')],
                ReportKind::BudgetCurrentAllocation => ['Budget', 'Allocato Corrente'],
                ReportKind::BudgetVersions => ['Budget Iniziale', 'Budget Finale'],
                ReportKind::Exercises => ['Esercizio Iniziale', 'Esercizio Finale'],
                default => ['Iniziale', 'Finale'],
            };
            foreach ([
                'initial_value' => $initialColumnLabel, 'final_value' => $finalColumnLabel, 'delta' => 'Delta',
                'category' => 'Categoria', 'dimensions' => 'Dimensioni', 'labels' => 'Etichette',
            ] as $key => $label) {
                $availableColumns[] = $this->option('column:comparisons:'.$key, $label, 'comparisons');
            }
        }

        $blockIds = array_column($availableBlocks, 'id');
        $defaultBlocks = array_values(array_diff($blockIds, ['details:contracts', 'details:sources']));
        if ($kind === ReportKind::AnnualExecutive) {
            $defaultBlocks = array_values(array_diff($defaultBlocks, ['table:comparisons']));
        }
        if (in_array($kind, [
            ReportKind::BudgetActual,
            ReportKind::BudgetCurrentAllocation,
            ReportKind::BudgetVersions,
            ReportKind::Exercises,
            ReportKind::Projects,
            ReportKind::Carryovers,
            ReportKind::Suppliers,
        ], true)) {
            $defaultBlocks = array_values(array_diff($defaultBlocks, ['table:sources']));
        }
        $selectedBlocks = $this->selection($configuration, 'blocks', $blockIds, $defaultBlocks);
        $selectedColumns = $this->selection($configuration, 'columns', array_column($availableColumns, 'id'));

        return [
            'orientation' => $orientation,
            'definition' => $result->definition->toArray(),
            'header' => $result->header,
            'category_definitions' => $comparisons === [] ? [] : array_map(fn (ComparisonCategory $category): array => [
                'label' => $category->label(), 'definition' => $category->definition(),
            ], ComparisonCategory::cases()),
            'kpis' => $kpis,
            'charts' => $charts,
            'sources' => $sources,
            'cost_centers' => $result->costCenters,
            'comparisons' => $comparisons,
            'sections' => $sections,
            'contracts' => $contracts,
            'contract_state_counts' => $contractStateCounts,
            'logo' => $logo,
            'available_blocks' => $availableBlocks,
            'available_columns' => $availableColumns,
            'selected_blocks' => $selectedBlocks,
            'selected_columns' => $selectedColumns,
            'source_table_title' => $sourceTableLabel ?? 'Dettaglio e riconciliazione',
            'comparison_table_title' => match ($kind) {
                ReportKind::BudgetActual => 'Budget e Actual per sorgente',
                ReportKind::BudgetCurrentAllocation => 'Evoluzione del piano per sorgente',
                ReportKind::BudgetVersions => 'Variazioni fra le versioni',
                ReportKind::Exercises => 'Confronto della misura per sorgente',
                default => 'Confronto',
            },
        ];
    }

    /**
     * @param  array<string, mixed>  $configuration
     */
    private function orientation(array $configuration): string
    {
        $orientation = $configuration['orientation'] ?? 'landscape';

        if (! is_string($orientation) || ! in_array($orientation, ['portrait', 'landscape'], true)) {
            throw new \InvalidArgumentException('Invalid PDF orientation.');
        }

        return $orientation;
    }

    /** @return array{id: string, label: string, group: string} */
    private function option(string $id, string $label, string $group): array
    {
        return compact('id', 'label', 'group');
    }

    /** @param array<string, mixed> $configuration
     * @param  array<int, string>  $available
     * @param  array<int, string>|null  $default
     * @return array<int, string>
     */
    private function selection(array $configuration, string $key, array $available, ?array $default = null): array
    {
        if (! array_key_exists($key, $configuration) || $configuration[$key] === null) {
            return $default ?? $available;
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
            'state_label' => match ($source->sourceType) {
                'contract' => $source->state === null ? '—' : ContractState::from($source->state)->label(),
                'project' => $source->state === null ? '—' : ProjectState::from($source->state)->label(),
                default => match ($source->state) {
                    'active' => 'Attivo', 'reversed' => 'Stornata', null => '—',
                    default => $source->state,
                },
            },
            'allocation' => $source->allocation,
            'actual' => $source->actual,
            'operational_variance' => Decimal::subtract($source->actual, $source->allocation),
            'carryover' => $source->carryover,
            'residual' => $source->residual,
            'saving' => $source->saving,
            'unused' => $source->unused,
            'detail' => $this->normalizeValue($source->detail),
            'corrections' => $this->normalizeValue($source->corrections),
            'annotations' => $this->normalizeValue($source->annotations),
        ];
    }

    /**
     * @param  array<int, array<string, mixed>>  $sections
     * @return array<int, array{id: string, label: string, value: string|int|float, formatted: string, description: ?string, group: string}>
     */
    private function kpiDefinitions(ReportResult $result, array $sections): array
    {
        $items = [];
        $kind = $result->definition->kind;
        $totals = $result->totals;
        $availability = $result->header['availability'];

        if ($kind === ReportKind::AnnualExecutive) {
            $items = [
                ['initial_budget', 'Budget Iniziale Approvato', $availability['initial_budget'] ? $totals['initial_budget'] : 'Non disponibile', true],
                ['current_budget', 'Budget Approvato Corrente', $availability['current_budget'] ? $totals['current_budget'] : 'Non disponibile', true],
                ['current_allocation', 'Allocato Corrente', $totals['current_allocation'], true],
                ['selected_actual', (string) $result->header['actual_reference'], $totals['selected_actual'], true],
                ['current_operational_variance', 'Scostamento Operativo', $totals['current_operational_variance'], true],
            ];
            $items[] = ['current_actual', 'Effettivo Corrente', $totals['current_actual'], true];
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
            } elseif ($kind === ReportKind::Contracts) {
                $items = [
                    ['specialist_count', 'Contratti', $specialist['item_count'], false],
                    ['specialist_allocation', 'Allocato', $specialist['allocation'], true],
                    ['specialist_actual', 'Effettivo', $specialist['actual'], true],
                    ['specialist_variance', 'Scostamento operativo', $specialist['operational_variance'], true],
                ];
            } elseif ($kind === ReportKind::Projects) {
                $items = [
                    ['specialist_allocation', 'Allocato', $specialist['allocation'], true],
                    ['specialist_actual', 'Effettivo', $specialist['actual'], true],
                    ['specialist_variance', 'Scostamento Operativo', $specialist['operational_variance'], true],
                    ['specialist_count', 'Progetti', $specialist['item_count'], false],
                ];
            } elseif ($kind === ReportKind::Carryovers) {
                $items = [
                    ['specialist_carryover', 'Riporto', $specialist['carryover'], true],
                    ['specialist_allocation', 'Allocato', $specialist['allocation'], true],
                    ['specialist_actual', 'Effettivo', $specialist['actual'], true],
                    ['specialist_count', 'Progetti Analizzati', $specialist['item_count'], false],
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
            'description' => null,
            'group' => ! $item[3] ? 'context' : (in_array($item[0], [
                'initial_budget', 'current_budget', 'current_allocation', 'selected_actual', 'comparison_initial', 'comparison_final',
                'comparison_delta', 'allocation', 'actual', 'operational_variance',
                'specialist_carryover', 'specialist_allocation', 'specialist_actual', 'specialist_variance',
            ], true) ? 'economic' : 'secondary'),
        ], $items);
    }

    /** @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function comparison(array $row, ReportKind $kind): array
    {
        /** @var ReportSource|null $displaySource */
        $displaySource = $row['final_source'] ?? $row['initial_source'] ?? null;
        $hideActualSemantics = in_array($kind, [ReportKind::BudgetCurrentAllocation, ReportKind::BudgetVersions], true);
        $dimensions = $hideActualSemantics
            ? array_values(array_filter($row['dimensions'], fn ($value): bool => $value->value !== 'actual'))
            : $row['dimensions'];
        $labels = $hideActualSemantics
            ? array_values(array_filter($row['labels'], fn ($value): bool => ! in_array($value->value, [
                'planned_not_occurred', 'without_actuals', 'late_correction',
            ], true)))
            : $row['labels'];

        return [
            'origin_key' => $row['origin_key'], 'label' => $row['label'],
            'source_type' => $displaySource?->sourceType,
            'cost_center' => $displaySource?->costCenterLabel,
            'supplier' => $displaySource?->supplierLabel,
            'initial_value' => $row['initial_value'], 'final_value' => $row['final_value'], 'delta' => $row['delta'],
            'category' => $row['category']->label(),
            'dimensions' => array_map(fn ($value): string => $value->label(), $dimensions),
            'labels' => array_map(fn ($value): string => $value->label(), $labels),
            'derived_from_origin_key' => $row['derived_from_origin_key'],
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
    public function chartDefinitions(ReportResult $result, string $orientation = 'landscape'): array
    {
        $charts = $this->definitions->definitions($result);

        foreach ($charts as &$chart) {
            if ($chart['id'] === 'contract-values') {
                $total = count($chart['data']['labels']);
                $indices = $this->orderedChartIndices($chart, false);
                $indices = array_slice($indices, 0, $orientation === 'portrait' ? 5 : 8);
                $this->selectChartRows($chart, $indices);
                $chart['render_description'] = count($indices) < $total
                    ? 'Visualizzati '.count($indices).' di '.$total.' contratti · ordinati per Allocato decrescente.'
                    : 'Contratti ordinati per Allocato decrescente.';
            }
        }
        unset($chart);

        foreach ($charts as &$chart) {
            $subject = match ($chart['id']) {
                'annual-cost-centers' => 'centri di costo',
                'operational-variance' => 'sorgenti',
                'project-values', 'carryover-values' => 'progetti',
                'supplier-values' => 'fornitori',
                default => null,
            };
            if ($subject === null) {
                continue;
            }
            $variance = $chart['id'] === 'operational-variance';
            $indices = $this->orderedChartIndices($chart, $variance);
            $total = count($indices);
            $indices = array_slice($indices, 0, $orientation === 'portrait' ? 5 : 8);
            $order = $variance ? 'valore assoluto dello Scostamento Operativo' : ($chart['id'] === 'carryover-values' ? 'Riporto' : 'Allocato');
            $chart['render_description'] = $chart['description'].' Visualizzati '.count($indices).' di '.$total.' '.$subject.' · ordinati per '.$order.' decrescente.';
            $this->selectChartRows($chart, $indices);
        }
        unset($chart);

        return $charts;
    }

    /**
     * @param  array<string, mixed>  $chart
     * @return array<int, int>
     */
    private function orderedChartIndices(array $chart, bool $absolute): array
    {
        $values = $chart['data']['datasets'][0]['data'];
        $indices = array_keys($values);
        usort($indices, function (int $a, int $b) use ($absolute, $values): int {
            $comparison = $absolute
                ? abs($values[$b]) <=> abs($values[$a])
                : $values[$b] <=> $values[$a];

            return $comparison === 0 ? $a <=> $b : $comparison;
        });

        return $indices;
    }

    /**
     * @param  array<string, mixed>  $chart
     * @param  array<int, int>  $indices
     */
    private function selectChartRows(array &$chart, array $indices): void
    {
        $chart['data']['labels'] = array_map(fn (int $index): string => $chart['data']['labels'][$index], $indices);
        foreach ($chart['data']['datasets'] as &$dataset) {
            $dataset['data'] = array_map(fn (int $index): float|int => $dataset['data'][$index], $indices);
            if (is_array($dataset['backgroundColor'])) {
                $dataset['backgroundColor'] = array_map(fn (int $index): string => $dataset['backgroundColor'][$index], $indices);
            }
        }
        unset($dataset);
    }

    /**
     * @param  array<int, array<string, mixed>>  $definitions
     * @return array<int, array{id: string, heading: string, description: string, image: string}>
     */
    private function staticCharts(array $definitions, string $orientation): array
    {
        return array_map(function (array $chart) use ($orientation): array {
            $description = (string) ($chart['render_description'] ?? $chart['description']);
            $datasets = array_map(function (array $dataset): array {
                $colors = $dataset['backgroundColor'];

                return [
                    'label' => (string) $dataset['label'],
                    'values' => array_map('floatval', $dataset['data']),
                    'colors' => is_array($colors) ? array_values($colors) : [(string) $colors],
                ];
            }, $chart['data']['datasets']);

            if ($chart['variant'] === 'contract-state-doughnut') {
                return $this->contractStateChart(
                    (string) $chart['id'],
                    (string) $chart['heading'],
                    $description,
                    array_map('strval', $chart['data']['labels']),
                    $datasets[0],
                );
            }

            if ($chart['id'] === 'contract-values') {
                return $this->contractBarChart(
                    (string) $chart['id'],
                    (string) $chart['heading'],
                    $description,
                    array_map('strval', $chart['data']['labels']),
                    $datasets,
                    $orientation,
                );
            }

            if ($chart['variant'] === 'category-doughnut') {
                return $this->categoryDistribution($chart['id'], $chart['heading'], $description, $chart['data']['labels'], $datasets[0], $orientation);
            }

            return $this->monetaryBarChart(
                (string) $chart['id'],
                (string) $chart['heading'],
                $description,
                array_map('strval', $chart['data']['labels']),
                $datasets,
                $orientation,
                $chart['variant'] === 'variance-horizontal',
            );
        }, $definitions);
    }

    /**
     * @param  array<int, string>  $labels
     * @param  array<int, array{label: string, values: array<int, float>, colors: array<int, string>}>  $series
     * @return array{id: string, heading: string, description: string, image: string}
     */
    private function contractBarChart(string $id, string $heading, string $description, array $labels, array $series, string $orientation): array
    {
        $rowHeight = max($orientation === 'portrait' ? 44 : 36, count($series) * 17 + 2);
        $canvasWidth = $orientation === 'portrait' ? 1000 : 1400;
        $plotX = 380;
        $legendWidth = max(180, 20 + max(array_map(fn (array $dataset): int => mb_strlen($dataset['label']), $series)) * 10);
        $legendColumns = max(1, intdiv($canvasWidth - $plotX, $legendWidth));
        $plotTop = (int) ceil(count($series) / $legendColumns) * 20 + 12;
        $height = $plotTop + 2 + count($labels) * $rowHeight;
        $maxBarWidth = $canvasWidth - $plotX - 160;
        $max = max(1.0, ...array_map('abs', array_merge(...array_column($series, 'values'))));
        $svg = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 '.$canvasWidth.' '.$height.'">';
        foreach ($series as $index => $dataset) {
            $legendX = $plotX + ($index % $legendColumns) * $legendWidth;
            $legendY = intdiv($index, $legendColumns) * 20;
            $svg .= '<rect x="'.$legendX.'" y="'.($legendY + 3).'" width="12" height="12" fill="'.$dataset['colors'][0].'"/>';
            $svg .= '<text x="'.($legendX + 20).'" y="'.($legendY + 15).'" font-family="Geist" font-size="15" fill="#15323b">'.$this->escape($dataset['label']).'</text>';
        }
        foreach ($labels as $row => $label) {
            $y = $plotTop + $row * $rowHeight;
            $firstLine = mb_substr($label, 0, 38);
            $lastSpace = mb_strrpos($firstLine, ' ');
            $splitAt = mb_strlen($label) > 38 && $lastSpace !== false ? $lastSpace : 38;
            $lines = [mb_substr($label, 0, $splitAt), ltrim(mb_substr($label, $splitAt))];
            foreach ($lines as $lineIndex => $line) {
                $text = $lineIndex === 0 ? $line : mb_strimwidth($line, 0, 38, '…');
                $svg .= '<text x="0" y="'.($y + 13 + $lineIndex * 17).'" font-family="Geist" font-size="16" fill="#15323b">'.$this->escape($text).'</text>';
            }
            foreach ($series as $index => $dataset) {
                $value = $dataset['values'][$row];
                if ($value === 0.0) {
                    continue;
                }
                $width = abs($value) / $max * $maxBarWidth;
                $barY = $y + $index * 17;
                $svg .= '<rect x="'.$plotX.'" y="'.$barY.'" width="'.$width.'" height="12" fill="'.$dataset['colors'][0].'"/>';
                $svg .= '<text x="'.($plotX + $width + 8).'" y="'.($barY + 12).'" font-family="Geist" font-size="14" fill="#15323b">'.$this->escape(Number::currency($value, in: 'EUR', locale: 'it')).'</text>';
            }
        }
        $svg .= '</svg>';

        return compact('id', 'heading', 'description') + ['image' => 'data:image/svg+xml;base64,'.base64_encode($svg)];
    }

    /**
     * @param  array<int, string>  $labels
     * @param  array<int, array{label: string, values: array<int, float>, colors: array<int, string>}>  $series
     * @return array{id: string, heading: string, description: string, image: string}
     */
    private function monetaryBarChart(string $id, string $heading, string $description, array $labels, array $series, string $orientation, bool $divergent): array
    {
        $portrait = $orientation === 'portrait';
        $canvasWidth = $portrait ? 1000 : 1400;
        $rowHeight = max($portrait ? (count($series) > 1 ? 56 : 50) : 44, count($series) * 23);
        $plotX = $portrait ? 350 : 470;
        $plotEnd = $canvasWidth - 175;
        $legend = $divergent ? [['label' => 'Negativo (−)'], ['label' => 'Positivo (+)']] : $series;
        $legendWidth = max(190, 20 + max(array_map(fn (array $dataset): int => mb_strlen($dataset['label']), $legend)) * 10);
        $legendColumns = max(1, intdiv($canvasWidth - $plotX, $legendWidth));
        $plotTop = (int) ceil(count($legend) / $legendColumns) * 22 + 20;
        $height = $plotTop + count($labels) * $rowHeight;
        $values = array_merge(...array_column($series, 'values'));
        $minimum = min(0.0, ...$values);
        $maximum = max(1.0, ...$values);
        if ($divergent) {
            $maximum = max(1.0, ...array_map('abs', $values));
            $minimum = -$maximum;
        }
        $scale = ($plotEnd - $plotX) / ($maximum - $minimum);
        $zero = $plotX - $minimum * $scale;
        $svg = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 '.$canvasWidth.' '.$height.'">';
        foreach ($legend as $index => $dataset) {
            $color = $divergent ? ['#60a5fa', '#15323b'][$index] : $dataset['colors'][0];
            $legendX = $plotX + ($index % $legendColumns) * $legendWidth;
            $legendY = intdiv($index, $legendColumns) * 22;
            $svg .= '<rect x="'.$legendX.'" y="'.$legendY.'" width="12" height="12" fill="'.$color.'"/>';
            $svg .= '<text x="'.($legendX + 20).'" y="'.($legendY + 12).'" font-family="Geist" font-size="16" fill="#15323b">'.$this->escape($dataset['label']).'</text>';
        }
        $svg .= '<line class="zero-axis" x1="'.$zero.'" x2="'.$zero.'" y1="'.($plotTop - 10).'" y2="'.$height.'" stroke="#91a3a8" stroke-width="1.5"/>';
        $svg .= '<text x="'.$zero.'" y="'.($plotTop - 14).'" text-anchor="middle" font-family="Geist" font-size="14" fill="#526762">0</text>';
        foreach ($labels as $row => $label) {
            $y = $plotTop + $row * $rowHeight;
            $lineLength = $portrait ? 32 : 44;
            $firstLine = mb_substr($label, 0, $lineLength);
            $space = mb_strrpos($firstLine, ' ');
            $split = mb_strlen($label) > $lineLength && $space !== false ? $space : $lineLength;
            foreach ([mb_substr($label, 0, $split), ltrim(mb_substr($label, $split))] as $lineIndex => $line) {
                $svg .= '<text x="0" y="'.($y + 15 + $lineIndex * 19).'" font-family="Geist" font-size="18" fill="#15323b">'.$this->escape(mb_strimwidth($line, 0, $lineLength, '…')).'</text>';
            }
            foreach ($series as $index => $dataset) {
                $value = $dataset['values'][$row];
                $width = abs($value) * $scale;
                $x = $value < 0 ? $zero - $width : $zero;
                $barY = $y + $index * 23;
                $color = $divergent
                    ? ($value > 0 ? '#15323b' : '#60a5fa')
                    : $dataset['colors'][0];
                if ($value === 0.0) {
                    $svg .= '<circle class="zero-value" cx="'.$zero.'" cy="'.($barY + 7).'" r="3" fill="#667b7d"/>';
                } else {
                    $svg .= '<rect class="'.($value < 0 ? 'negative' : 'positive').'" x="'.$x.'" y="'.$barY.'" width="'.$width.'" height="14" fill="'.$color.'"/>';
                }
                $formatted = ($divergent && $value > 0 ? '+' : '').Number::currency($value, in: 'EUR', locale: 'it');
                $svg .= '<text x="'.($canvasWidth - 2).'" y="'.($barY + 14).'" text-anchor="end" font-family="Geist" font-size="16" fill="#15323b">'.$this->escape($formatted).'</text>';
            }
        }
        $svg .= '</svg>';

        return compact('id', 'heading', 'description') + ['image' => 'data:image/svg+xml;base64,'.base64_encode($svg)];
    }

    /**
     * @param  array<int, string>  $labels
     * @param  array{label: string, values: array<int, float>, colors: array<int, string>}  $series
     * @return array{id: string, heading: string, description: string, image: string}
     */
    private function categoryDistribution(string $id, string $heading, string $description, array $labels, array $series, string $orientation): array
    {
        $width = $orientation === 'portrait' ? 1000 : 1400;
        $total = array_sum($series['values']);
        $offset = 0.0;
        $svg = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 '.$width.' 76">';
        foreach ($labels as $index => $label) {
            $count = (int) $series['values'][$index];
            $color = ['#39d5c4', '#60a5fa', '#91a3a8', '#15323b'][$index];
            if ($count > 0) {
                $segment = $count / $total * $width;
                $svg .= '<rect x="'.$offset.'" y="0" width="'.$segment.'" height="16" fill="'.$color.'"/>';
                $offset += $segment;
            }
            $x = $index * $width / count($labels);
            $svg .= '<rect x="'.$x.'" y="32" width="10" height="10" fill="'.$color.'"/>';
            $svg .= '<text x="'.($x + 18).'" y="43" font-family="Geist" font-size="18" fill="#526762">'.$this->escape($label).'</text>';
            $svg .= '<text x="'.($x + 18).'" y="69" font-family="Geist" font-size="24" fill="#15323b">'.$count.'</text>';
        }
        $svg .= '</svg>';

        return compact('id', 'heading', 'description') + ['image' => 'data:image/svg+xml;base64,'.base64_encode($svg)];
    }

    /**
     * @param  array<int, string>  $labels
     * @param  array{label: string, values: array<int, float>, colors: array<int, string>}  $series
     * @return array{id: string, heading: string, description: string, image: string}
     */
    private function contractStateChart(string $id, string $heading, string $description, array $labels, array $series): array
    {
        $total = array_sum($series['values']);
        $offset = 0.0;
        $svg = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 1000 56">';
        foreach ($labels as $index => $label) {
            $value = $series['values'][$index];
            $color = ['#60a5fa', '#39d5c4', '#91a3a8', '#15323b'][$index];
            if ($value > 0) {
                $width = $value / $total * 1000;
                $svg .= '<rect x="'.$offset.'" y="0" width="'.$width.'" height="14" fill="'.$color.'"/>';
                $offset += $width;
            }
            $x = $index * 250;
            $ink = $value === 0.0 ? '#667b7d' : '#15323b';
            $svg .= '<rect x="'.$x.'" y="34" width="9" height="9" fill="'.$color.'"/>';
            $svg .= '<text x="'.($x + 18).'" y="44" font-family="Geist" font-size="15" fill="'.$ink.'">'.$this->escape($label).'</text>';
            $svg .= '<text x="'.($x + 224).'" y="44" text-anchor="end" font-family="Geist" font-size="19" fill="'.$ink.'">'.(int) $value.'</text>';
        }
        $svg .= '</svg>';

        return compact('id', 'heading', 'description') + ['image' => 'data:image/svg+xml;base64,'.base64_encode($svg)];
    }

    /** @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function contract(array $row): array
    {
        $detail = is_array($row['detail'] ?? null) ? $row['detail'] : [];

        return [
            'label' => (string) $row['label'],
            'summary' => is_string($row['summary'] ?? null) ? $row['summary'] : null,
            'supplier' => (string) $row['supplier'],
            'cost_center' => (string) $row['cost_center'],
            'state' => (string) $row['state'],
            'state_label' => (string) $row['state_label'],
            'labels' => $row['labels'],
            'deadline' => $row['deadline'] ?? null,
            'notice_limit_date' => $row['notice_limit_date'] ?? null,
            'automatic_renewal' => (bool) ($row['automatic_renewal'] ?? false),
            'allocation' => (string) $row['allocation'],
            'actual' => (string) $row['actual'],
            'operational_variance' => (string) $row['operational_variance'],
            'conditions' => $this->contractConditions($detail['conditions'] ?? []),
            'renewal_configurations' => $this->contractRenewalConfigurations($detail['cycles'] ?? []),
            'events' => $this->contractEvents($detail['events'] ?? []),
            'expenses' => $this->contractExpenses($detail['expenses'] ?? []),
            'corrections' => $this->normalizeValue($row['corrections'] ?? []),
            'annotations' => $this->normalizeValue($row['annotations'] ?? []),
        ];
    }

    /** @return array<int, array<string, mixed>> */
    private function contractConditions(mixed $conditions): array
    {
        if (! is_array($conditions)) {
            return [];
        }

        return collect($conditions)
            ->filter(fn (mixed $condition): bool => is_array($condition) && empty($condition['annulled_at']))
            ->map(function (array $condition): array {
                $cycle = ContractCycleType::tryFrom((string) ($condition['cycle'] ?? ''));
                $attribution = ContractAttributionMode::tryFrom((string) ($condition['attribution_mode'] ?? ''));

                return [
                    'amount' => (string) ($condition['amount'] ?? '0.00'),
                    'cycle' => $cycle?->label() ?? '—',
                    'attribution' => $attribution?->label() ?? '—',
                    'valid_from' => $condition['valid_from'] ?? null,
                    'valid_to' => $condition['valid_to'] ?? null,
                    'reason' => is_string($condition['reason'] ?? null) ? $condition['reason'] : null,
                ];
            })
            ->values()
            ->all();
    }

    /** @return array<int, array<string, mixed>> */
    private function contractRenewalConfigurations(mixed $configurations): array
    {
        if (! is_array($configurations)) {
            return [];
        }

        return collect($configurations)
            ->filter(fn (mixed $configuration): bool => is_array($configuration))
            ->map(fn (array $configuration): array => [
                'effective_from' => $configuration['effective_from'] ?? null,
                'automatic_renewal' => (bool) ($configuration['automatic_renewal'] ?? false),
                'expiry_anchor_date' => $configuration['expiry_anchor_date'] ?? null,
                'renewal_duration_months' => isset($configuration['renewal_duration_months']) ? (int) $configuration['renewal_duration_months'] : null,
                'notice_days' => isset($configuration['notice_days']) ? (int) $configuration['notice_days'] : null,
            ])
            ->values()
            ->all();
    }

    /** @return array<int, array<string, mixed>> */
    private function contractEvents(mixed $events): array
    {
        if (! is_array($events)) {
            return [];
        }

        return collect($events)
            ->filter(fn (mixed $event): bool => is_array($event) && empty($event['annulled_at']))
            ->map(fn (array $event): array => [
                'date' => $event['state_change_date'] ?? $event['renewed_expiry_date'] ?? $event['declared_contractual_date'] ?? null,
                'label' => match ((string) ($event['type'] ?? '')) {
                    'activation' => 'Attivazione',
                    'cessation', 'expiry_cessation' => 'Cessazione',
                    'reactivation' => 'Riattivazione',
                    'cancellation' => 'Annullamento',
                    'renewal' => 'Rinnovo',
                    default => 'Evento contrattuale',
                },
                'reason' => is_string($event['reason'] ?? null) ? $event['reason'] : null,
            ])
            ->values()
            ->all();
    }

    /** @return array<int, array<string, string>> */
    private function contractExpenses(mixed $expenses): array
    {
        if (! is_array($expenses)) {
            return [];
        }

        return collect($expenses)
            ->filter(fn (mixed $expense): bool => is_array($expense))
            ->map(fn (array $expense): array => [
                'description' => (string) ($expense['source'] ?? 'Spesa'),
                'allocation' => (string) ($expense['allocation'] ?? '0.00'),
                'actual' => (string) ($expense['actual'] ?? '0.00'),
            ])
            ->values()
            ->all();
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
