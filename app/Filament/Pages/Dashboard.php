<?php

namespace App\Filament\Pages;

use App\Filament\Widgets\AllocationComparisonScatterChart;
use App\Filament\Widgets\BudgetVariationChart;
use App\Filament\Widgets\CostCenterEconomicChart;
use App\Filament\Widgets\EconomicSummary;
use App\Filament\Widgets\OperationalVarianceBySourceChart;
use App\Filament\Widgets\SourceEconomicProfileChart;
use App\Models\TenantCompany;
use App\Models\User;
use Filament\Facades\Filament;
use Filament\Pages\Dashboard as BaseDashboard;
use Filament\Widgets\Widget;
use Filament\Widgets\WidgetConfiguration;

class Dashboard extends BaseDashboard
{
    protected static ?string $title = 'Panoramica';

    protected static ?string $navigationLabel = 'Panoramica';

    public static function canAccess(): bool
    {
        $tenant = Filament::getTenant();
        $user = auth()->user();

        return $tenant instanceof TenantCompany
            && $user instanceof User
            && $user->canAccessTenant($tenant);
    }

    /** @return array<class-string<Widget>|WidgetConfiguration> */
    public function getWidgets(): array
    {
        return [
            EconomicSummary::class,
            SourceEconomicProfileChart::class,
            CostCenterEconomicChart::class,
            BudgetVariationChart::class,
            AllocationComparisonScatterChart::class,
            OperationalVarianceBySourceChart::class,
        ];
    }

    public function getColumns(): int|array
    {
        return ['default' => 1, 'lg' => 2];
    }
}
