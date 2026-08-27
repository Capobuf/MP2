<?php

namespace App\Filament\Resources\Contracts\Tables;

use App\Domain\Contracts\ContractAnnualAllocation;
use App\Domain\Contracts\ContractStateTimeline;
use App\Domain\Expenses\Decimal;
use App\Filament\Resources\Contracts\ContractResource;
use App\Models\Company;
use App\Models\Contract;
use App\Models\CostCenter;
use App\Models\Exercise;
use App\Models\Supplier;
use App\Models\TenantCompany;
use App\Support\ExerciseContext;
use Carbon\CarbonImmutable;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Facades\Filament;
use Filament\Support\Enums\Alignment;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Enums\FiltersLayout;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class ContractsTable
{
    public static function configure(Table $table): Table
    {
        /** @var array<int, array{state: string, reference_date: string|null, cost_center: string, allocation: string, actual: string, variance: string}> $annualCache */
        $annualCache = [];
        $annual = function (Contract $record) use (&$annualCache): array {
            if (isset($annualCache[$record->id])) {
                return $annualCache[$record->id];
            }

            $exercise = app(ExerciseContext::class)->current($record->company);

            return $annualCache[$record->id] = self::annualValues($record, $exercise);
        };

        return $table->columns([
            TextColumn::make('title')->label('Titolo')->searchable()->sortable()->wrap(),
            TextColumn::make('supplier.legal_name')->label('Fornitore')->searchable(),
            TextColumn::make('current_state')->label('Stato')
                ->state(fn (Contract $record): string => $annual($record)['state'])
                ->description(fn (Contract $record): ?string => $annual($record)['reference_date'] === null
                    ? null
                    : 'Riferimento '.$annual($record)['reference_date'])
                ->badge(),
            TextColumn::make('cost_center')->label('Centro di Costo')->state(fn (Contract $record): string => $annual($record)['cost_center']),
            TextColumn::make('allocation')->label('Allocato')->state(fn (Contract $record): string => $annual($record)['allocation'])
                ->money('EUR', locale: 'it')->alignment(Alignment::End),
            TextColumn::make('actual')->label('Effettivo')->state(fn (Contract $record): string => $annual($record)['actual'])
                ->money('EUR', locale: 'it')->alignment(Alignment::End),
            TextColumn::make('variance')->label('Scostamento')->state(fn (Contract $record): string => $annual($record)['variance'])
                ->money('EUR', locale: 'it')->alignment(Alignment::End),
            TextColumn::make('next_expiry_date')->label('Prossima scadenza')->date('d/m/Y')->placeholder('Scadenza non definita')->sortable(),
            TextColumn::make('automatic_renewal')->label('Rinnovo automatico')->formatStateUsing(fn (bool $state): string => $state ? 'Sì' : 'No'),
            TextColumn::make('contractual_start_date')->label('Data inizio')->date('d/m/Y')->sortable()
                ->toggleable(isToggledHiddenByDefault: true),
            TextColumn::make('archive_state')->label('Visibilità')->state(fn (Contract $record): string => $record->isArchived() ? 'Archiviato' : 'Attivo')->badge()
                ->toggleable(isToggledHiddenByDefault: true),
            TextColumn::make('updated_at')->label('Ultima modifica')->dateTime('d/m/Y H:i')->sortable()
                ->toggleable(isToggledHiddenByDefault: true),
        ])->filters([
            SelectFilter::make('supplier')->label('Fornitore')
                ->native(false)
                ->options(function (): array {
                    $tenant = Filament::getTenant();

                    return $tenant instanceof TenantCompany
                        ? Supplier::query()->whereBelongsTo($tenant->company, 'company')->orderBy('legal_name')->pluck('legal_name', 'id')->all()
                        : [];
                })
                ->query(fn (Builder $query, array $data): Builder => blank($data['value'] ?? null)
                    ? $query
                    : $query->where('supplier_id', $data['value'])),
            SelectFilter::make('cost_center')->label('Centro di Costo')
                ->native(false)
                ->options(function (): array {
                    $tenant = Filament::getTenant();

                    return $tenant instanceof TenantCompany
                        ? CostCenter::query()->whereBelongsTo($tenant->company, 'company')->orderBy('name')->pluck('name', 'id')->all()
                        : [];
                })
                ->query(function (Builder $query, array $data): Builder {
                    $tenant = Filament::getTenant();
                    $company = $tenant instanceof TenantCompany ? $tenant->company : null;
                    $costCenterId = $data['value'] ?? null;
                    $exercise = $company instanceof Company ? app(ExerciseContext::class)->current($company) : null;

                    return blank($costCenterId) || $exercise === null
                        ? $query
                        : $query->whereHas('classifications', fn (Builder $classification): Builder => $classification
                            ->where('exercise_id', $exercise->id)
                            ->where('cost_center_id', $costCenterId));
                }),
            TernaryFilter::make('automatic_renewal')->label('Rinnovo automatico')->native(false)
                ->placeholder('Tutti')->trueLabel('Attivo')->falseLabel('Disattivo'),
            TernaryFilter::make('next_expiry_date')->label('Durata')->native(false)
                ->placeholder('Tutte')->trueLabel('Con scadenza')->falseLabel('Senza scadenza')
                ->queries(
                    true: fn (Builder $query): Builder => $query->whereNotNull('next_expiry_date'),
                    false: fn (Builder $query): Builder => $query->whereNull('next_expiry_date'),
                    blank: fn (Builder $query): Builder => $query,
                ),
            TernaryFilter::make('archived_at')->label('Archivio')->native(false)->placeholder('Tutti')->trueLabel('Archiviati')->falseLabel('Attivi')->default(false)
                ->queries(
                    true: fn (Builder $query): Builder => $query->whereNotNull('archived_at'),
                    false: fn (Builder $query): Builder => $query->whereNull('archived_at'),
                    blank: fn (Builder $query): Builder => $query,
                ),
        ], layout: FiltersLayout::AboveContent)
            ->filtersFormColumns(5)
            ->deferFilters(false)
            ->reorderableColumns()
            ->searchPlaceholder('Cerca per titolo o fornitore')
            ->recordUrl(fn (Contract $record): string => ContractResource::getUrl('view', ['record' => $record]))
            ->recordActions([ViewAction::make(), EditAction::make()])
            ->emptyStateHeading('Nessun contratto')
            ->emptyStateDescription('Crea il primo Contratto per definire condizioni economiche e consultarne i valori annuali.');
    }

    /** @return array{state: string, reference_date: string|null, cost_center: string, allocation: string, actual: string, variance: string} */
    private static function annualValues(Contract $contract, ?Exercise $exercise): array
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

        $today = CarbonImmutable::now($contract->company->timezone)->startOfDay();
        $reference = ContractStateTimeline::referenceDateForExercise($exercise->year, $today);
        $allocation = ContractAnnualAllocation::forYear(
            $contract->conditions,
            $exercise->year,
            fn (string $date) => $contract->stateAtDate($date),
        )->amount;
        $actual = Decimal::sum($contract->expenses
            ->where('exercise_id', $exercise->id)
            ->where('origin', 'manual')
            ->map(fn ($expense): string => $expense->actual()));
        $classification = $contract->classifications->firstWhere('exercise_id', $exercise->id);

        return [
            'state' => $contract->stateAtDate($reference->toDateString())->label(),
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
