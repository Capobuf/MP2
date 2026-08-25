<?php

namespace App\Filament\Widgets;

use Filament\Support\RawJs;

class AllocationComparisonScatterChart extends EconomicChartWidget
{
    protected ?string $heading = 'Budget → Allocato Corrente';

    protected ?string $emptyStateHeading = 'Confronto Allocato non disponibile';

    protected function getType(): string
    {
        return 'scatter';
    }

    public function getDescription(): ?string
    {
        $data = $this->economicData();

        return ($data['has_budget'] ?? false)
            ? 'Ogni punto è una sorgente primaria; la diagonale indica uguaglianza degli Allocati.'
            : 'Seleziona una versione di Budget nel contesto globale per visualizzare il confronto.';
    }

    /** @return array<string, mixed> */
    protected function getData(): array
    {
        $dashboard = $this->economicData();
        if (! ($dashboard['has_budget'] ?? false) || ($dashboard['sources'] ?? []) === []) {
            return [];
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
                    'label' => 'Allocato invariato (x = y)',
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
        return $this->options(<<<'JS'
            {
                interaction: { mode: 'nearest', intersect: true },
                plugins: {
                    legend: { position: 'bottom', labels: { usePointStyle: true, boxWidth: 8, padding: 16 } },
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
                    x: { beginAtZero: true, title: { display: true, text: 'Budget selezionato' }, grid: { color: 'rgba(145, 163, 168, 0.12)' }, ticks: { callback: (value) => new Intl.NumberFormat('it-IT', { style: 'currency', currency: 'EUR', notation: 'compact' }).format(value) } },
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
