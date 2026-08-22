<?php

namespace App\Filament\Resources\Contracts\Schemas;

use App\Domain\Contracts\ContractAnnualAllocation;
use App\Domain\Contracts\ContractDeadline;
use App\Domain\Contracts\ContractState;
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
            Section::make('Riepilogo')
                ->description('Stato, controparte e riferimenti essenziali del Contratto.')
                ->schema([
                    TextEntry::make('current_state')
                        ->label('Stato')
                        ->state(fn (Contract $record): string => self::currentState($record)->label())
                        ->badge()
                        ->color(fn (Contract $record): string => self::stateColor(self::currentState($record))),
                    TextEntry::make('archive_state')
                        ->label('Visibilità')
                        ->state(fn (Contract $record): string => $record->isArchived() ? 'Archiviato' : 'Visibile')
                        ->badge()
                        ->color('gray'),
                    TextEntry::make('supplier.legal_name')->label('Fornitore'),
                    TextEntry::make('contractual_start_date')->label('Data di inizio')->date('d/m/Y'),
                    TextEntry::make('state_reference_date')
                        ->label('Riferimento stato')
                        ->state(fn (Contract $record): string => self::today($record)->format('d/m/Y')),
                    TextEntry::make('origin_key')
                        ->label('Riferimento tecnico')
                        ->state(fn (Contract $record): string => $record->originKey()),
                    TextEntry::make('notes')
                        ->label('Note')
                        ->placeholder('Nessuna nota')
                        ->columnSpanFull(),
                ])
                ->columns(['default' => 1, 'md' => 2, 'xl' => 3])
                ->extraAttributes(['class' => 'mp2-contract-summary'])
                ->columnSpanFull(),
            Section::make('Scadenze e rinnovo')
                ->description('Date contrattuali e configurazione di rinnovo. Non sono scadenze di fattura o pagamento.')
                ->schema([
                    TextEntry::make('deadline_next_expiry')
                        ->label('Prossima scadenza')
                        ->state(fn (Contract $record): string => self::formatDate(
                            self::deadline($record)->nextExpiryDate,
                            'Scadenza non definita',
                        )),
                    TextEntry::make('automatic_renewal')
                        ->label('Rinnovo automatico')
                        ->formatStateUsing(fn (bool $state): string => $state ? 'Sì' : 'No'),
                    TextEntry::make('renewal_duration')
                        ->label('Durata rinnovo')
                        ->state(function (Contract $record): string {
                            $months = self::deadline($record)->renewalDurationMonths;

                            return $months === null ? '—' : $months.' mesi';
                        }),
                    TextEntry::make('notice_days')
                        ->label('Preavviso di disdetta')
                        ->state(function (Contract $record): string {
                            $days = self::deadline($record)->noticeDays;

                            return $days === null ? '—' : $days.' giorni';
                        }),
                    TextEntry::make('notice_limit')
                        ->label('Data limite di disdetta')
                        ->state(fn (Contract $record): string => self::formatDate(self::deadline($record)->noticeLimitDate)),
                    TextEntry::make('planned_cessation')
                        ->label('Cessazione pianificata')
                        ->state(fn (Contract $record): string => self::formatDate(self::deadline($record)->plannedCessationDate))
                        ->visible(fn (Contract $record): bool => self::deadline($record)->plannedCessationDate !== null),
                    TextEntry::make('renewal_warning')
                        ->label('Attenzione')
                        ->state('Rinnovo senza condizione economica')
                        ->badge()
                        ->color('warning')
                        ->visible(fn (Contract $record): bool => self::deadline($record)->renewalWithoutCondition)
                        ->columnSpanFull(),
                ])
                ->columns(['default' => 1, 'md' => 2, 'xl' => 3])
                ->extraAttributes(['class' => 'mp2-contract-renewal'])
                ->columnSpanFull(),
            Section::make('Situazioni annuali')
                ->description('Allocato, Effettivo e composizione dei cicli per ciascun Esercizio.')
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
                            TextEntry::make('state')
                                ->label('Stato')
                                ->badge()
                                ->color(fn (string $state): string => self::stateLabelColor($state)),
                            TextEntry::make('cost_center')->label('Centro di Costo'),
                            TextEntry::make('allocation')->label('Allocato')->money('EUR', locale: 'it'),
                            TextEntry::make('actual')->label('Effettivo')->money('EUR', locale: 'it'),
                            TextEntry::make('variance')->label('Scostamento')->money('EUR', locale: 'it'),
                            TextEntry::make('composition')->label('Composizione')
                                ->listWithLineBreaks()
                                ->placeholder('Nessun ciclo')
                                ->wrap(),
                        ])->columnSpanFull(),
                ])
                ->extraAttributes(['class' => 'mp2-contract-annual'])
                ->columnSpanFull(),
        ]);
    }

    private static function currentState(Contract $contract): ContractState
    {
        return $contract->stateAtDate(self::today($contract)->toDateString());
    }

    private static function today(Contract $contract): CarbonImmutable
    {
        return CarbonImmutable::now($contract->company->timezone)->startOfDay();
    }

    private static function deadline(Contract $contract): ContractDeadline
    {
        return ContractDeadline::fromContract($contract, null, self::today($contract));
    }

    private static function formatDate(?string $date, string $fallback = '—'): string
    {
        return $date === null ? $fallback : CarbonImmutable::parse($date)->format('d/m/Y');
    }

    private static function stateColor(ContractState $state): string
    {
        return match ($state) {
            ContractState::Active => 'success',
            ContractState::Planned => 'info',
            ContractState::Cessated => 'gray',
            ContractState::Cancelled => 'danger',
        };
    }

    private static function stateLabelColor(string $state): string
    {
        return match ($state) {
            ContractState::Active->label() => 'success',
            ContractState::Planned->label() => 'info',
            ContractState::Cessated->label() => 'gray',
            ContractState::Cancelled->label() => 'danger',
            default => 'gray',
        };
    }

    /** @return list<array{year: int, reference_date: string, state: string, cost_center: string, allocation: string, actual: string, variance: string, composition: list<string>}> */
    private static function annualRows(Contract $contract): array
    {
        $today = self::today($contract);

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
