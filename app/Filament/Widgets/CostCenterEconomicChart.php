<?php

namespace App\Filament\Widgets;

use Filament\Support\RawJs;

class CostCenterEconomicChart extends EconomicChartWidget
{
    private const RADAR_AXIS_LIMIT = 12;

    protected ?string $heading = 'Centri di Costo';

    protected ?string $emptyStateHeading = 'Nessun Centro di Costo disponibile';

    protected function getType(): string
    {
        $count = count($this->economicData()['cost_centers'] ?? []);

        return ($count > 0 && $count < 3) || $count > self::RADAR_AXIS_LIMIT
            ? 'bar'
            : 'radar';
    }

    public function getDescription(): ?string
    {
        $data = $this->economicData();
        if (! ($data['has_budget'] ?? false)) {
            return 'Seleziona una versione di Budget nel contesto globale per visualizzare il confronto.';
        }

        $count = count($data['cost_centers'] ?? []);

        return match (true) {
            $count > self::RADAR_AXIS_LIMIT => "{$count} Centri di Costo · barre orizzontali per preservare tutti i dati oltre 12 assi.",
            $count > 0 && $count < 3 => "{$count} Centri di Costo · barre orizzontali perché un Radar richiede almeno tre assi leggibili.",
            default => 'Budget selezionato, Allocato Corrente ed Effettivo per classificazione annuale.',
        };
    }

    /** @return array<string, mixed> */
    protected function getData(): array
    {
        $dashboard = $this->economicData();
        if (! ($dashboard['has_budget'] ?? false) || ($dashboard['cost_centers'] ?? []) === []) {
            return [];
        }

        $centers = $dashboard['cost_centers'];

        return [
            'labels' => array_column($centers, 'label'),
            'sourceUrls' => array_column($centers, 'url'),
            'datasets' => [
                ['label' => 'Budget selezionato', 'data' => array_map('floatval', array_column($centers, 'budget')), 'borderColor' => '#91A3A8', 'backgroundColor' => 'rgba(145, 163, 168, 0.12)', 'borderWidth' => 2, 'pointRadius' => 3],
                ['label' => 'Allocato Corrente', 'data' => array_map('floatval', array_column($centers, 'allocation')), 'borderColor' => '#39D5C4', 'backgroundColor' => 'rgba(57, 213, 196, 0.14)', 'borderWidth' => 2, 'pointRadius' => 3],
                ['label' => 'Effettivo', 'data' => array_map('floatval', array_column($centers, 'actual')), 'borderColor' => '#60A5FA', 'backgroundColor' => 'rgba(96, 165, 250, 0.13)', 'borderWidth' => 2, 'pointRadius' => 3],
            ],
        ];
    }

    protected function getOptions(): RawJs
    {
        $isBar = $this->getType() === 'bar';
        $typeOptions = $isBar
            ? "indexAxis: 'y', scales: { x: { beginAtZero: true, grid: { color: 'rgba(145, 163, 168, 0.12)' }, ticks: { callback: (value) => new Intl.NumberFormat('it-IT', { style: 'currency', currency: 'EUR', notation: 'compact' }).format(value) } }, y: { grid: { display: false } } },"
            : "scales: { r: { beginAtZero: true, grid: { color: 'rgba(145, 163, 168, 0.14)' }, angleLines: { color: 'rgba(145, 163, 168, 0.14)' }, pointLabels: { font: { size: 11 } }, ticks: { display: false } } },";
        $tooltipValue = $this->tooltipValueExpression($isBar);
        $options = <<<'JS'
            {
                __TYPE_OPTIONS__
                interaction: { mode: 'index', intersect: false },
                plugins: {
                    legend: { position: 'bottom', labels: { usePointStyle: true, boxWidth: 8, padding: 16 } },
                    tooltip: { padding: 12, callbacks: { label: (context) => `${context.dataset.label}: ${new Intl.NumberFormat('it-IT', { style: 'currency', currency: 'EUR' }).format(__TOOLTIP_VALUE__)}` } },
                },
                onClick: (event, elements, chart) => {
                    if (! elements.length) return;
                    const url = chart.data.sourceUrls[elements[0].index];
                    if (url) window.location.assign(url);
                },
            }
            JS;

        return $this->options(str_replace(
            ['__TYPE_OPTIONS__', '__TOOLTIP_VALUE__'],
            [$typeOptions, $tooltipValue],
            $options,
        ));
    }

    private function tooltipValueExpression(bool $isBar): string
    {
        return $isBar ? 'context.parsed.x' : 'context.parsed.r';
    }
}
