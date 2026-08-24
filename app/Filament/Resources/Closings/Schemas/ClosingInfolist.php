<?php

namespace App\Filament\Resources\Closings\Schemas;

use App\Domain\Proposals\ProposalSourceType;
use App\Models\Attachment;
use App\Models\ClosingSnapshot;
use App\Models\ClosingSourceRow;
use App\Models\HistoricalErrorAnnotation;
use App\Models\LateCorrection;
use Filament\Infolists\Components\RepeatableEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Illuminate\Support\HtmlString;

final class ClosingInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Snapshot di Chiusura')
                ->description('Fotografia immutabile della situazione materializzata alla Chiusura.')
                ->schema([
                    TextEntry::make('company_name')->label('Azienda'),
                    TextEntry::make('exercise_year')->label('Esercizio'),
                    TextEntry::make('closed_at')->label('Chiuso il')->dateTime('d/m/Y H:i'),
                    TextEntry::make('closer.name')->label('Chiuso da'),
                    TextEntry::make('initialBudget.version')->label('Budget v1')->formatStateUsing(fn (mixed $state): string => 'v'.$state)->placeholder('Assente'),
                    TextEntry::make('currentBudget.version')->label('Budget corrente')->formatStateUsing(fn (mixed $state): string => 'v'.$state)->placeholder('Assente'),
                    TextEntry::make('total_final_allocation')->label('Allocato finale')->money('EUR', locale: 'it'),
                    TextEntry::make('total_closing_actual')->label('Effettivo alla Chiusura')->money('EUR', locale: 'it'),
                    TextEntry::make('total_operational_variance')->label('Scostamento operativo')->money('EUR', locale: 'it'),
                    TextEntry::make('total_consolidated_carryover')->label('Riporto consolidato')->money('EUR', locale: 'it'),
                    TextEntry::make('next_exercise_disposition')->label('N+1')->formatStateUsing(fn (mixed $state): string => match ((string) $state) {
                        'created' => 'Creato alla Chiusura',
                        'already_existed' => 'Già esistente',
                        'not_created_management_terminated' => 'Non creato · gestione terminata',
                        default => (string) $state,
                    }),
                    TextEntry::make('nextExercise.year')->label('Esercizio successivo')->placeholder('—'),
                ])->columns(3),

            Section::make('Decisioni ed evidenze')
                ->schema([
                    TextEntry::make('accepted_warnings')->label('Avvisi accettati')->state(fn (ClosingSnapshot $record): string => self::warnings($record->accepted_warnings))->columnSpanFull()->wrap(),
                    TextEntry::make('applied_settings')->label('Impostazioni applicate')->state(fn (ClosingSnapshot $record): string => self::json($record->applied_settings))->columnSpanFull()->wrap(),
                    TextEntry::make('operation_id')->label('ID operazione')->copyable(),
                ]),

            Section::make('Sorgenti materializzate')->schema([
                RepeatableEntry::make('rows')->label('')->schema([
                    TextEntry::make('source_type')->label('Tipo')->formatStateUsing(fn ($state): string => $state->label()),
                    TextEntry::make('label')->label('Sorgente'),
                    TextEntry::make('cost_center_label')->label('Centro di Costo'),
                    TextEntry::make('supplier_label')->label('Fornitore')->placeholder('—'),
                    TextEntry::make('end_state')->label('Stato al 31/12')->placeholder('—'),
                    TextEntry::make('has_actuals')->label('Ha Effettivi')->formatStateUsing(fn (bool $state): string => $state ? 'Sì' : 'No'),
                    TextEntry::make('final_estimates')->label('Stime finali')->money('EUR', locale: 'it'),
                    TextEntry::make('received_carryover')->label('Riporto ricevuto')->money('EUR', locale: 'it')->visible(self::projectRow(...)),
                    TextEntry::make('final_allocation')->label('Allocato finale')->money('EUR', locale: 'it'),
                    TextEntry::make('closing_actual')->label('Effettivo alla Chiusura')->money('EUR', locale: 'it'),
                    TextEntry::make('operational_variance')->label('Scostamento')->money('EUR', locale: 'it'),
                    TextEntry::make('detail')->label('Dettaglio materializzato')->state(fn (ClosingSourceRow $record): string => self::json($record->detail))->columnSpanFull()->wrap(),
                ])->columns(4),
            ]),

            Section::make('Correzioni tardive')
                ->description('Evidenze emerse dopo la Chiusura. I valori della Snapshot sopra restano immutabili.')
                ->schema([
                    RepeatableEntry::make('lateCorrections')
                        ->label('')
                        ->placeholder('Nessuna correzione tardiva.')
                        ->schema([
                            TextEntry::make('expense.id')->label('Spesa generata')->prefix('#'),
                            TextEntry::make('expense.description')->label('Descrizione Spesa generata')->wrap(),
                            TextEntry::make('expenseLine.amount')->label('Nuovo Effettivo')->money('EUR', locale: 'it'),
                            TextEntry::make('expenseLine.id')->label('Riga Effettivo generata')->prefix('#'),
                            TextEntry::make('originalExpenseLine.id')->label('Riga originaria')->prefix('#')->placeholder('Non indicata'),
                            TextEntry::make('source_label')->label('Sorgente storica'),
                            TextEntry::make('source_origin_key')->label('Riferimento sorgente'),
                            TextEntry::make('owner_context')->label('Contesto storico')->state(fn (LateCorrection $record): string => self::json($record->owner_context))->columnSpanFull()->wrap(),
                            TextEntry::make('supplier_context')->label('Fornitore storico')->state(fn (LateCorrection $record): string => self::json($record->supplier_context))->placeholder('—'),
                            TextEntry::make('reason')->label('Motivo')->columnSpanFull()->wrap(),
                            TextEntry::make('belongs_to_closed_exercise')->label('Dichiarazione')->formatStateUsing(fn (bool $state): string => $state ? 'Apparteneva realmente a questo Esercizio' : 'Non dichiarata'),
                            TextEntry::make('recordedBy.name')->label('Autore'),
                            TextEntry::make('created_at')->label('Registrata il')->state(fn (LateCorrection $record): string => $record->created_at->copy()->timezone($record->company->timezone)->format('d/m/Y H:i')),
                            TextEntry::make('attachments')
                                ->label('Evidenze conservate')
                                ->state(fn (LateCorrection $record): HtmlString|string => self::attachmentLinks($record->attachments))
                                ->html(),
                        ])
                        ->columns(4),
                ]),

            Section::make('Annotazioni di errore storico')
                ->description('Evidenze immutabili separate dagli importi: Nessun impatto economico.')
                ->schema([
                    RepeatableEntry::make('historicalErrorAnnotations')
                        ->label('')
                        ->placeholder('Nessuna annotazione storica.')
                        ->schema([
                            TextEntry::make('kind')->label('Tipo di errore')->formatStateUsing(fn ($state): string => $state->label()),
                            TextEntry::make('closing_snapshot_id')->label('Snapshot di Chiusura')->prefix('#'),
                            TextEntry::make('recorded_facts')->label('Dato registrato')->state(fn (HistoricalErrorAnnotation $record): string => self::facts($record->recorded_facts))->columnSpanFull()->wrap(),
                            TextEntry::make('believed_correct_facts')->label('Dato ritenuto corretto')->state(fn (HistoricalErrorAnnotation $record): string => self::facts($record->believed_correct_facts))->columnSpanFull()->wrap(),
                            TextEntry::make('affected_sources')->label('Sorgenti interessate')->state(fn (HistoricalErrorAnnotation $record): string => self::sources($record->affected_sources))->columnSpanFull()->wrap(),
                            TextEntry::make('reason')->label('Motivo')->columnSpanFull()->wrap(),
                            TextEntry::make('economic_impact')->label('Impatto economico')->state(fn (): string => 'Nessun impatto economico'),
                            TextEntry::make('recordedBy.name')->label('Autore'),
                            TextEntry::make('created_at')->label('Registrata il')->state(fn (HistoricalErrorAnnotation $record): string => $record->created_at->copy()->timezone($record->company->timezone)->format('d/m/Y H:i')),
                            TextEntry::make('attachments')
                                ->label('Evidenze conservate')
                                ->state(fn (HistoricalErrorAnnotation $record): HtmlString|string => self::attachmentLinks($record->attachments))
                                ->html(),
                        ])
                        ->columns(4),
                ]),
        ]);
    }

    /** @param iterable<Attachment> $attachments */
    private static function attachmentLinks(iterable $attachments): HtmlString|string
    {
        $links = collect($attachments)
            ->map(fn (Attachment $attachment): string => '<a class="fi-link" href="'.e(route('attachments.download', $attachment)).'">'.e($attachment->original_name).'</a>')
            ->implode('<br>');

        return $links === '' ? 'Nessuna evidenza' : new HtmlString($links);
    }

    private static function facts(mixed $state): string
    {
        if (! is_array($state) || $state === []) {
            return '—';
        }
        if (array_key_exists('value', $state) && is_scalar($state['value'])) {
            return (string) $state['value'];
        }

        return collect($state)
            ->map(fn (mixed $value, int|string $key): string => (string) $key.': '.(is_scalar($value) ? (string) $value : self::json($value)))
            ->implode(' · ');
    }

    private static function sources(mixed $state): string
    {
        if (! is_array($state) || $state === []) {
            return '—';
        }

        return collect($state)
            ->map(fn (array $source): string => (string) $source['type'].' · '.(string) $source['label'])
            ->implode(' · ');
    }

    private static function projectRow(ClosingSourceRow $record): bool
    {
        $sourceType = $record->getAttribute('source_type');

        return $sourceType instanceof ProposalSourceType && $sourceType === ProposalSourceType::Project;
    }

    private static function warnings(mixed $state): string
    {
        if (! is_array($state) || $state === []) {
            return 'Nessun avviso accettato.';
        }

        return collect($state)
            ->map(fn (mixed $warning): string => is_array($warning) ? '• '.(string) ($warning['message'] ?? $warning['code'] ?? 'Avviso') : '• '.(string) $warning)
            ->implode("\n");
    }

    private static function json(mixed $state): string
    {
        return json_encode($state, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    }
}
