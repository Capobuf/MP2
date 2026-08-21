<?php

namespace App\Filament\Pages;

use App\Filament\Widgets\OperationalOverview;
use App\Models\Company;
use App\Support\ExerciseContext;
use Filament\Facades\Filament;
use Filament\Pages\Dashboard as BaseDashboard;
use Filament\Widgets\Widget;
use Filament\Widgets\WidgetConfiguration;

class Dashboard extends BaseDashboard
{
    protected static ?string $title = 'Panoramica';

    protected static string|\UnitEnum|null $navigationGroup = 'Panoramica';

    public function getSubheading(): ?string
    {
        $company = Filament::getTenant();
        $exercise = $company instanceof Company
            ? app(ExerciseContext::class)->current($company)
            : null;

        return $exercise === null
            ? 'Configura un Esercizio per visualizzare i valori economici.'
            : "Situazione dell’Esercizio {$exercise->year} · {$exercise->status()->label()}";
    }

    /** @return array<class-string<Widget>|WidgetConfiguration> */
    public function getWidgets(): array
    {
        return [OperationalOverview::class];
    }

    public function getColumns(): int|array
    {
        return 1;
    }
}
