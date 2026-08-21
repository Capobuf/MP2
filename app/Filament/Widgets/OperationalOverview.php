<?php

namespace App\Filament\Widgets;

use App\Domain\Expenses\Decimal;
use App\Filament\Resources\Expenses\ExpenseResource;
use App\Models\Company;
use App\Support\ExerciseContext;
use Filament\Facades\Filament;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Illuminate\Support\Number;

class OperationalOverview extends StatsOverviewWidget
{
    protected ?string $heading = 'Sintesi economica';

    protected ?string $description = 'Totali delle Spese attive nell’Esercizio selezionato.';

    protected static bool $isLazy = false;

    protected function getStats(): array
    {
        $company = Filament::getTenant();
        $exercise = $company instanceof Company
            ? app(ExerciseContext::class)->current($company)
            : null;

        if ($exercise === null) {
            return [
                Stat::make('Esercizio', 'Non configurato')
                    ->description('Crea un Esercizio per iniziare.'),
            ];
        }

        $allocation = $exercise->allocation();
        $actual = $exercise->actual();
        $variance = Decimal::subtract($actual, $allocation);
        $expensesUrl = ExpenseResource::getUrl(tenant: $company);

        return [
            Stat::make('Stima', self::formatMoney($allocation))
                ->description("Esercizio {$exercise->year}")
                ->color('primary')
                ->url($expensesUrl),
            Stat::make('Effettivo', self::formatMoney($actual))
                ->description("Esercizio {$exercise->year}")
                ->color('info')
                ->url($expensesUrl),
            Stat::make('Scostamento', self::formatMoney($variance))
                ->description('Effettivo meno Stima')
                ->color(Decimal::compare($variance, '0.00') > 0 ? 'danger' : 'success')
                ->url($expensesUrl),
        ];
    }

    private static function formatMoney(string $amount): string
    {
        return Number::currency((float) $amount, in: 'EUR', locale: 'it');
    }
}
