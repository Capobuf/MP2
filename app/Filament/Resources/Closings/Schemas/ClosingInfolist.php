<?php

namespace App\Filament\Resources\Closings\Schemas;

use App\Models\ClosingSourceRow;
use Filament\Infolists\Components\RepeatableEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

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
                    TextEntry::make('initialBudget.version')->label('Budget v1')->formatStateUsing(fn (mixed $state): string => $state === null ? 'Assente' : 'v'.$state),
                    TextEntry::make('currentBudget.version')->label('Budget corrente')->formatStateUsing(fn (mixed $state): string => $state === null ? 'Assente' : 'v'.$state),
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
                    TextEntry::make('accepted_warnings')->label('Avvisi accettati')->formatStateUsing(self::warnings(...))->columnSpanFull()->wrap(),
                    TextEntry::make('applied_settings')->label('Impostazioni applicate')->formatStateUsing(self::json(...))->columnSpanFull()->wrap(),
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
                    TextEntry::make('detail')->label('Dettaglio materializzato')->formatStateUsing(self::json(...))->columnSpanFull()->wrap(),
                ])->columns(4),
            ]),
        ]);
    }

    private static function projectRow(ClosingSourceRow $record): bool
    {
        return $record->source_type->value === 'project';
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
        return json_encode($state, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    }
}
