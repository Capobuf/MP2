<?php

namespace App\Filament\Widgets;

use App\Domain\Expenses\Decimal;
use Filament\Support\RawJs;

class AllocationComparisonScatterChart extends EconomicChartWidget
{
    public function chartSurfaceClass(): string
    {
        return parent::chartSurfaceClass().(($this->economicData()['has_budget'] ?? false) ? '' : ' mp2-economic-chart-summary');
    }

    public function getEmptyStateHeading(): string
    {
        return ($this->economicData()['has_budget'] ?? false)
            ? 'Confronto Allocato Non Disponibile'
            : 'Nessuna Sorgente Disponibile';
    }

    public function getHeading(): string
    {
        return ($this->economicData()['has_budget'] ?? false)
            ? 'Budget → Allocato Corrente'
            : 'Sorgenti per Scostamento';
    }

    protected function getType(): string
    {
        return ($this->economicData()['has_budget'] ?? false) ? 'scatter' : 'doughnut';
    }

    public function getDescription(): ?string
    {
        $data = $this->economicData();

        return ($data['has_budget'] ?? false)
            ? 'Ogni Punto È una Sorgente Primaria; la Diagonale Indica Uguaglianza degli Allocati.'
            : 'Numero di Sorgenti con Effettivo Inferiore, Uguale o Superiore all’Allocato Corrente.';
    }

    /** @return array<string, mixed> */
    protected function getData(): array
    {
        $dashboard = $this->economicData();
        if (($dashboard['sources'] ?? []) === []) {
            return [];
        }

        if (! ($dashboard['has_budget'] ?? false)) {
            $counts = [-1 => 0, 0 => 0, 1 => 0];
            foreach ($dashboard['sources'] as $source) {
                $counts[Decimal::compare((string) $source['operational_variance'], '0')]++;
            }

            return [
                'labels' => ['Inferiore', 'Uguale', 'Superiore'],
                'datasets' => [[
                    'label' => 'Sorgenti',
                    'data' => array_values($counts),
                    'backgroundColor' => ['#60A5FA', '#91A3A8', '#EF4444'],
                    'borderColor' => '#0B1D25',
                    'borderWidth' => 3,
                    'hoverOffset' => 8,
                ]],
            ];
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

    protected function getOptions(): RawJs
    {
        if (! ($this->economicData()['has_budget'] ?? false)) {
            return $this->options(<<<'JS'
                {
                    cutout: '68%',
                    plugins: {
                        legend: { position: 'bottom', labels: { usePointStyle: true, boxWidth: 12, boxHeight: 8, padding: 18, font: { family: getComputedStyle(document.body).fontFamily } } },
                        tooltip: { padding: 12, callbacks: { label: (context) => `Effettivo ${context.label.toLocaleLowerCase('it-IT')} all’Allocato: ${context.parsed} ${context.parsed === 1 ? 'sorgente' : 'sorgenti'}` } },
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
