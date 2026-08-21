<?php

namespace App\Filament\Resources\Projects\Tables;

use App\Domain\Expenses\Decimal;
use App\Domain\Expenses\ExpenseLineType;
use App\Domain\Projects\ProjectAnnualReferenceDate;
use App\Filament\Resources\Projects\ProjectResource;
use App\Models\Company;
use App\Models\Exercise;
use App\Models\ExpenseLine;
use App\Models\Project;
use App\Support\ExerciseContext;
use Carbon\CarbonImmutable;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Facades\Filament;
use Filament\Support\Enums\Alignment;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class ProjectsTable
{
    public static function configure(Table $table): Table
    {
        /** @var array<int, array{state: string, reference_date: string|null, cost_center: string, allocation: string, actual: string, variance: string}> $annualCache */
        $annualCache = [];
        $annual = function (Project $record) use (&$annualCache): array {
            if (isset($annualCache[$record->id])) {
                return $annualCache[$record->id];
            }

            $exercise = app(ExerciseContext::class)->current($record->company);

            return $annualCache[$record->id] = self::annualValues($record, $exercise);
        };

        return $table
            ->modifyQueryUsing(function (Builder $query): Builder {
                $company = Filament::getTenant();
                $exercise = $company instanceof Company
                    ? app(ExerciseContext::class)->current($company)
                    : null;

                if ($exercise === null) {
                    return $query;
                }

                return $query->addSelect([
                    'context_allocation' => self::totalSubquery($exercise, ExpenseLineType::Estimate),
                    'context_actual' => self::totalSubquery($exercise, ExpenseLineType::Actual),
                ]);
            })
            ->columns([
                TextColumn::make('title')->label('Titolo')->searchable()->sortable()->wrap(),
                TextColumn::make('current_state')->label('Stato')
                    ->state(fn (Project $record): string => $annual($record)['state'])
                    ->description(fn (Project $record): ?string => $annual($record)['reference_date'] === null
                        ? null
                        : 'Riferimento '.$annual($record)['reference_date'])
                    ->badge(),
                TextColumn::make('cost_center')->label('Centro di Costo')->state(fn (Project $record): string => $annual($record)['cost_center']),
                TextColumn::make('allocation')->label('Allocato')->state(fn (Project $record): string => $annual($record)['allocation'])
                    ->money('EUR', locale: 'it')->alignment(Alignment::End),
                TextColumn::make('actual')->label('Effettivo')->state(fn (Project $record): string => $annual($record)['actual'])
                    ->money('EUR', locale: 'it')->alignment(Alignment::End),
                TextColumn::make('variance')->label('Scostamento')->state(fn (Project $record): string => $annual($record)['variance'])
                    ->money('EUR', locale: 'it')->alignment(Alignment::End),
                TextColumn::make('initial_effective_date')->label('Efficacia iniziale')->date('d/m/Y')->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('archive_state')->label('Visibilità')->state(fn (Project $record): string => $record->isArchived() ? 'Archiviato' : 'Attivo')->badge()
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('updated_at')->label('Ultima modifica')->dateTime('d/m/Y H:i')->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                TernaryFilter::make('archived_at')
                    ->label('Archivio')
                    ->placeholder('Tutti')
                    ->trueLabel('Archiviati')
                    ->falseLabel('Attivi')
                    ->default(false)
                    ->queries(
                        true: fn (Builder $query): Builder => $query->whereNotNull('archived_at'),
                        false: fn (Builder $query): Builder => $query->whereNull('archived_at'),
                        blank: fn (Builder $query): Builder => $query,
                    ),
            ])
            ->searchPlaceholder('Cerca per titolo')
            ->recordUrl(fn (Project $record): string => ProjectResource::getUrl('view', ['record' => $record]))
            ->recordActions([ViewAction::make(), EditAction::make()])
            ->emptyStateHeading('Nessun progetto')
            ->emptyStateDescription('Crea il primo Progetto per pianificare attività e consultarne la situazione annuale.');
    }

    /** @return Builder<ExpenseLine> */
    private static function totalSubquery(Exercise $exercise, ExpenseLineType $type): Builder
    {
        return ExpenseLine::query()
            ->selectRaw('COALESCE(SUM(expense_lines.amount), 0)')
            ->join('expenses', 'expenses.id', '=', 'expense_lines.expense_id')
            ->whereColumn('expenses.project_id', 'projects.id')
            ->where('expenses.exercise_id', $exercise->id)
            ->whereNull('expenses.reversed_at')
            ->whereNull('expense_lines.annulled_at')
            ->where('expense_lines.type', $type->value);
    }

    /** @return array{state: string, reference_date: string|null, cost_center: string, allocation: string, actual: string, variance: string} */
    private static function annualValues(Project $project, ?Exercise $exercise): array
    {
        if ($exercise === null) {
            return [
                'state' => 'Nessun Esercizio',
                'reference_date' => null,
                'cost_center' => 'Non classificato',
                'allocation' => '0.00',
                'actual' => '0.00',
                'variance' => '0.00',
            ];
        }

        $today = CarbonImmutable::now($project->company->timezone)->startOfDay();
        $reference = ProjectAnnualReferenceDate::forYear($exercise->year, $today);
        $classification = $project->classifications->firstWhere('exercise_id', $exercise->id);
        $allocation = Decimal::money((string) ($project->getAttribute('context_allocation') ?? '0'));
        $actual = Decimal::money((string) ($project->getAttribute('context_actual') ?? '0'));

        return [
            'state' => $project->stateAtDate($reference->toDateString())?->label() ?? 'Assente alla data',
            'reference_date' => $reference->format('d/m/Y'),
            'cost_center' => $classification === null || $classification->cost_center_id === null
                ? 'Non classificato'
                : $classification->costCenter->name,
            'allocation' => $allocation,
            'actual' => $actual,
            'variance' => Decimal::subtract($actual, $allocation),
        ];
    }
}
