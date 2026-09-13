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
use Carbon\CarbonImmutable;
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
        $orientation = $this->orientation($configuration);
        $sources = array_map(fn (ReportSource $source): array => $this->source($source), $result->sources);
        $comparisons = array_map(fn (array $row): array => $this->comparison($row), $result->comparisons);
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
            $availableBlocks[] = $this->option('table:sources', 'Dettaglio e riconciliazione', 'table');
            $availableBlocks[] = $this->option('details:sources', 'Approfondimenti delle sorgenti', 'detail');
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

        $blockIds = array_column($availableBlocks, 'id');
        $defaultBlocks = array_values(array_diff($blockIds, ['details:contracts', 'details:sources']));
        if (in_array($result->definition->kind, [ReportKind::Projects, ReportKind::Carryovers, ReportKind::Suppliers], true)) {
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
            } elseif ($kind === ReportKind::Contracts) {
                $referenceDate = $result->definition->finalReference->referenceDate
                    ?? CarbonImmutable::parse((string) $result->header['reference_date']);
                $expiringBy = $referenceDate->addDays(90);
                /** @var array<int, array<string, mixed>> $contractRows */
                $contractRows = $sections[0]['rows'] ?? [];
                $expiring = count(array_filter($contractRows, function (array $row) use ($referenceDate, $expiringBy): bool {
                    $deadline = $row['deadline'] ?? null;

                    return is_string($deadline)
                        && $deadline !== ''
                        && CarbonImmutable::parse($deadline)->betweenIncluded($referenceDate, $expiringBy);
                }));
                $items = [
                    ['specialist_count', 'Contratti', $specialist['item_count'], false],
                    ['specialist_allocation', 'Allocato', $specialist['allocation'], true],
                    ['specialist_actual', 'Effettivo', $specialist['actual'], true],
                    ['specialist_variance', 'Scostamento operativo', $specialist['operational_variance'], true],
                    ['contracts_expiring', 'Contratti in scadenza', $expiring, false, 'nei prossimi 90 giorni'],
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
            'description' => $item[4] ?? null,
            'group' => ! $item[3] ? 'context' : (in_array($item[0], [
                'current_budget', 'current_allocation', 'selected_actual', 'comparison_initial', 'comparison_final',
                'comparison_delta', 'allocation', 'actual', 'operational_variance',
                'specialist_carryover', 'specialist_allocation', 'specialist_actual', 'specialist_variance',
            ], true) ? 'economic' : 'secondary'),
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
    public function chartDefinitions(ReportResult $result, string $orientation = 'landscape'): array
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
                'Riferimenti economici disponibili per l’Esercizio.', $labels, $values,
            );

            $categoryChart = $this->categoryChart($result);
            if ($categoryChart !== null) {
                $charts[] = $categoryChart;
            }

            $costCenters = $result->costCenters;
            if ($costCenters !== []) {
                $charts[] = $this->groupedBarChart(
                    'annual-cost-centers', 'Allocato ed Effettivo per Centro di Costo',
                    'Totali diretti e di ramo; Non classificato include solo le sorgenti senza Centro di Costo.',
                    array_column($costCenters, 'label'),
                    [
                        ['label' => 'Allocato diretto', 'data' => array_map('floatval', array_column($costCenters, 'direct_allocation')), 'color' => '#39D5C4'],
                        ['label' => 'Allocato ramo', 'data' => array_map('floatval', array_column($costCenters, 'branch_allocation')), 'color' => '#1A9489'],
                        ['label' => (string) $result->header['actual_reference'].' diretto', 'data' => array_map('floatval', array_column($costCenters, 'direct_actual')), 'color' => '#60A5FA'],
                        ['label' => (string) $result->header['actual_reference'].' ramo', 'data' => array_map('floatval', array_column($costCenters, 'branch_actual')), 'color' => '#2563EB'],
                    ],
                );
            }
        } elseif (in_array($kind, [
            ReportKind::BudgetActual, ReportKind::BudgetCurrentAllocation,
            ReportKind::BudgetVersions, ReportKind::Exercises,
        ], true)) {
            $initial = Decimal::sum(array_column($result->comparisons, 'initial_value'));
            $final = Decimal::sum(array_column($result->comparisons, 'final_value'));
            $charts[] = $this->currencyBarChart(
                'comparison-totals', 'Confronto Complessivo',
                'Valori iniziali e finali delle sorgenti confrontate.',
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
        } elseif ($kind === ReportKind::Contracts) {
            $sources = collect($result->sources)
                ->filter(fn (ReportSource $source): bool => $source->sourceType === 'contract');
            $contractCount = $sources->count();
            $sources = $sources->sortByDesc(fn (ReportSource $source): float => (float) $source->allocation)
                ->take($orientation === 'portrait' ? 5 : 8)
                ->values()
                ->all();
            if ($sources !== []) {
                $charts[] = $this->groupedBarChart(
                    'contract-values', 'Allocato vs Effettivo per contratto',
                    count($sources) < $contractCount
                        ? 'Visualizzati '.count($sources).' di '.$contractCount.' contratti · ordinati per Allocato decrescente.'
                        : 'Contratti ordinati per Allocato decrescente.',
                    array_map(fn (ReportSource $source): string => $source->label, $sources),
                    [
                        ['label' => 'Allocato', 'data' => array_map(fn (ReportSource $source): float => (float) $source->allocation, $sources), 'color' => '#39D5C4'],
                        ['label' => 'Effettivo', 'data' => array_map(fn (ReportSource $source): float => (float) $source->actual, $sources), 'color' => '#60A5FA'],
                    ],
                );

                $counts = collect(array_values(array_filter(
                    $result->sources,
                    fn (ReportSource $source): bool => $source->sourceType === 'contract',
                )))->countBy(fn (ReportSource $source): string => (string) $source->state);
                $states = ContractState::cases();
                $charts[] = [
                    'id' => 'contract-states',
                    'heading' => 'Distribuzione per stato',
                    'description' => 'Stati canonici MP2 alla data economica del report.',
                    'type' => 'doughnut',
                    'variant' => 'contract-state-doughnut',
                    'data' => [
                        'labels' => array_map(fn (ContractState $state): string => $state->label(), $states),
                        'datasets' => [[
                            'label' => 'Contratti',
                            'data' => array_map(fn (ContractState $state): int => (int) $counts->get($state->value, 0), $states),
                            'backgroundColor' => ['#60A5FA', '#39D5C4', '#91A3A8', '#EF4444'],
                        ]],
                    ],
                ];
            }
        } elseif ($kind === ReportKind::Projects) {
            $sources = array_values(array_filter($result->sources, fn (ReportSource $source): bool => $source->sourceType === 'project'));
            if ($sources !== []) {
                $charts[] = $this->groupedBarChart(
                    'project-values', 'Progetti · Allocato ed Effettivo',
                    'Allocato ed Effettivo dei Progetti.',
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
                'Allocato ed Effettivo aggregati per Fornitore.', array_column($rows, 'label'),
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
                    'Riporto per Progetto.',
                    array_map(fn (ReportSource $source): string => $source->label, $sources),
                    [
                        ['label' => 'Riporto', 'data' => array_map(fn (ReportSource $source): float => (float) $source->carryover, $sources), 'color' => '#39D5C4'],
                    ],
                );
            }
        }

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
            $values = $chart['data']['datasets'][0]['data'];
            $indices = array_keys($values);
            $variance = $chart['id'] === 'operational-variance';
            usort($indices, fn (int $a, int $b): int => $variance
                ? abs($values[$b]) <=> abs($values[$a])
                : $values[$b] <=> $values[$a]);
            $total = count($indices);
            $indices = array_slice($indices, 0, $orientation === 'portrait' ? 5 : 8);
            $order = $variance ? 'valore assoluto dello Scostamento Operativo' : ($chart['id'] === 'carryover-values' ? 'Riporto' : 'Allocato');
            $chart['description'] .= ' Visualizzati '.count($indices).' di '.$total.' '.$subject.' · ordinati per '.$order.' decrescente.';
            $chart['data']['labels'] = array_map(fn (int $index): string => $chart['data']['labels'][$index], $indices);
            foreach ($chart['data']['datasets'] as &$dataset) {
                $dataset['data'] = array_map(fn (int $index): float => $dataset['data'][$index], $indices);
                if (is_array($dataset['backgroundColor'])) {
                    $dataset['backgroundColor'] = array_map(fn (int $index): string => $dataset['backgroundColor'][$index], $indices);
                }
            }
            unset($dataset);
        }
        unset($chart);

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
    private function staticCharts(array $definitions, string $orientation): array
    {
        return array_map(function (array $chart) use ($orientation): array {
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
                    (string) $chart['description'],
                    array_map('strval', $chart['data']['labels']),
                    $datasets[0],
                );
            }

            if ($chart['id'] === 'contract-values') {
                return $this->contractBarChart(
                    (string) $chart['id'],
                    (string) $chart['heading'],
                    (string) $chart['description'],
                    array_map('strval', $chart['data']['labels']),
                    $datasets,
                    $orientation,
                );
            }

            if ($chart['variant'] === 'category-doughnut') {
                return $this->categoryDistribution($chart['id'], $chart['heading'], $chart['description'], $chart['data']['labels'], $datasets[0], $orientation);
            }

            return $this->monetaryBarChart(
                (string) $chart['id'],
                (string) $chart['heading'],
                (string) $chart['description'],
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
        $rowHeight = $orientation === 'portrait' ? 44 : 36;
        $height = 34 + count($labels) * $rowHeight;
        $canvasWidth = $orientation === 'portrait' ? 1000 : 1400;
        $plotX = 380;
        $maxBarWidth = $canvasWidth - $plotX - 160;
        $max = max(1.0, ...array_map('abs', array_merge(...array_column($series, 'values'))));
        $svg = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 '.$canvasWidth.' '.$height.'">';
        foreach ($series as $index => $dataset) {
            $legendX = $plotX + $index * 180;
            $svg .= '<rect x="'.$legendX.'" y="3" width="12" height="12" fill="'.$dataset['colors'][0].'"/>';
            $svg .= '<text x="'.($legendX + 20).'" y="15" font-family="Geist" font-size="15" fill="#15323b">'.$this->escape($dataset['label']).'</text>';
        }
        foreach ($labels as $row => $label) {
            $y = 32 + $row * $rowHeight;
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
        $rowHeight = count($series) > 1 ? ($portrait ? 56 : 46) : ($portrait ? 50 : 44);
        $height = 42 + count($labels) * $rowHeight;
        $plotX = $portrait ? 350 : 470;
        $plotEnd = $canvasWidth - 175;
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
        $legend = $divergent ? [['label' => 'Negativo (−)'], ['label' => 'Positivo (+)']] : $series;
        foreach ($legend as $index => $dataset) {
            $color = $divergent ? ['#60a5fa', '#15323b'][$index] : $dataset['colors'][0];
            $legendX = $plotX + $index * 190;
            $svg .= '<rect x="'.$legendX.'" y="0" width="12" height="12" fill="'.$color.'"/>';
            $svg .= '<text x="'.($legendX + 20).'" y="12" font-family="Geist" font-size="16" fill="#15323b">'.$this->escape($dataset['label']).'</text>';
        }
        $svg .= '<line class="zero-axis" x1="'.$zero.'" x2="'.$zero.'" y1="32" y2="'.$height.'" stroke="#91a3a8" stroke-width="1.5"/>';
        $svg .= '<text x="'.$zero.'" y="28" text-anchor="middle" font-family="Geist" font-size="14" fill="#526762">0</text>';
        foreach ($labels as $row => $label) {
            $y = 42 + $row * $rowHeight;
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
