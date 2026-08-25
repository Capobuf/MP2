<?php

namespace App\Filament\Resources\Expenses\Schemas;

use App\Domain\Company\AuditEventType;
use App\Domain\Expenses\ExpenseLineType;
use App\Filament\Pages\CompanyAudit;
use App\Filament\Resources\Contracts\ContractResource;
use App\Filament\Resources\Projects\ProjectResource;
use App\Models\AuditEvent;
use App\Models\Expense;
use App\Models\ExpenseLine;
use Filament\Infolists\Components\RepeatableEntry;
use Filament\Infolists\Components\RepeatableEntry\TableColumn;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Enums\Alignment;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

class ExpenseInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Spesa')->schema([
                TextEntry::make('code')->label('Codice')->state(fn (Expense $record): string => '#'.$record->id),
                TextEntry::make('description')->label('Descrizione'),
                TextEntry::make('state')->label('Stato')->state(fn (Expense $record): string => $record->isReversed() ? 'Stornata' : 'Attiva')
                    ->badge()->color(fn (string $state): string => $state === 'Attiva' ? 'success' : 'gray'),
                TextEntry::make('origin_key')->label('OriginKey')->state(fn (Expense $record): string => $record->originKey())->copyable(),
            ])->columns(2),
            Section::make('Riepilogo economico')->schema([
                TextEntry::make('allocation')->label('Totale Stima')->state(fn (Expense $record): string => $record->allocation())
                    ->money('EUR', locale: 'it')->color('primary'),
                TextEntry::make('actual')->label('Totale Effettivo')->state(fn (Expense $record): string => $record->actual())
                    ->money('EUR', locale: 'it')->color('success'),
                TextEntry::make('variance')->label('Scostamento Operativo')->state(fn (Expense $record): string => $record->operationalVariance())
                    ->money('EUR', locale: 'it'),
            ])->columns(3),
            Section::make('Dati principali')->schema([
                TextEntry::make('exercise.year')->label('Esercizio'),
                TextEntry::make('container')->label('Contenitore')
                    ->state(fn (Expense $record): string => $record->containerLabel())
                    ->url(fn (Expense $record): ?string => match (true) {
                        $record->project !== null => ProjectResource::getUrl('view', ['record' => $record->project]),
                        $record->contract !== null => ContractResource::getUrl('view', ['record' => $record->contract]),
                        default => null,
                    }),
                TextEntry::make('supplier_display')->label('Fornitore')->placeholder('—')
                    ->state(fn (Expense $record): ?string => $record->supplier === null
                        ? null
                        : $record->supplier->legal_name.($record->supplier->isArchived() ? ' · Archiviato' : '')),
                TextEntry::make('cost_center_display')->label('Centro di Costo')
                    ->state(fn (Expense $record): string => $record->costCenterLabel()),
            ])->columns(2),
            Section::make('Note')->schema([
                TextEntry::make('notes')->hiddenLabel(),
            ])->visible(fn (Expense $record): bool => filled($record->notes))->columnSpanFull(),
            Section::make('Righe della Spesa')
                ->description('Le Stime e gli Effettivi restano separati e il Totale della Riga è l’Importo autoritativo.')
                ->schema([
                    RepeatableEntry::make('lines')->hiddenLabel()->table([
                        TableColumn::make('Tipo'),
                        TableColumn::make('Importo unitario')->alignment(Alignment::End),
                        TableColumn::make('Quantità'),
                        TableColumn::make('Totale')->alignment(Alignment::End),
                        TableColumn::make('Stato'),
                        TableColumn::make('Nota'),
                        TableColumn::make('Ultima modifica')->alignment(Alignment::End),
                    ])->schema([
                        TextEntry::make('type')->label('Tipo')->formatStateUsing(
                            fn (mixed $state): string => ($state instanceof ExpenseLineType ? $state : ExpenseLineType::from((string) $state))->label(),
                        )->badge()->color(fn (mixed $state): string => ($state instanceof ExpenseLineType ? $state : ExpenseLineType::from((string) $state)) === ExpenseLineType::Estimate ? 'primary' : 'success'),
                        TextEntry::make('unit_amount')->label('Importo unitario')
                            ->state(fn (ExpenseLine $record): ?string => self::formatUnitAmount($record))
                            ->placeholder('—'),
                        TextEntry::make('quantity')->label('Quantità')->placeholder('—'),
                        TextEntry::make('amount')->label('Totale')->money('EUR', locale: 'it')->weight('semibold'),
                        TextEntry::make('line_state')->label('Stato')
                            ->state(fn (ExpenseLine $record): string => $record->isAnnulled() ? 'Annullata' : 'Attiva')
                            ->badge()->color(fn (string $state): string => $state === 'Attiva' ? 'success' : 'gray'),
                        TextEntry::make('note')->label('Nota')->placeholder('—')->wrap(),
                        TextEntry::make('updated_at')->label('Ultima modifica')
                            ->formatStateUsing(fn (mixed $state, ExpenseLine $record): string => $record->updated_at
                                ->timezone($record->expense->company->timezone)
                                ->format('d/m/Y H:i')),
                    ])->columnSpanFull(),
                ])->columnSpanFull(),
            Section::make('Timeline recente')->description('Ultimi eventi della Spesa e delle sue Righe.')
                ->schema([
                    RepeatableEntry::make('recent_timeline')->hiddenLabel()
                        ->state(fn (Expense $record): Collection => self::recentEvents($record))
                        ->table([
                            TableColumn::make('Data'),
                            TableColumn::make('Evento'),
                            TableColumn::make('Autore'),
                            TableColumn::make('Motivo'),
                        ])->schema([
                            TextEntry::make('created_at')->label('Data')
                                ->formatStateUsing(fn (mixed $state, AuditEvent $record): string => $record->created_at
                                    ->timezone($record->company->timezone)
                                    ->format('d/m/Y H:i')),
                            TextEntry::make('event_type')->label('Evento')->formatStateUsing(
                                fn (mixed $state): string => ($state instanceof AuditEventType ? $state : AuditEventType::from((string) $state))->label(),
                            ),
                            TextEntry::make('actor.name')->label('Autore'),
                            TextEntry::make('reason')->label('Motivo')->placeholder('—')->wrap(),
                        ])->columnSpanFull(),
                    TextEntry::make('timeline_link')->hiddenLabel()->state('Vedi Timeline completa')
                        ->url(fn (Expense $record): string => CompanyAudit::getUrl([
                            'tenant' => $record->company,
                            'expense' => $record->id,
                        ])),
                ])->columnSpanFull(),
        ]);
    }

    /** @return Collection<int, AuditEvent> */
    private static function recentEvents(Expense $expense): Collection
    {
        return AuditEvent::query()
            ->where('company_id', $expense->company_id)
            ->where(fn (Builder $query): Builder => $query
                ->where(fn (Builder $expenseEvent): Builder => $expenseEvent
                    ->where('subject_type', Expense::class)
                    ->where('subject_id', $expense->id))
                ->orWhere(fn (Builder $lineEvent): Builder => $lineEvent
                    ->where('subject_type', ExpenseLine::class)
                    ->whereIn('subject_id', $expense->lines()->select('expense_lines.id'))))
            ->with(['actor', 'company'])
            ->latest('created_at')
            ->latest('id')
            ->limit(5)
            ->get();
    }

    private static function formatUnitAmount(ExpenseLine $line): ?string
    {
        $amount = $line->getRawOriginal('unit_amount');

        return is_string($amount) ? '€ '.str_replace('.', ',', $amount) : null;
    }
}
