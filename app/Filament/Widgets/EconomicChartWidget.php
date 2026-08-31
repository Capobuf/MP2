<?php

namespace App\Filament\Widgets;

use App\Models\Company;
use App\Models\Exercise;
use App\Models\TenantCompany;
use App\Models\User;
use App\Support\BudgetContext;
use App\Support\ExerciseContext;
use App\Support\Reporting\EconomicDashboardReadModel;
use Filament\Facades\Filament;
use Filament\Support\RawJs;
use Filament\Widgets\ChartWidget;

abstract class EconomicChartWidget extends ChartWidget
{
    protected static bool $isLazy = false;

    protected ?string $pollingInterval = null;

    protected string $view = 'filament.widgets.economic-chart';

    protected ?string $maxHeight = '23rem';

    /** @var array<string, mixed>|null */
    private ?array $economicData = null;

    public function chartSurfaceClass(): string
    {
        return 'mp2-economic-chart';
    }

    /** @return array<string, mixed>|null */
    protected function economicData(): ?array
    {
        if ($this->economicData !== null) {
            return $this->economicData;
        }

        $tenant = Filament::getTenant();
        $company = $tenant instanceof TenantCompany ? $tenant->company : null;
        $user = auth()->user();
        if (! $company instanceof Company || ! $user instanceof User) {
            return null;
        }

        $exercise = app(ExerciseContext::class)->current($company);
        if (! $exercise instanceof Exercise) {
            return null;
        }

        $budget = app(BudgetContext::class)->current($company, $exercise);

        return $this->economicData = app(EconomicDashboardReadModel::class)
            ->load($user, $company, $exercise, $budget);
    }

    protected function options(string $options): RawJs
    {
        return RawJs::make(<<<JS
            {
                responsive: true,
                maintainAspectRatio: false,
                animation: window.matchMedia('(prefers-reduced-motion: reduce)').matches
                    ? false
                    : { duration: 750, easing: 'easeOutQuart' },
                transitions: window.matchMedia('(prefers-reduced-motion: reduce)').matches
                    ? { active: { animation: { duration: 0 } } }
                    : { active: { animation: { duration: 220, easing: 'easeOutQuart' } } },
                ...({$options})
            }
            JS);
    }
}
