<?php

namespace App\Filament\Resources\Projects\Schemas;

use App\Domain\Projects\ProjectAnnualSituation;
use App\Domain\Projects\ProjectState;
use App\Models\Project;
use App\Models\ProjectTransition;
use App\Support\ExerciseContext;
use Carbon\CarbonImmutable;
use Filament\Schemas\Components\View;
use Filament\Schemas\Schema;
use Illuminate\Support\Number;

class ProjectInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            View::make('filament.resources.projects.components.overview')
                ->viewData(fn (Project $record): array => ['overview' => self::overview($record)])
                ->columnSpanFull(),
        ]);
    }

    /** @return array<string, mixed> */
    private static function overview(Project $project): array
    {
        $today = CarbonImmutable::now($project->company->timezone)->toDateString();
        $reference = CarbonImmutable::parse($today)->startOfDay();
        $selectedExercise = app(ExerciseContext::class)->current($project->company);
        $annualRows = self::annualRows($project, $reference, $selectedExercise?->id);

        return [
            'profile' => [
                'origin_key' => $project->originKey(),
                'description' => filled($project->description) ? $project->description : '—',
                'notes' => filled($project->notes) ? $project->notes : '—',
                'initial_state' => $project->initialState()->label(),
                'initial_effective_date' => $project->initialEffectiveDate()->format('d/m/Y'),
                'current_state' => $project->stateAtDate($today)?->label() ?? 'Assente alla data',
                'visibility' => $project->isArchived() ? 'Archiviato' : 'Attivo',
            ],
            'selected' => collect($annualRows)->firstWhere('selected', true),
            'annual' => $annualRows,
        ];
    }

    /** @return list<array<string, int|string|bool|null>> */
    private static function annualRows(Project $project, CarbonImmutable $today, ?int $selectedExerciseId): array
    {
        return array_map(function (ProjectAnnualSituation $situation) use ($project, $today, $selectedExerciseId): array {
            $row = $situation->toArray();
            $row['selected'] = $situation->exerciseId === $selectedExerciseId;
            $row['reference_date'] = CarbonImmutable::parse($situation->referenceDate)->format('d/m/Y');
            $row['reference_rule'] = match (true) {
                $situation->year < $today->year => '31 dicembre dell’Esercizio passato',
                $situation->year === $today->year => 'Data odierna aziendale',
                default => '1° gennaio dell’Esercizio futuro',
            };
            $row['cost_center'] ??= 'Non classificato';
            $row['allocation'] = self::money($situation->allocation);
            $row['actual'] = self::money($situation->actual);
            $row['variance'] = self::money($situation->variance);
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

    private static function money(string $amount): string
    {
        return Number::currency((float) $amount, in: 'EUR', locale: 'it');
    }
}
