<?php

namespace App\Filament\Resources\Contracts\Schemas;

use App\Domain\Contracts\ContractAnnualAllocation;
use App\Domain\Contracts\ContractStateTimeline;
use App\Domain\Expenses\Decimal;
use App\Models\Contract;
use App\Models\Exercise;
use Carbon\CarbonImmutable;
use Filament\Infolists\Components\RepeatableEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class ContractInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Identità e stato')->schema([
                TextEntry::make('origin_key')->label('OriginKey')->state(fn (Contract $record): string => $record->originKey()),
                TextEntry::make('title')->label('Titolo'),
                TextEntry::make('supplier.legal_name')->label('Fornitore'),
                TextEntry::make('notes')->label('Note')->placeholder('—'),
                TextEntry::make('current_state')->label('Stato attuale')->state(fn (Contract $record): string => self::currentState($record))->badge(),
                TextEntry::make('archive_state')->label('Visibilità')->state(fn (Contract $record): string => $record->isArchived() ? 'Archiviato' : 'Attivo')->badge(),
                TextEntry::make('contractual_start_date')->label('Data di inizio')->date('d/m/Y'),
                TextEntry::make('next_expiry_date')->label('Prossima scadenza')->date('d/m/Y')->placeholder('Scadenza non definita'),
                TextEntry::make('automatic_renewal')->label('Rinnovo automatico')->formatStateUsing(fn (bool $state): string => $state ? 'Sì' : 'No'),
                TextEntry::make('renewal_duration_months')->label('Durata rinnovo (mesi)')->placeholder('—'),
                TextEntry::make('notice_days')->label('Preavviso (giorni)')->placeholder('—'),
            ])->columns(3),
            Section::make('Situazioni annuali')->schema([
                RepeatableEntry::make('annual_situations')->label('Situazioni annuali')
                    ->state(fn (Contract $record): array => self::annualRows($record))
                    ->schema([
                        TextEntry::make('year')->label('Esercizio'),
                        TextEntry::make('reference_date')->label('Data di riferimento')->date('d/m/Y'),
                        TextEntry::make('state')->label('Stato')->badge(),
                        TextEntry::make('cost_center')->label('Centro di Costo'),
                        TextEntry::make('allocation')->label('Allocato')->money('EUR', locale: 'it'),
                        TextEntry::make('actual')->label('Effettivo')->money('EUR', locale: 'it'),
                        TextEntry::make('variance')->label('Scostamento')->money('EUR', locale: 'it'),
                        TextEntry::make('composition')->label('Composizione esatta')->wrap(),
                    ])->columns(3)->columnSpanFull(),
            ]),
        ]);
    }

    private static function currentState(Contract $contract): string
    {
        return $contract->stateAtDate(CarbonImmutable::now($contract->company->timezone)->toDateString())->label();
    }

    /** @return list<array<string, int|string>> */
    private static function annualRows(Contract $contract): array
    {
        $today = CarbonImmutable::now($contract->company->timezone)->startOfDay();

        return $contract->company->exercises->sortBy('year')->map(function (Exercise $exercise) use ($contract, $today): array {
            $reference = ContractStateTimeline::referenceDateForExercise($exercise->year, $today);
            $allocation = ContractAnnualAllocation::forYear(
                $contract->conditions,
                $exercise->year,
                fn (string $date) => $contract->stateAtDate($date),
            );
            $actual = Decimal::sum($contract->expenses
                ->where('exercise_id', $exercise->id)
                ->where('origin', 'manual')
                ->map(fn ($expense): string => $expense->actual()));
            $classification = $contract->classifications->firstWhere('exercise_id', $exercise->id);

            return [
                'year' => $exercise->year,
                'reference_date' => $reference->toDateString(),
                'state' => $contract->stateAtDate($reference->toDateString())->label(),
                'cost_center' => $classification === null || $classification->cost_center_id === null
                    ? 'Non classificato'
                    : $classification->costCenter->name,
                'allocation' => $allocation->amount,
                'actual' => $actual,
                'variance' => Decimal::subtract($actual, $allocation->amount),
                'composition' => collect($allocation->composition)->map(fn (array $item): string => $item['attribution_date'].' · € '.$item['amount'])->implode(' · ') ?: 'Nessun ciclo',
            ];
        })->values()->all();
    }
}
