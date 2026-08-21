<?php

namespace App\Filament\Resources\Contracts\RelationManagers;

use App\Domain\Contracts\ContractAnnualAllocation;
use App\Domain\Contracts\ContractStateTimeline;
use App\Domain\Expenses\Decimal;
use App\Models\Contract;
use App\Models\Exercise;
use Carbon\CarbonImmutable;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

class ContractAnnualSituationsRelationManager extends RelationManager
{
    protected static string $relationship = 'exercises';

    protected static ?string $title = 'Situazioni annuali';

    public static function canViewForRecord(Model $ownerRecord, string $pageClass): bool
    {
        return $ownerRecord instanceof Contract && auth()->user()?->can('view', $ownerRecord) === true;
    }

    public function table(Table $table): Table
    {
        return $table->columns([
            TextColumn::make('year')->label('Esercizio')->sortable(),
            TextColumn::make('reference_date')->label('Data di riferimento')->state(fn (Exercise $record): string => $this->reference($record)->format('d/m/Y')),
            TextColumn::make('state')->label('Stato')->state(fn (Exercise $record): string => $this->contract()->stateAtDate($this->reference($record)->toDateString())->label())->badge(),
            TextColumn::make('cost_center')->label('Centro di Costo')->state(function (Exercise $record): string {
                $classification = $this->contract()->classifications()
                    ->where('exercise_id', $record->id)
                    ->with('costCenter')
                    ->first();

                return $classification === null || $classification->cost_center_id === null
                    ? 'Non classificato'
                    : $classification->costCenter->name;
            }),
            TextColumn::make('allocation')->label('Allocato')->state(fn (Exercise $record): string => $this->allocation($record)->amount)->money('EUR', locale: 'it'),
            TextColumn::make('actual')->label('Effettivo')->state(fn (Exercise $record): string => Decimal::sum($this->contract()->expenses()->where('exercise_id', $record->id)->where('origin', 'manual')->with('lines')->get()->map->actual()))->money('EUR', locale: 'it'),
            TextColumn::make('composition')->label('Composizione esatta')->state(fn (Exercise $record): string => collect($this->allocation($record)->composition)->map(fn (array $item): string => CarbonImmutable::parse($item['attribution_date'])->format('d/m/Y').' · € '.$item['amount'])->implode(' · ') ?: 'Nessun ciclo')->wrap(),
        ])->defaultSort('year')
            ->emptyStateHeading('Nessuna situazione annuale')
            ->emptyStateDescription('Le situazioni compariranno per gli Esercizi disponibili.');
    }

    private function contract(): Contract
    {
        $record = $this->getOwnerRecord();
        abort_unless($record instanceof Contract, 404);

        return $record;
    }

    private function reference(Exercise $exercise): CarbonImmutable
    {
        $contract = $this->contract();

        return ContractStateTimeline::referenceDateForExercise($exercise->year, CarbonImmutable::now($contract->company->timezone));
    }

    private function allocation(Exercise $exercise): ContractAnnualAllocation
    {
        $contract = $this->contract();

        return ContractAnnualAllocation::forYear($contract->conditions()->get(), $exercise->year, fn (string $date) => $contract->stateAtDate($date));
    }
}
