<?php

namespace App\Filament\Widgets;

use App\Domain\Reporting\ComparisonCategory;
use Filament\Support\RawJs;

class BudgetVariationChart extends EconomicChartWidget
{
    protected ?string $heading = 'Variazioni vs Budget';

    protected ?string $emptyStateHeading = 'Confronto Budget non disponibile';

    protected function getType(): string
    {
        return 'doughnut';
    }

    public function getDescription(): ?string
    {
        $data = $this->economicData();

        return ($data['has_budget'] ?? false)
            ? ($data['comparison_source_count'] ?? 0).' sorgenti primarie · Budget selezionato → Situazione Corrente.'
            : 'Seleziona una versione di Budget nel contesto globale per classificare le variazioni.';
    }

    /** @return array<string, mixed> */
    protected function getData(): array
    {
        $dashboard = $this->economicData();
        if (! ($dashboard['has_budget'] ?? false) || ($dashboard['comparison_source_count'] ?? 0) === 0) {
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

    protected function getOptions(): RawJs
    {
        return $this->options(<<<'JS'
            {
                cutout: '68%',
                plugins: {
                    legend: { position: 'bottom', labels: { usePointStyle: true, boxWidth: 8, padding: 16 } },
                    tooltip: { padding: 12, callbacks: { label: (context) => `${context.label}: ${context.parsed} sorgenti` } },
                },
                onClick: (event, elements, chart) => {
                    if (elements.length && chart.data.comparisonUrl) window.location.assign(chart.data.comparisonUrl);
                },
            }
            JS);
    }
}
