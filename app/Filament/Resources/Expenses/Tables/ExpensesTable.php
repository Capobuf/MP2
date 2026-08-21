<?php

namespace App\Filament\Resources\Expenses\Tables;

use App\Filament\Resources\Contracts\ContractResource;
use App\Filament\Resources\Expenses\ExpenseResource;
use App\Filament\Resources\Expenses\Pages\ListExpenses;
use App\Filament\Resources\Projects\ProjectResource;
use App\Models\Company;
use App\Models\CostCenter;
use App\Models\Expense;
use App\Models\Supplier;
use App\Support\ExerciseContext;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Support\Enums\Alignment;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Enums\FiltersLayout;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class ExpensesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(function (Builder $query): Builder {
                $company = Filament::getTenant();
                $exercise = $company instanceof Company
                    ? app(ExerciseContext::class)->current($company)
                    : null;

                return $exercise === null ? $query : $query->where('exercise_id', $exercise->id);
            })
            ->columns([
                TextColumn::make('id')->label('ID')->formatStateUsing(fn (int $state): string => '#'.$state)->searchable()->sortable(),
                TextColumn::make('description')->label('Descrizione')->searchable()->sortable()->wrap(),
                TextColumn::make('container')->label('Contenitore')
                    ->state(fn (Expense $record): string => $record->containerLabel())
                    ->url(fn (Expense $record): ?string => match (true) {
                        $record->project !== null => ProjectResource::getUrl('view', ['record' => $record->project]),
                        $record->contract !== null => ContractResource::getUrl('view', ['record' => $record->contract]),
                        default => null,
                    })->visibleFrom('md'),
                TextColumn::make('supplier.legal_name')->label('Fornitore')->searchable()->placeholder('—')->wrap()->visibleFrom('md'),
                TextColumn::make('cost_center')->label('Centro di Costo')->state(fn (Expense $record): string => $record->costCenterLabel())
                    ->wrap()->visibleFrom('md'),
                TextColumn::make('allocation')->label('Stima')->state(fn (Expense $record): string => $record->allocation())
                    ->money('EUR', locale: 'it')->alignment(Alignment::End)->color('primary'),
                TextColumn::make('actual')->label('Effettivo')->state(fn (Expense $record): string => $record->actual())
                    ->money('EUR', locale: 'it')->alignment(Alignment::End)->color('success'),
                TextColumn::make('variance')->label('Scostamento')->state(fn (Expense $record): string => $record->operationalVariance())
                    ->money('EUR', locale: 'it')->alignment(Alignment::End)->visibleFrom('md'),
                TextColumn::make('state')->label('Stato')->state(fn (Expense $record): string => $record->isReversed() ? 'Stornata' : 'Attiva')
                    ->badge()->color(fn (string $state): string => $state === 'Attiva' ? 'success' : 'gray'),
                TextColumn::make('exercise.year')->label('Esercizio')->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                TernaryFilter::make('reversed_at')->label('Stato')->native(false)->placeholder('Tutte')->trueLabel('Stornate')->falseLabel('Attive')
                    ->queries(
                        true: fn (Builder $query): Builder => $query->whereNotNull('reversed_at'),
                        false: fn (Builder $query): Builder => $query->whereNull('reversed_at'),
                        blank: fn (Builder $query): Builder => $query,
                    ),
                SelectFilter::make('supplier')->label('Fornitore')
                    ->native(false)
                    ->options(fn (): array => Filament::getTenant() instanceof Company
                            ? Supplier::query()->whereBelongsTo(Filament::getTenant(), 'company')->orderBy('legal_name')->pluck('legal_name', 'id')->all()
                            : [])
                    ->query(fn (Builder $query, array $data): Builder => blank($data['value'] ?? null)
                            ? $query
                            : $query->where('supplier_id', $data['value'])),
                SelectFilter::make('container')->label('Contenitore')->native(false)->options([
                    'autonomous' => 'Autonoma',
                    'project' => 'Progetto',
                    'contract' => 'Contratto',
                ])->query(function (Builder $query, array $data): Builder {
                    return match ($data['value'] ?? null) {
                        'autonomous' => $query->whereNull('project_id')->whereNull('contract_id'),
                        'project' => $query->whereNotNull('project_id'),
                        'contract' => $query->whereNotNull('contract_id'),
                        default => $query,
                    };
                }),
                SelectFilter::make('cost_center')->label('Centro di Costo')
                    ->native(false)
                    ->options(fn (): array => Filament::getTenant() instanceof Company
                        ? CostCenter::query()->whereBelongsTo(Filament::getTenant(), 'company')->orderBy('name')->pluck('name', 'id')->all()
                        : [])
                    ->query(function (Builder $query, array $data): Builder {
                        $costCenterId = $data['value'] ?? null;
                        if (blank($costCenterId)) {
                            return $query;
                        }

                        return $query->where(function (Builder $query) use ($costCenterId): void {
                            $query->where('direct_cost_center_id', $costCenterId)
                                ->orWhereHas('project.classifications', fn (Builder $classification): Builder => $classification
                                    ->whereColumn('project_exercise_classifications.exercise_id', 'expenses.exercise_id')
                                    ->where('cost_center_id', $costCenterId))
                                ->orWhereHas('contract.classifications', fn (Builder $classification): Builder => $classification
                                    ->whereColumn('contract_exercise_classifications.exercise_id', 'expenses.exercise_id')
                                    ->where('cost_center_id', $costCenterId));
                        });
                    }),
            ], layout: FiltersLayout::AboveContent)
            ->filtersFormColumns(4)
            ->deferFilters(false)
            ->searchPlaceholder('Cerca per ID, descrizione o fornitore…')
            ->recordUrl(null)
            ->recordAction('selectExpense')
            ->recordClasses(fn (Expense $record, ListExpenses $livewire): string => $livewire->selectedExpenseId === $record->id
                ? 'mp2-expense-row-selected'
                : 'mp2-expense-row')
            ->selectable()
            ->recordActions([
                Action::make('selectExpense')
                    ->label('Seleziona')
                    ->extraAttributes(['class' => 'mp2-row-select-action'])
                    ->action(fn (Expense $record, ListExpenses $livewire) => $livewire->toggleExpense($record->id)),
                ExpenseResource::reverseAction(),
                ExpenseResource::restoreAction(),
            ])
            ->defaultSort('updated_at', 'desc')
            ->paginationPageOptions([25, 50, 100])
            ->defaultPaginationPageOption(25)
            ->emptyStateHeading('Nessuna spesa nell’Esercizio selezionato')
            ->emptyStateDescription('Crea una Spesa autonoma oppure apri un Progetto per consultarne le Spese associate.');
    }
}
