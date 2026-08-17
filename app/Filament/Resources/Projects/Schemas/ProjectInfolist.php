<?php

namespace App\Filament\Resources\Projects\Schemas;

use App\Domain\Projects\ProjectAnnualSituation;
use App\Models\Project;
use Carbon\CarbonImmutable;
use Filament\Infolists\Components\RepeatableEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class ProjectInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Identità e stato')->schema([
                TextEntry::make('origin_key')->label('OriginKey')->state(fn (Project $record): string => $record->originKey()),
                TextEntry::make('title')->label('Titolo'),
                TextEntry::make('description')->label('Descrizione')->placeholder('—'),
                TextEntry::make('notes')->label('Note')->placeholder('—'),
                TextEntry::make('initial_state')->label('Stato iniziale')->formatStateUsing(fn ($state): string => $state->label())->badge(),
                TextEntry::make('initial_effective_date')->label('Data efficacia iniziale')->date('d/m/Y'),
                TextEntry::make('current_state')->label('Stato attuale')->state(fn (Project $record): string => self::currentState($record))->badge(),
                TextEntry::make('archive_state')->label('Visibilità')->state(fn (Project $record): string => $record->isArchived() ? 'Archiviato' : 'Attivo')->badge(),
            ])->columns(3),
            Section::make('Situazioni annuali')->schema([
                RepeatableEntry::make('annual_situations')
                    ->label('Situazioni annuali')
                    ->state(fn (Project $record): array => array_map(
                        fn (ProjectAnnualSituation $situation): array => $situation->toArray(),
                        ProjectAnnualSituation::build(
                            $record,
                            $record->company->exercises,
                            CarbonImmutable::now($record->company->timezone),
                        ),
                    ))
                    ->schema([
                        TextEntry::make('year')->label('Esercizio'),
                        TextEntry::make('reference_date')->label('Data di riferimento')->date('d/m/Y'),
                        TextEntry::make('state')->label('Stato')->badge(),
                        TextEntry::make('cost_center')->label('Centro di Costo')->placeholder('Non classificato'),
                        TextEntry::make('allocation')->label('Allocato')->money('EUR', locale: 'it'),
                        TextEntry::make('actual')->label('Effettivo')->money('EUR', locale: 'it'),
                        TextEntry::make('variance')->label('Scostamento')->money('EUR', locale: 'it'),
                    ])->columns(4)
                    ->columnSpanFull(),
            ]),
        ]);
    }

    private static function currentState(Project $project): string
    {
        $today = CarbonImmutable::now($project->company->timezone)->toDateString();

        return $project->stateAtDate($today)?->label() ?? 'Assente alla data';
    }
}
