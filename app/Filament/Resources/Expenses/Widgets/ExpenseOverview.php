<?php

namespace App\Filament\Resources\Expenses\Widgets;

use App\Domain\Expenses\Decimal;
use App\Domain\Expenses\ExpenseLineType;
use App\Models\Company;
use App\Models\Expense;
use App\Models\ExpenseLine;
use App\Models\TenantCompany;
use App\Support\ExerciseContext;
use Filament\Facades\Filament;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Illuminate\Database\Eloquent\Builder;

class ExpenseOverview extends StatsOverviewWidget
{
    protected ?string $heading = 'Sintesi Spese';

    protected ?string $description = 'Valori delle Spese nell’Esercizio Globale Selezionato.';

    protected static bool $isLazy = false;

    protected function getStats(): array
    {
        $tenant = Filament::getTenant();
        $company = $tenant instanceof TenantCompany ? $tenant->company : null;
        $exercise = $company instanceof Company
            ? app(ExerciseContext::class)->current($company)
            : null;

        if (! $company instanceof Company || $exercise === null) {
            return [
                Stat::make('Esercizio', 'Non configurato')
                    ->description('Crea o seleziona un Esercizio per visualizzare i KPI.'),
            ];
        }

        $expenses = Expense::query()
            ->where('company_id', $company->id)
            ->where('exercise_id', $exercise->id);

        return [
            Stat::make('Spese rappresentate', (clone $expenses)->count())
                ->description("Spese Registrate nell’Esercizio {$exercise->year}"),
            Stat::make('Totale Stime', self::formatMoney($company, $exercise->id, ExpenseLineType::Estimate))
                ->description('Righe Stima Attive delle Spese Attive')
                ->color('primary'),
            Stat::make('Totale Effettivi', self::formatMoney($company, $exercise->id, ExpenseLineType::Actual))
                ->description('Righe Effettivo Attive delle Spese Attive')
                ->color('success'),
            Stat::make('Senza Fornitore', (clone $expenses)->whereNull('supplier_id')->count())
                ->description('Spese senza Fornitore nell’Esercizio'),
        ];
    }

    private static function formatMoney(Company $company, int $exerciseId, ExpenseLineType $type): string
    {
        $sum = ExpenseLine::query()
            ->whereHas('expense', fn (Builder $query): Builder => $query
                ->where('company_id', $company->id)
                ->where('exercise_id', $exerciseId)
                ->whereNull('reversed_at'))
            ->whereNull('annulled_at')
            ->where('type', $type->value)
            ->sum('amount');
        $amount = Decimal::money((string) $sum);
        $negative = str_starts_with($amount, '-');
        [$integer, $decimals] = explode('.', ltrim($amount, '-'));
        $integer = preg_replace('/\B(?=(\d{3})+(?!\d))/', '.', $integer) ?? $integer;

        return ($negative ? '- ' : '').'€ '.$integer.','.$decimals;
    }
}
