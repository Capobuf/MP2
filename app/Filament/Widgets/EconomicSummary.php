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
use Filament\Widgets\Widget;

class EconomicSummary extends Widget
{
    protected string $view = 'filament.widgets.economic-summary';

    protected int|string|array $columnSpan = 'full';

    protected static bool $isLazy = false;

    /** @return array<string, mixed> */
    protected function getViewData(): array
    {
        $tenant = Filament::getTenant();
        $company = $tenant instanceof TenantCompany ? $tenant->company : null;
        $user = auth()->user();
        if (! $company instanceof Company || ! $user instanceof User) {
            return ['dashboard' => null];
        }

        $exercise = app(ExerciseContext::class)->current($company);
        if (! $exercise instanceof Exercise) {
            return ['dashboard' => null];
        }

        $budget = app(BudgetContext::class)->current($company, $exercise);

        return [
            'dashboard' => app(EconomicDashboardReadModel::class)->load($user, $company, $exercise, $budget),
        ];
    }
}
