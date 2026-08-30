<?php

namespace App\Filament\Widgets;

use Filament\Support\RawJs;
use Filament\Widgets\ChartWidget;

class ReportChart extends ChartWidget
{
    protected static bool $isLazy = false;

    protected ?string $pollingInterval = null;

    protected string $view = 'filament.widgets.economic-chart';

    public string $headingText = '';

    public string $descriptionText = '';

    public string $chartType = 'bar';

    public string $variant = 'currency-bar';

    /** @var array<string, mixed> */
    public array $chartData = [];

    public function chartSurfaceClass(): string
    {
        return 'mp2-economic-chart mp2-report-chart';
    }

    public function getHeading(): string
    {
        return $this->headingText;
    }

    public function getDescription(): string
    {
        return $this->descriptionText;
    }

    protected function getType(): string
    {
        return $this->chartType;
    }

    /** @return array<string, mixed> */
    protected function getData(): array
    {
        return $this->chartData;
    }

    protected function getMaxHeight(): ?string
    {
        if (! in_array($this->variant, ['grouped-horizontal', 'variance-horizontal'], true)) {
            return '23rem';
        }

        $rows = count($this->chartData['labels'] ?? []);

        return max(23, 8 + ($rows * 2)).'rem';
    }

    protected function getOptions(): RawJs
    {
        $variantOptions = match ($this->variant) {
            'category-doughnut' => <<<'JS'
                cutout: '68%',
                plugins: {
                    legend: { position: 'bottom', labels: { usePointStyle: true, boxWidth: 8, padding: 16 } },
                    tooltip: { padding: 12, callbacks: { label: (context) => `${context.label}: ${context.parsed} sorgenti` } },
                },
                JS,
            'grouped-horizontal' => <<<'JS'
                indexAxis: 'y',
                interaction: { mode: 'index', intersect: false },
                plugins: {
                    legend: { position: 'bottom', labels: { usePointStyle: true, boxWidth: 8, padding: 16 } },
                    tooltip: { padding: 12, callbacks: { label: (context) => `${context.dataset.label}: ${new Intl.NumberFormat('it-IT', { style: 'currency', currency: 'EUR' }).format(context.parsed.x)}` } },
                },
                scales: {
                    x: { beginAtZero: true, grid: { color: 'rgba(145, 163, 168, 0.12)' }, ticks: { callback: (value) => new Intl.NumberFormat('it-IT', { style: 'currency', currency: 'EUR', notation: 'compact' }).format(value) } },
                    y: { grid: { display: false }, ticks: { autoSkip: false } },
                },
                JS,
            'variance-horizontal' => <<<'JS'
                indexAxis: 'y',
                plugins: {
                    legend: { display: false },
                    tooltip: { padding: 12, callbacks: { label: (context) => `Scostamento Operativo: ${new Intl.NumberFormat('it-IT', { style: 'currency', currency: 'EUR' }).format(context.parsed.x)}` } },
                },
                scales: {
                    x: { grid: { color: (context) => context.tick.value === 0 ? 'rgba(247, 251, 251, 0.55)' : 'rgba(145, 163, 168, 0.10)', lineWidth: (context) => context.tick.value === 0 ? 2 : 1 }, ticks: { callback: (value) => new Intl.NumberFormat('it-IT', { style: 'currency', currency: 'EUR', notation: 'compact' }).format(value) } },
                    y: { grid: { display: false }, ticks: { autoSkip: false } },
                },
                JS,
            default => <<<'JS'
                interaction: { mode: 'index', intersect: false },
                plugins: {
                    legend: { display: false },
                    tooltip: { padding: 12, callbacks: { label: (context) => `${context.dataset.label}: ${new Intl.NumberFormat('it-IT', { style: 'currency', currency: 'EUR' }).format(context.parsed.y)}` } },
                },
                scales: {
                    x: { grid: { display: false } },
                    y: { beginAtZero: true, grid: { color: 'rgba(145, 163, 168, 0.12)' }, ticks: { callback: (value) => new Intl.NumberFormat('it-IT', { style: 'currency', currency: 'EUR', notation: 'compact' }).format(value) } },
                },
                JS,
        };

        return RawJs::make(<<<JS
            {
                responsive: true,
                maintainAspectRatio: false,
                animation: window.matchMedia('(prefers-reduced-motion: reduce)').matches
                    ? false
                    : { duration: 650, easing: 'easeOutQuart' },
                transitions: window.matchMedia('(prefers-reduced-motion: reduce)').matches
                    ? { active: { animation: { duration: 0 } } }
                    : { active: { animation: { duration: 180, easing: 'easeOutQuart' } } },
                {$variantOptions}
            }
            JS);
    }
}
