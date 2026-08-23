<?php

namespace App\Filament\Resources\Budgets\Schemas;

use App\Domain\Projects\ProjectDeferralMode;
use App\Models\BudgetEvidence;
use App\Models\BudgetSourceRow;
use Filament\Infolists\Components\RepeatableEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class BudgetInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Budget immutabile')->schema([TextEntry::make('exercise.year')->label('Esercizio'), TextEntry::make('version')->label('Versione')->formatStateUsing(fn ($state): string => 'v'.$state), TextEntry::make('previousBudget.version')->label('Versione precedente')->formatStateUsing(fn (mixed $state): string => $state === null ? '—' : 'v'.$state), TextEntry::make('purpose')->label('Finalità')->formatStateUsing(fn ($state): string => $state->label()), TextEntry::make('approved_at')->label('Approvato il')->dateTime('d/m/Y H:i'), TextEntry::make('approver.name')->label('Approvato da'), TextEntry::make('total_approved_allocation')->label('Allocato approvato')->money('EUR', locale: 'it')])->columns(3),
            Section::make('Sorgenti materializzate')->schema([RepeatableEntry::make('rows')->label('')->schema([TextEntry::make('source_type')->label('Tipo')->formatStateUsing(fn ($state): string => $state->label()), TextEntry::make('origin_key')->label('OriginKey'), TextEntry::make('label')->label('Etichetta'), TextEntry::make('supplier_label')->label('Fornitore')->placeholder('—'), TextEntry::make('cost_center_label')->label('Centro di Costo'), TextEntry::make('approved_estimates')->label('Stime approvate')->money('EUR', locale: 'it')->visible(self::projectRow(...)), TextEntry::make('approved_carryover')->label('Riporto approvato')->money('EUR', locale: 'it')->visible(self::projectRow(...)), TextEntry::make('carryover_state')->label('Stato Riporto')->formatStateUsing(fn (mixed $state): string => $state === 'provisional' ? 'Provvisorio' : ($state === 'consolidated' ? 'Consolidato' : '—'))->visible(self::projectRow(...)), TextEntry::make('approved_allocation')->label('Allocato approvato')->money('EUR', locale: 'it'), TextEntry::make('detail.project.deferral_mode')->label('Modalità rinvio')->formatStateUsing(fn (mixed $state): string => ProjectDeferralMode::tryFrom((string) $state)?->label() ?? 'Nessuna')->visible(self::projectRow(...)), TextEntry::make('detail.project.approved_reprogrammed_amount')->label('Riprogrammato approvato')->money('EUR', locale: 'it')->visible(self::projectRow(...)), TextEntry::make('start_state')->label('Stato iniziale'), TextEntry::make('end_state')->label('Stato finale'), TextEntry::make('detail.expense')->label('Dettaglio Spesa')->formatStateUsing(self::json(...))->visible(fn (BudgetSourceRow $record): bool => $record->source_type->value === 'expense')->columnSpanFull()->wrap(), TextEntry::make('detail.project')->label('Dettaglio tecnico Progetto')->formatStateUsing(self::json(...))->visible(self::projectRow(...))->columnSpanFull()->wrap(), TextEntry::make('detail.contract')->label('Dettaglio Contratto')->formatStateUsing(self::json(...))->visible(fn (BudgetSourceRow $record): bool => $record->source_type->value === 'contract')->columnSpanFull()->wrap(), TextEntry::make('detail.approved_actions')->label('Azioni e motivazioni approvate')->formatStateUsing(self::json(...))->columnSpanFull()->wrap(), TextEntry::make('detail.relations')->label('Relazioni informative')->formatStateUsing(self::json(...))->columnSpanFull()->wrap(), TextEntry::make('detail.approval_event_sequences')->label('Riferimenti eventi di approvazione')->formatStateUsing(self::json(...))->columnSpanFull()->wrap()])->columns(4)]),
            Section::make('Evidenza di approvazione')->schema([RepeatableEntry::make('evidence')->label('')->schema([TextEntry::make('external_subject')->label('Soggetto esterno')->placeholder('—'), TextEntry::make('external_venue')->label('Sede/verbale')->placeholder('—'), TextEntry::make('reason')->label('Nota')->placeholder('—'), TextEntry::make('original_name')->label('Allegato')->placeholder('—'), TextEntry::make('sha256')->label('SHA-256')->placeholder('—')->copyable(), TextEntry::make('download')->label('Download')->state(fn (BudgetEvidence $record): ?string => $record->storage_path === null ? null : 'Scarica evidenza')->url(fn (BudgetEvidence $record): ?string => $record->storage_path === null ? null : route('budget-evidence.download', $record))])->columns(3)]),
        ]);
    }

    private static function json(mixed $state): string
    {
        return json_encode($state, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    }

    private static function projectRow(BudgetSourceRow $record): bool
    {
        return $record->source_type->value === 'project';
    }
}
