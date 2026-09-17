<?php

namespace App\Filament\Widgets;

use App\Domain\Expenses\Decimal;
use App\Domain\Reporting\ComparisonCategory;
use Filament\Support\RawJs;

class BudgetVariationChart extends EconomicChartWidget
{
    public function chartSurfaceClass(): string
    {
        return parent::chartSurfaceClass().(($this->economicData()['has_budget'] ?? false) ? '' : ' mp2-economic-chart-summary');
    }

    public function getHeading(): string
    {
        return ($this->economicData()['has_budget'] ?? false)
            ? 'Variazioni vs Budget'
            : 'Allocato ed Effettivo per Tipologia';
    }

    public function getEmptyStateHeading(): string
    {
        return ($this->economicData()['has_budget'] ?? false)
            ? 'Confronto Budget Non Disponibile'
            : 'Nessuna Sorgente Disponibile';
    }

    protected function getType(): string
    {
        return ($this->economicData()['has_budget'] ?? false) ? 'doughnut' : 'bar';
    }

    public function getDescription(): ?string
    {
        $data = $this->economicData();

        return ($data['has_budget'] ?? false)
            ? ($data['comparison_source_count'] ?? 0).' Sorgenti Primarie · Budget Selezionato → Situazione Corrente.'
            : 'Allocato Corrente ed Effettivo di Spese Autonome, Progetti e Contratti.';
    }

    /** @return array<string, mixed> */
    protected function getData(): array
    {
        $dashboard = $this->economicData();
        if (! ($dashboard['has_budget'] ?? false)) {
            return $this->currentData($dashboard['sources'] ?? []);
        }

        if (($dashboard['comparison_source_count'] ?? 0) === 0) {
            return [];
        }

        $categories = [
            ComparisonCategory::Unchanged,
            ComparisonCategory::Added,
            ComparisonCategory::Removed,
            ComparisonCategory::Modified,
        ];

        return [
            'labels' => array_map(fn (ComparisonCategory $category): string => $category->label(), $categories),
            'comparisonUrl' => $dashboard['comparison_url'],
            'datasets' => [[
                'label' => 'Sorgenti',
                'data' => array_map(fn (ComparisonCategory $category): int => (int) $dashboard['comparison_categories'][$category->value], $categories),
                'backgroundColor' => ['#39D5C4', '#60A5FA', '#EF4444', '#F59E0B'],
                'borderColor' => '#0B1D25',
                'borderWidth' => 3,
                'hoverOffset' => 8,
            ]],
        ];
    }

    /**
     * @param  array<int, array<string, mixed>>  $sources
     * @return array<string, mixed>
     */
    private function currentData(array $sources): array
    {
        if ($sources === []) {
            return [];
        }

        $types = ['expense' => 'Spese Autonome', 'project' => 'Progetti', 'contract' => 'Contratti'];
        $allocations = [];
        $actuals = [];
        foreach (array_keys($types) as $type) {
            $rows = array_filter($sources, fn (array $source): bool => $source['source_type'] === $type);
            $allocations[] = (float) Decimal::sum(array_column($rows, 'allocation'));
            $actuals[] = (float) Decimal::sum(array_column($rows, 'actual'));
        }

        return [
            'labels' => array_values($types),
            'datasets' => [
                ['label' => 'Allocato Corrente', 'data' => $allocations, 'backgroundColor' => '#39D5C4', 'borderRadius' => 5, 'maxBarThickness' => 24],
                ['label' => 'Effettivo', 'data' => $actuals, 'backgroundColor' => '#60A5FA', 'borderRadius' => 5, 'maxBarThickness' => 24],
            ],
        ];
    }

    protected function getOptions(): RawJs
    {
        if (! ($this->economicData()['has_budget'] ?? false)) {
            return $this->options(<<<'JS'
                {
                    indexAxis: 'y',
                    interaction: { mode: 'index', axis: 'y', intersect: false },
                    plugins: {
                        legend: { position: 'bottom', labels: { usePointStyle: true, boxWidth: 12, boxHeight: 8, padding: 18, font: { family: getComputedStyle(document.body).fontFamily } } },
                        tooltip: { padding: 12, callbacks: { label: (context) => `${context.dataset.label}: ${new Intl.NumberFormat('it-IT', { style: 'currency', currency: 'EUR' }).format(context.parsed.x)}` } },
                    },
                    scales: {
                        x: { beginAtZero: true, grid: { color: 'rgba(145, 163, 168, 0.12)' }, ticks: { callback: (value) => new Intl.NumberFormat('it-IT', { style: 'currency', currency: 'EUR', notation: 'compact' }).format(value) } },
                        y: { grid: { display: false } },
                    },
                }
                JS);
        }

        return $this->options(<<<'JS'
            {
                cutout: '68%',
                plugins: {
                    legend: { position: 'bottom', labels: { usePointStyle: true, boxWidth: 12, boxHeight: 8, padding: 18, font: { family: getComputedStyle(document.body).fontFamily } } },
                    tooltip: { padding: 12, callbacks: { label: (context) => `${context.label}: ${context.parsed} sorgenti` } },
                },
                onClick: (event, elements, chart) => {
                    if (elements.length && chart.data.comparisonUrl) window.location.assign(chart.data.comparisonUrl);
                },
            }
            JS);
    }
}
