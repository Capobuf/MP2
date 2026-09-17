<?php

namespace App\Filament\Widgets;

use App\Domain\Expenses\Decimal;
use Filament\Support\RawJs;

class OperationalVarianceBySourceChart extends EconomicChartWidget
{
    protected ?string $heading = 'Scostamento Operativo per Sorgente';

    protected ?string $description = 'Effettivo Meno Allocato Corrente; Valori Positivi e Negativi si Sviluppano dai Lati Opposti dello Zero.';

    protected ?string $emptyStateHeading = 'Nessuno Scostamento Disponibile';

    public function chartSurfaceClass(): string
    {
        return parent::chartSurfaceClass().' mp2-operational-variance-chart';
    }

    protected function getType(): string
    {
        return 'bar';
    }

    /** @return array<string, mixed> */
    protected function getData(): array
    {
        $dashboard = $this->economicData();
        $sources = array_filter(
            $dashboard['sources'] ?? [],
            fn (array $source): bool => Decimal::compare((string) $source['operational_variance'], '0') !== 0,
        );
        if ($sources === []) {
            return [];
        }

        usort($sources, function (array $left, array $right): int {
            $leftAbsolute = ltrim((string) $left['operational_variance'], '-');
            $rightAbsolute = ltrim((string) $right['operational_variance'], '-');
            $varianceOrder = Decimal::compare($rightAbsolute, $leftAbsolute);

            return $varianceOrder !== 0
                ? $varianceOrder
                : strcmp((string) $left['origin_key'], (string) $right['origin_key']);
        });
        $values = array_map('floatval', array_column($sources, 'operational_variance'));

        return [
            'labels' => array_column($sources, 'label'),
            'sourceUrls' => array_column($sources, 'url'),
            'datasets' => [[
                'label' => 'Scostamento Operativo',
                'data' => $values,
                'backgroundColor' => array_map(fn (float $value): string => match (true) {
                    $value > 0 => '#EF4444',
                    $value < 0 => '#60A5FA',
                    default => '#91A3A8',
                }, $values),
                'borderWidth' => 0,
                'hoverBorderWidth' => 0,
                'borderRadius' => 3,
                'borderSkipped' => false,
                'maxBarThickness' => 10,
            ]],
        ];
    }

    protected function getOptions(): RawJs
    {
        return $this->options(<<<'JS'
            {
                indexAxis: 'y',
                plugins: {
                    legend: { display: false },
                    tooltip: {
                        mode: 'nearest', axis: 'y', intersect: false,
                        backgroundColor: getComputedStyle(document.documentElement).getPropertyValue('--surface-elevated').trim(),
                        borderColor: getComputedStyle(document.documentElement).getPropertyValue('--border-strong').trim(),
                        borderWidth: 1,
                        titleColor: getComputedStyle(document.documentElement).getPropertyValue('--text-primary').trim(),
                        bodyColor: getComputedStyle(document.documentElement).getPropertyValue('--text-secondary').trim(),
                        titleFont: { family: getComputedStyle(document.body).fontFamily, weight: 600 },
                        bodyFont: { family: getComputedStyle(document.body).fontFamily },
                        cornerRadius: 8, displayColors: false, padding: 12,
                        callbacks: { label: (context) => `Scostamento Operativo: ${new Intl.NumberFormat('it-IT', { style: 'currency', currency: 'EUR' }).format(context.parsed.x)}` },
                    },
                },
                scales: {
                    x: {
                        border: { display: false },
                        grid: { display: true, drawTicks: false, color: (context) => context.tick.value === 0 ? getComputedStyle(document.documentElement).getPropertyValue('--border-strong').trim() : 'rgba(145, 163, 168, 0.08)' },
                        ticks: {
                            color: getComputedStyle(document.documentElement).getPropertyValue('--text-muted').trim(),
                            font: { family: getComputedStyle(document.body).fontFamily, size: 11 },
                            padding: 10, maxTicksLimit: 5,
                            callback: (value) => new Intl.NumberFormat('it-IT', { style: 'currency', currency: 'EUR', notation: 'compact' }).format(value),
                        },
                    },
                    y: {
                        border: { display: false },
                        grid: { display: false },
                        ticks: {
                            autoSkip: true,
                            color: getComputedStyle(document.documentElement).getPropertyValue('--text-secondary').trim(),
                            font: { family: getComputedStyle(document.body).fontFamily, size: 11 },
                            padding: 10,
                            callback: function (value) {
                                const label = this.getLabelForValue(value);
                                const maxLength = this.chart.width < 480 ? 24 : 42;

                                return label.length > maxLength ? `${label.slice(0, maxLength - 1)}…` : label;
                            },
                        },
                    },
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
