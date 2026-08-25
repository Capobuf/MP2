<?php

namespace App\Filament\Pages;

use App\Domain\Company\Capability;
use App\Filament\Widgets\AllocationComparisonScatterChart;
use App\Filament\Widgets\BudgetVariationChart;
use App\Filament\Widgets\CostCenterEconomicChart;
use App\Filament\Widgets\EconomicSummary;
use App\Filament\Widgets\OperationalVarianceBySourceChart;
use App\Filament\Widgets\SourceEconomicProfileChart;
use App\Models\Company;
use App\Models\User;
use App\Support\BudgetContext;
use App\Support\ExerciseContext;
use Filament\Facades\Filament;
use Filament\Pages\Dashboard as BaseDashboard;
use Filament\Widgets\Widget;
use Filament\Widgets\WidgetConfiguration;

class Dashboard extends BaseDashboard
{
    protected static ?string $title = 'Panoramica';

    protected static string|\UnitEnum|null $navigationGroup = 'Panoramica';

    public static function canAccess(): bool
    {
        $company = Filament::getTenant();
        $user = auth()->user();

        return $company instanceof Company && $user instanceof User
            && $user->hasCapability($company, Capability::View);
    }

    public function getSubheading(): ?string
    {
        $company = Filament::getTenant();
        $exercise = $company instanceof Company
            ? app(ExerciseContext::class)->current($company)
            : null;

        if ($exercise === null) {
            return 'Configura un Esercizio per visualizzare i valori economici.';
        }

        $budget = app(BudgetContext::class)->current($company, $exercise);
        $budgetLabel = $budget === null
            ? 'Budget non selezionato'
            : "Budget v{$budget->version}";

        return "Intero Esercizio {$exercise->year} · {$exercise->status()->label()} · {$budgetLabel}";
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
