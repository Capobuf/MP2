<?php

namespace App\Filament\Resources\Contracts\Schemas;

use App\Domain\Contracts\ContractAnnualAllocation;
use App\Domain\Contracts\ContractStateTimeline;
use App\Domain\Expenses\Decimal;
use App\Models\Contract;
use App\Models\Exercise;
use Carbon\CarbonImmutable;
use Filament\Infolists\Components\RepeatableEntry;
use Filament\Infolists\Components\RepeatableEntry\TableColumn;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Enums\Alignment;

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
            Section::make('Situazioni annuali')
                ->description('Valori e cicli inclusi per ciascun Esercizio, calcolati alla relativa data di riferimento.')
                ->schema([
                    RepeatableEntry::make('annual_situations')->hiddenLabel()
                        ->state(fn (Contract $record): array => self::annualRows($record))
                        ->table([
                            TableColumn::make('Esercizio'),
                            TableColumn::make('Riferimento'),
                            TableColumn::make('Stato'),
                            TableColumn::make('Centro di Costo'),
                            TableColumn::make('Allocato')->alignment(Alignment::End),
                            TableColumn::make('Effettivo')->alignment(Alignment::End),
                            TableColumn::make('Scostamento')->alignment(Alignment::End),
                            TableColumn::make('Composizione')->width('20rem'),
                        ])
                        ->schema([
                            TextEntry::make('year')->label('Esercizio'),
                            TextEntry::make('reference_date')->label('Riferimento')->date('d/m/Y'),
                            TextEntry::make('state')->label('Stato')->badge(),
                            TextEntry::make('cost_center')->label('Centro di Costo'),
                            TextEntry::make('allocation')->label('Allocato')->money('EUR', locale: 'it'),
                            TextEntry::make('actual')->label('Effettivo')->money('EUR', locale: 'it'),
                            TextEntry::make('variance')->label('Scostamento')->money('EUR', locale: 'it'),
                            TextEntry::make('composition')->label('Composizione')
                                ->listWithLineBreaks()
                                ->placeholder('Nessun ciclo')
                                ->wrap(),
                        ])->columnSpanFull(),
                ])->columnSpanFull(),
        ]);
    }

    private static function currentState(Contract $contract): string
    {
        return $contract->stateAtDate(CarbonImmutable::now($contract->company->timezone)->toDateString())->label();
    }

    /** @return list<array{year: int, reference_date: string, state: string, cost_center: string, allocation: string, actual: string, variance: string, composition: list<string>}> */
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
                'composition' => collect($allocation->composition)
                    ->map(fn (array $item): string => CarbonImmutable::parse($item['attribution_date'])->format('d/m/Y').' · € '.number_format((float) $item['amount'], 2, ',', '.'))
                    ->values()
                    ->all(),
            ];
        })->values()->all();
    }
}
