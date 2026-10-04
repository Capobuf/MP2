<?php

namespace App\Support\Reporting;

use App\Domain\Contracts\ContractState;
use App\Domain\Expenses\Decimal;
use App\Domain\Reporting\ComparisonCategory;
use App\Domain\Reporting\ReportKind;
use App\Domain\Reporting\ReportResult;
use App\Domain\Reporting\ReportSource;

final class ReportChartDefinitions
{
    /** @return array<int, array<string, mixed>> */
    public function definitions(ReportResult $result): array
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

            if ($result->costCenters !== []) {
                $charts[] = $this->groupedBarChart(
                    'annual-cost-centers', 'Allocato ed Effettivo per Centro di Costo',
                    'Totali diretti e di ramo; Non classificato include solo le sorgenti senza Centro di Costo.',
                    array_column($result->costCenters, 'label'),
                    [
                        ['label' => 'Allocato diretto', 'data' => array_map('floatval', array_column($result->costCenters, 'direct_allocation')), 'color' => '#39D5C4'],
                        ['label' => 'Allocato ramo', 'data' => array_map('floatval', array_column($result->costCenters, 'branch_allocation')), 'color' => '#1A9489'],
                        ['label' => (string) $result->header['actual_reference'].' diretto', 'data' => array_map('floatval', array_column($result->costCenters, 'direct_actual')), 'color' => '#60A5FA'],
                        ['label' => (string) $result->header['actual_reference'].' ramo', 'data' => array_map('floatval', array_column($result->costCenters, 'branch_actual')), 'color' => '#2563EB'],
                    ],
                );
            }
        } elseif (in_array($kind, [
            ReportKind::BudgetActual, ReportKind::BudgetCurrentAllocation,
            ReportKind::BudgetVersions, ReportKind::Exercises,
        ], true)) {
            $charts[] = $this->currencyBarChart(
                'comparison-totals', 'Confronto Complessivo',
                'Valori iniziali e finali delle sorgenti confrontate.',
                [
                    (string) ($result->header['initial_reference_label'] ?? $result->header['initial_reference']),
                    (string) ($result->header['final_reference_label'] ?? $result->header['final_reference']),
                ],
                [
                    (float) Decimal::sum(array_column($result->comparisons, 'initial_value')),
                    (float) Decimal::sum(array_column($result->comparisons, 'final_value')),
                ],
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
            $sources = array_values(array_filter(
                $result->sources,
                fn (ReportSource $source): bool => $source->sourceType === 'contract',
            ));
            if ($sources !== []) {
                $charts[] = $this->groupedBarChart(
                    'contract-values', 'Allocato vs Effettivo per contratto',
                    'Allocato ed Effettivo dei Contratti.',
                    array_map(fn (ReportSource $source): string => $source->label, $sources),
                    [
                        ['label' => 'Allocato', 'data' => array_map(fn (ReportSource $source): float => (float) $source->allocation, $sources), 'color' => '#39D5C4'],
                        ['label' => 'Effettivo', 'data' => array_map(fn (ReportSource $source): float => (float) $source->actual, $sources), 'color' => '#60A5FA'],
                    ],
                );

                $counts = collect($sources)->countBy(fn (ReportSource $source): string => (string) $source->state);
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
            $rows = $result->sections[0]['rows'] ?? [];
            $carryovers = array_map(fn (mixed $row): float => (float) ($row instanceof ReportSource
                ? $row->carryover
                : ($row['provisional_carryover'] ?? $row['consolidated_carryover'] ?? $row['carryover'] ?? '0.00')), $rows);
            $reprogrammed = array_map(fn (mixed $row): float => (float) ($row instanceof ReportSource
                ? '0.00'
                : ($row['reprogrammed_amount'] ?? '0.00')), $rows);
            if ($rows !== [] && (array_sum($carryovers) !== 0.0 || array_sum($reprogrammed) !== 0.0)) {
                $charts[] = $this->groupedBarChart(
                    'carryover-values', 'Trasferimenti per Progetto',
                    'Importi di Riporto e Riprogrammazione registrati.',
                    array_map(fn (mixed $row): string => $row instanceof ReportSource ? $row->label : (string) $row['label'], $rows),
                    [
                        ['label' => 'Riporto', 'data' => $carryovers, 'color' => '#F59E0B'],
                        ['label' => 'Riprogrammato', 'data' => $reprogrammed, 'color' => '#60A5FA'],
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
}
