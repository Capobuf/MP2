<?php

namespace App\Filament\Resources\Projects\Schemas;

use App\Domain\Projects\ProjectAnnualSituation;
use App\Domain\Projects\ProjectState;
use App\Models\Project;
use App\Models\ProjectTransition;
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
                    ->state(fn (Project $record): array => self::annualRows($record))
                    ->schema([
                        TextEntry::make('year')->label('Esercizio'),
                        TextEntry::make('reference_date')->label('Data di riferimento')->date('d/m/Y'),
                        TextEntry::make('reference_rule')->label('Regola di riferimento')->wrap(),
                        TextEntry::make('state')->label('Stato')->badge(),
                        TextEntry::make('cost_center')->label('Centro di Costo')->placeholder('Non classificato'),
                        TextEntry::make('allocation')->label('Allocato')->money('EUR', locale: 'it'),
                        TextEntry::make('actual')->label('Effettivo')->money('EUR', locale: 'it'),
                        TextEntry::make('variance')->label('Scostamento')->money('EUR', locale: 'it'),
                        TextEntry::make('future_transitions')->label('Transizioni pianificate dopo il 1° gennaio')->wrap(),
                    ])->columns(3)
                    ->columnSpanFull(),
            ]),
        ]);
    }

    private static function currentState(Project $project): string
    {
        $today = CarbonImmutable::now($project->company->timezone)->toDateString();

        return $project->stateAtDate($today)?->label() ?? 'Assente alla data';
    }

    /** @return list<array<string, int|string|null>> */
    private static function annualRows(Project $project): array
    {
        $today = CarbonImmutable::now($project->company->timezone)->startOfDay();

        return array_map(function (ProjectAnnualSituation $situation) use ($project, $today): array {
            $row = $situation->toArray();
            $row['reference_rule'] = match (true) {
                $situation->year < $today->year => '31 dicembre dell’Esercizio passato',
                $situation->year === $today->year => 'Data odierna aziendale',
                default => '1° gennaio dell’Esercizio futuro',
            };
            $row['future_transitions'] = $situation->year <= $today->year
                ? 'Non applicabile'
                : ($project->transitions
                    ->filter(function (ProjectTransition $transition) use ($situation): bool {
                        $date = $transition->effectiveDate();

                        return $transition->annulledAt() === null
                            && $date->year === $situation->year
                            && $date->greaterThan(CarbonImmutable::create($situation->year, 1, 1));
                    })
                    ->map(fn (ProjectTransition $transition): string => $transition->effectiveDate()->format('d/m/Y')
                        .': '.self::stateLabel($transition->from_state).' → '.self::stateLabel($transition->to_state))
                    ->implode(' · ') ?: 'Nessuna');

            return $row;
        }, ProjectAnnualSituation::build($project, $project->company->exercises, $today));
    }

    private static function stateLabel(mixed $state): string
    {
        return ($state instanceof ProjectState ? $state : ProjectState::from((string) $state))->label();
    }
}
