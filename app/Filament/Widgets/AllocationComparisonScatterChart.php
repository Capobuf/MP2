<?php

namespace App\Filament\Widgets;

use App\Domain\Expenses\Decimal;
use Filament\Support\RawJs;

class AllocationComparisonScatterChart extends EconomicChartWidget
{
    private const GROUPINGS = [
        'type' => 'Tipologia',
        'supplier' => 'Fornitore',
        'cost_center' => 'Centro di costo',
    ];

    public ?string $filter = 'type';

    /** @return array<string, string>|null */
    protected function getFilters(): ?array
    {
        return ($this->economicData()['has_budget'] ?? false) ? null : self::GROUPINGS;
    }

    public function updatingFilter(?string $value): void
    {
        abort_unless(array_key_exists($value ?? '', self::GROUPINGS), 422, 'Seleziona un raggruppamento disponibile.');
    }

    public function chartSurfaceClass(): string
    {
        return parent::chartSurfaceClass().(($this->economicData()['has_budget'] ?? false) ? '' : ' mp2-economic-chart-summary');
    }

    public function getEmptyStateHeading(): string
    {
        return ($this->economicData()['has_budget'] ?? false)
            ? 'Confronto Allocato Non Disponibile'
            : 'Nessun Allocato Disponibile';
    }

    public function getHeading(): string
    {
        return ($this->economicData()['has_budget'] ?? false)
            ? 'Budget → Allocato Corrente'
            : 'Distribuzione dell’Allocato';
    }

    protected function getType(): string
    {
        return ($this->economicData()['has_budget'] ?? false) ? 'scatter' : 'doughnut';
    }

    public function getDescription(): ?string
    {
        $data = $this->economicData();

        if ($data['has_budget'] ?? false) {
            return 'Ogni Punto È una Sorgente Primaria; la Diagonale Indica Uguaglianza degli Allocati.';
        }

        $description = match ($this->filter) {
            'type' => 'Quote di Allocato Corrente: Spese Autonome, Progetti e Contratti.',
            'supplier' => 'Quote di Allocato Corrente per Fornitore; Riporto senza Fornitore separato.',
            'cost_center' => 'Quote di Allocato Corrente per Centro di Costo diretto, senza sommare i rami.',
            default => throw new \LogicException('Unknown allocation grouping.'),
        };

        return $description.(($this->getCachedData()['otherCount'] ?? 0) > 0 ? ' Le sei quote maggiori e le restanti in Altri.' : '');
    }

    /** @return array<string, mixed> */
    protected function getData(): array
    {
        $dashboard = $this->economicData();
        if (($dashboard['sources'] ?? []) === []) {
            return [];
        }

        if (! ($dashboard['has_budget'] ?? false)) {
            return $this->allocationDistribution($dashboard);
        }

        $points = array_map(fn (array $source): array => [
            'x' => (float) $source['budget'],
            'y' => (float) $source['allocation'],
            'label' => $source['label'],
            'variation' => $source['allocation_vs_budget'],
            'url' => $source['url'],
        ], $dashboard['sources']);
        $max = max(1, ...array_map(fn (array $point): float => max($point['x'], $point['y']), $points));

        return [
            'datasets' => [
                [
                    'label' => 'Sorgenti',
                    'data' => $points,
                    'backgroundColor' => '#39D5C4',
                    'borderColor' => '#0B1D25',
                    'borderWidth' => 1.5,
                    'pointRadius' => 5,
                    'pointHoverRadius' => 8,
                ],
                [
                    'type' => 'line',
                    'label' => 'Allocato Invariato (x = y)',
                    'data' => [['x' => 0, 'y' => 0], ['x' => $max, 'y' => $max]],
                    'borderColor' => '#91A3A8',
                    'borderDash' => [5, 5],
                    'borderWidth' => 1.5,
                    'pointRadius' => 0,
                    'fill' => false,
                ],
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $dashboard
     * @return array<string, mixed>
     */
    private function allocationDistribution(array $dashboard): array
    {
        $rows = match ($this->filter) {
            'type' => collect(['expense' => 'Spese Autonome', 'project' => 'Progetti', 'contract' => 'Contratti'])
                ->map(fn (string $label, string $type): array => [
                    'key' => $type,
                    'label' => $label,
                    'allocation' => Decimal::sum(array_column(array_filter($dashboard['sources'], fn (array $source): bool => $source['source_type'] === $type), 'allocation')),
                ])->values()->all(),
            'supplier' => $dashboard['suppliers'],
            'cost_center' => array_map(fn (array $center): array => [
                'key' => $center['key'],
                'label' => $center['label'],
                'allocation' => $center['direct_allocation'],
            ], $dashboard['cost_centers']),
            default => throw new \LogicException('Unknown allocation grouping.'),
        };
        $rows = array_values(array_filter($rows, fn (array $row): bool => Decimal::compare((string) $row['allocation'], '0') !== 0));
        if ($rows === []) {
            return [];
        }

        usort($rows, fn (array $left, array $right): int => Decimal::compare((string) $right['allocation'], (string) $left['allocation'])
            ?: strcmp((string) $left['key'], (string) $right['key']));
        $total = Decimal::sum(array_column($rows, 'allocation'));
        $otherCount = 0;
        if (count($rows) > 7) {
            $others = array_splice($rows, 6);
            $otherCount = count($others);
            $rows[] = ['label' => "Altri ({$otherCount})", 'allocation' => Decimal::sum(array_column($others, 'allocation'))];
        }

        return [
            'labels' => array_column($rows, 'label'),
            'total' => $total,
            'otherCount' => $otherCount,
            'datasets' => [[
                'label' => 'Allocato Corrente',
                'data' => array_map('floatval', array_column($rows, 'allocation')),
                'backgroundColor' => ['#39D5C4', '#60A5FA', '#A78BFA', '#FBBF24', '#F472B6', '#5EEAD4', '#91A3A8'],
                'borderColor' => '#0B1D25',
                'borderWidth' => 3,
                'hoverOffset' => 8,
            ]],
        ];
    }

    protected function getOptions(): RawJs
    {
        if (! ($this->economicData()['has_budget'] ?? false)) {
            return $this->options(<<<'JS'
                {
                    cutout: '68%',
                    plugins: {
                        legend: {
                            position: 'bottom',
                            labels: {
                                usePointStyle: true, boxWidth: 12, boxHeight: 8, padding: 18,
                                font: { family: getComputedStyle(document.body).fontFamily },
                                generateLabels: (chart) => chart.constructor.overrides.doughnut.plugins.legend.labels.generateLabels(chart).map((item) => ({
                                    ...item,
                                    text: item.text.length > 28 ? `${item.text.slice(0, 27)}…` : item.text,
                                    strokeStyle: item.fillStyle,
                                    lineWidth: 2.5,
                                })),
                            },
                        },
                        tooltip: {
                            padding: 12,
                            callbacks: {
                                label: (context) => [
                                    new Intl.NumberFormat('it-IT', { style: 'currency', currency: 'EUR' }).format(context.parsed),
                                    `${new Intl.NumberFormat('it-IT', { style: 'percent', maximumFractionDigits: 1 }).format(context.parsed / Number(context.chart.data.total))} dell’Allocato Corrente`,
                                ],
                            },
                        },
                    },
                }
                JS);
        }

        return $this->options(<<<'JS'
            {
                interaction: { mode: 'nearest', intersect: true },
                plugins: {
                    legend: { position: 'bottom', labels: { usePointStyle: true, boxWidth: 12, boxHeight: 8, padding: 18, font: { family: getComputedStyle(document.body).fontFamily } } },
                    tooltip: {
                        filter: (item) => item.datasetIndex === 0,
                        padding: 12,
                        callbacks: {
                            title: (items) => items[0]?.raw?.label ?? '',
                            label: (context) => [
                                `Budget: ${new Intl.NumberFormat('it-IT', { style: 'currency', currency: 'EUR' }).format(context.parsed.x)}`,
                                `Allocato Corrente: ${new Intl.NumberFormat('it-IT', { style: 'currency', currency: 'EUR' }).format(context.parsed.y)}`,
                                `Variazione Allocato vs Budget: ${new Intl.NumberFormat('it-IT', { style: 'currency', currency: 'EUR' }).format(Number(context.raw.variation))}`,
                            ],
                        },
                    },
                },
                scales: {
                    x: { beginAtZero: true, title: { display: true, text: 'Budget Selezionato' }, grid: { color: 'rgba(145, 163, 168, 0.12)' }, ticks: { callback: (value) => new Intl.NumberFormat('it-IT', { style: 'currency', currency: 'EUR', notation: 'compact' }).format(value) } },
                    y: { beginAtZero: true, title: { display: true, text: 'Allocato Corrente' }, grid: { color: 'rgba(145, 163, 168, 0.12)' }, ticks: { callback: (value) => new Intl.NumberFormat('it-IT', { style: 'currency', currency: 'EUR', notation: 'compact' }).format(value) } },
                },
                onClick: (event, elements, chart) => {
                    if (! elements.length || elements[0].datasetIndex !== 0) return;
                    const url = chart.data.datasets[0].data[elements[0].index].url;
                    if (url) window.location.assign(url);
                },
            }
            JS);
    }
}
