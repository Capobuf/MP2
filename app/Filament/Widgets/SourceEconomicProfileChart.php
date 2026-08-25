<?php

namespace App\Filament\Widgets;

use Filament\Support\RawJs;

class SourceEconomicProfileChart extends EconomicChartWidget
{
    protected ?string $heading = 'Profilo economico delle sorgenti';

    protected int|string|array $columnSpan = 'full';

    protected ?string $maxHeight = '27rem';

    protected ?string $emptyStateHeading = 'Nessun profilo economico disponibile';

    protected function getType(): string
    {
        return 'line';
    }

    public function getDescription(): ?string
    {
        $data = $this->economicData();

        return ($data['has_budget'] ?? false)
            ? 'Budget selezionato, Allocato Corrente ed Effettivo per sorgente primaria.'
            : 'Seleziona una versione di Budget nel contesto globale per visualizzare il confronto.';
    }

    /** @return array<string, mixed> */
    protected function getData(): array
    {
        $dashboard = $this->economicData();
        if (! ($dashboard['has_budget'] ?? false) || ($dashboard['sources'] ?? []) === []) {
            return [];
        }

        $sources = $dashboard['sources'];

        return [
            'labels' => array_column($sources, 'label'),
            'sourceUrls' => array_column($sources, 'url'),
            'operationalVariances' => array_column($sources, 'operational_variance'),
            'datasets' => [
                [
                    'label' => 'Budget selezionato',
                    'data' => array_map('floatval', array_column($sources, 'budget')),
                    'borderColor' => '#91A3A8',
                    'backgroundColor' => 'rgba(145, 163, 168, 0.08)',
                    'borderDash' => [5, 5],
                    'borderWidth' => 2,
                    'pointRadius' => 3,
                    'pointHoverRadius' => 6,
                    'cubicInterpolationMode' => 'monotone',
                    'tension' => 0.35,
                ],
                [
                    'label' => 'Allocato Corrente',
                    'data' => array_map('floatval', array_column($sources, 'allocation')),
                    'borderColor' => '#39D5C4',
                    'backgroundColor' => 'rgba(57, 213, 196, 0.12)',
                    'borderWidth' => 2.5,
                    'pointRadius' => 3,
                    'pointHoverRadius' => 6,
                    'cubicInterpolationMode' => 'monotone',
                    'tension' => 0.35,
                    'fill' => false,
                ],
                [
                    'label' => 'Effettivo',
                    'data' => array_map('floatval', array_column($sources, 'actual')),
                    'borderColor' => '#60A5FA',
                    'backgroundColor' => 'rgba(96, 165, 250, 0.14)',
                    'borderWidth' => 2.5,
                    'pointRadius' => 3,
                    'pointHoverRadius' => 6,
                    'cubicInterpolationMode' => 'monotone',
                    'tension' => 0.35,
                    'fill' => 1,
                ],
            ],
        ];
    }

    protected function getOptions(): RawJs
    {
        return $this->options(<<<'JS'
            {
                interaction: { mode: 'index', intersect: false },
                plugins: {
                    legend: { position: 'bottom', labels: { usePointStyle: true, boxWidth: 8, padding: 18 } },
                    tooltip: {
                        padding: 12,
                        displayColors: true,
                        animations: { opacity: { duration: window.matchMedia('(prefers-reduced-motion: reduce)').matches ? 0 : 180 } },
                        callbacks: {
                            label: (context) => `${context.dataset.label}: ${new Intl.NumberFormat('it-IT', { style: 'currency', currency: 'EUR' }).format(context.parsed.y)}`,
                            footer: (items) => {
                                const index = items[0].dataIndex;
                                const value = items[0].chart.data.operationalVariances[index];
                                return `Scostamento Operativo: ${new Intl.NumberFormat('it-IT', { style: 'currency', currency: 'EUR' }).format(Number(value))}`;
                            },
                        },
                    },
                },
                scales: {
                    x: { grid: { display: false }, ticks: { autoSkip: false, maxRotation: 45, minRotation: 0 } },
                    y: { beginAtZero: true, grid: { color: 'rgba(145, 163, 168, 0.12)' }, ticks: { callback: (value) => new Intl.NumberFormat('it-IT', { style: 'currency', currency: 'EUR', notation: 'compact' }).format(value) } },
                },
                onClick: (event, elements, chart) => {
                    if (! elements.length) return;
                    const url = chart.data.sourceUrls[elements[0].index];
                    if (url) window.location.assign(url);
                },
            }
            JS);
    }
}
