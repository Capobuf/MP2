<?php

namespace App\Filament\Resources\Exercises\Schemas;

use App\Models\Exercise;
use App\Models\HistoricalErrorAnnotation;
use App\Models\LateCorrection;
use Filament\Infolists\Components\RepeatableEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Illuminate\Support\HtmlString;

class ExerciseInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Riepilogo')->schema([
                TextEntry::make('year')->label('Anno'),
                TextEntry::make('status')->label('Stato')->formatStateUsing(fn ($state): string => $state->label())->badge(),
                TextEntry::make('allocation')->label('Allocato corrente')->state(fn (Exercise $record): string => $record->allocation())->money('EUR', locale: 'it'),
                TextEntry::make('actual')->label('Effettivo')->state(fn (Exercise $record): string => $record->actual())->money('EUR', locale: 'it'),
                TextEntry::make('variance')->label('Scostamento operativo')->state(fn (Exercise $record): string => $record->operationalVariance())->money('EUR', locale: 'it'),
                TextEntry::make('expenses_count')->label('Numero Spese')->state(fn (Exercise $record): int => $record->expenses()->count()),
            ])->columns(3),

            Section::make('Correzioni tardive')
                ->description('Ogni correzione aggiunge un Effettivo append-only. La Snapshot di Chiusura resta distinta e invariata.')
                ->schema([
                    RepeatableEntry::make('lateCorrections')
                        ->label('')
                        ->placeholder('Nessuna correzione tardiva.')
                        ->schema([
                            TextEntry::make('expense.id')->label('Spesa generata')->prefix('#'),
                            TextEntry::make('expense.description')->label('Descrizione Spesa generata')->placeholder('—')->wrap(),
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
                            TextEntry::make('expenseLine.attachments')
                                ->label('Evidenze conservate')
                                ->state(function (LateCorrection $record): HtmlString|string {
                                    $links = $record->attachments
                                        ->map(fn ($attachment): string => '<a class="fi-link" href="'.e(route('attachments.download', $attachment)).'">'.e($attachment->original_name).'</a>')
                                        ->implode('<br>');

                                    return $links === '' ? 'Nessuna evidenza' : new HtmlString($links);
                                })
                                ->html()
                                ->placeholder('Nessuna evidenza'),
                        ])
                        ->columns(4),
                ]),
            Section::make('Annotazioni di errore storico')
                ->description('Le Annotazioni sono evidenze immutabili e separate dagli importi: Nessun impatto economico.')
                ->schema([
                    RepeatableEntry::make('historicalErrorAnnotations')
                        ->label('')
                        ->placeholder('Nessuna annotazione storica.')
                        ->schema([
                            TextEntry::make('kind')
                                ->label('Tipo di errore')
                                ->formatStateUsing(fn ($state): string => $state->label()),
                            TextEntry::make('closingSnapshot.id')->label('Snapshot di Chiusura')->prefix('#'),
                            TextEntry::make('recorded_facts')->label('Dato registrato')->state(fn (HistoricalErrorAnnotation $record): string => self::facts($record->recorded_facts))->columnSpanFull()->wrap(),
                            TextEntry::make('believed_correct_facts')->label('Dato ritenuto corretto')->state(fn (HistoricalErrorAnnotation $record): string => self::facts($record->believed_correct_facts))->columnSpanFull()->wrap(),
                            TextEntry::make('affected_sources')->label('Sorgenti interessate')->state(fn (HistoricalErrorAnnotation $record): string => self::sources($record->affected_sources))->columnSpanFull()->wrap(),
                            TextEntry::make('reason')->label('Motivo')->columnSpanFull()->wrap(),
                            TextEntry::make('economic_impact')->label('Impatto economico')->state(fn (): string => 'Nessun impatto economico'),
                            TextEntry::make('recordedBy.name')->label('Autore'),
                            TextEntry::make('created_at')->label('Registrata il')->state(fn (HistoricalErrorAnnotation $record): string => $record->created_at->copy()->timezone($record->company->timezone)->format('d/m/Y H:i')),
                            TextEntry::make('attachments')
                                ->label('Evidenze conservate')
                                ->state(function (HistoricalErrorAnnotation $record): HtmlString|string {
                                    $links = $record->attachments
                                        ->map(fn ($attachment): string => '<a class="fi-link" href="'.e(route('attachments.download', $attachment)).'">'.e($attachment->original_name).'</a>')
                                        ->implode('<br>');

                                    return $links === '' ? 'Nessuna evidenza' : new HtmlString($links);
                                })
                                ->html()
                                ->placeholder('Nessuna evidenza'),
                        ])
                        ->columns(4),
                ]),
        ]);
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
            ->map(fn (mixed $value, int|string $key): string => (string) $key.': '.self::factValue($value))
            ->implode(' · ');
    }

    private static function sources(mixed $state): string
    {
        if (! is_array($state) || $state === []) {
            return '—';
        }
        $labels = [
            'expense' => 'Spesa',
            'project' => 'Progetto',
            'contract' => 'Contratto',
            'supplier' => 'Fornitore',
            'cost_center' => 'Centro di Costo',
            'exercise' => 'Esercizio',
            'closing_snapshot' => 'Snapshot di Chiusura',
        ];

        return collect($state)
            ->filter(fn (mixed $source): bool => is_array($source) && is_string($source['label'] ?? null))
            ->map(fn (array $source): string => ($labels[$source['type'] ?? ''] ?? 'Sorgente').': '.$source['label'])
            ->implode(' · ');
    }

    private static function factValue(mixed $value): string
    {
        if (is_scalar($value) || $value === null) {
            return (string) ($value ?? '—');
        }

        return is_array($value) ? self::facts($value) : (string) $value;
    }

    private static function json(mixed $state): string
    {
        if ($state === null) {
            return '—';
        }

        return json_encode($state, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    }
}
