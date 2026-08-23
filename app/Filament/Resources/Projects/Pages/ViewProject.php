<?php

namespace App\Filament\Resources\Projects\Pages;

use App\Actions\Operations\SetProjectArchived;
use App\Actions\Operations\UpdateProjectClassification;
use App\Domain\Projects\ProjectState;
use App\Filament\Pages\CompanyAudit;
use App\Filament\Resources\Projects\ProjectResource;
use App\Models\CostCenter;
use App\Models\Exercise;
use App\Models\Project;
use App\Models\ProjectTransition;
use App\Models\User;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Checkbox;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Select;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Support\Enums\Width;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class ViewProject extends ViewRecord
{
    protected static string $resource = ProjectResource::class;

    public function getHeader(): ?View
    {
        $project = $this->projectRecord();
        $today = now($project->company->timezone)->toImmutable()->startOfDay();
        $nextTransition = $project->transitions
            ->filter(fn (ProjectTransition $transition): bool => $transition->annulledAt() === null
                && $transition->effectiveDate()->startOfDay()->greaterThan($today))
            ->sortBy(fn (ProjectTransition $transition): string => $transition->effectiveDate()->toDateString())
            ->first();

        return view('filament.resources.projects.components.object-header', [
            'project' => $project,
            'currentState' => $project->stateAtDate($today->toDateString()),
            'nextTransition' => $nextTransition,
            'today' => $today,
            'projectsUrl' => ProjectResource::getUrl('index', tenant: $project->company),
        ]);
    }

    public function getMaxContentWidth(): Width
    {
        return Width::Full;
    }

    /** @return array<string, string> */
    public function getExtraBodyAttributes(): array
    {
        return ['class' => 'mp2-object-page mp2-project-object-page'];
    }

    public function hasCombinedRelationManagerTabsWithContent(): bool
    {
        return true;
    }

    public function getContentTabLabel(): ?string
    {
        return 'Panoramica';
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('timeline')
                ->label('Timeline del Progetto')
                ->icon('heroicon-m-chart-bar')
                ->color('gray')
                ->outlined()
                ->url(fn (Project $record): string => CompanyAudit::getUrl([
                    'tenant' => $record->company,
                    'project' => $record->id,
                ])),
            EditAction::make()->label('Modifica')->icon('heroicon-m-pencil-square')->color('gray')->outlined(),
            Action::make('reclassify')
                ->label('Riclassifica annualità')
                ->icon('heroicon-m-arrows-right-left')
                ->extraAttributes(['class' => 'mp2-object-primary-action'])
                ->modalHeading('Riclassifica il Progetto')
                ->modalDescription('L’anteprima riclassifica tutte le Spese figlie dell’Esercizio senza modificarne identità o importi.')
                ->modalSubmitActionLabel('Conferma riclassificazione')
                ->visible(fn (): bool => $this->record instanceof Project && ! $this->record->isArchived() && auth()->user()?->can('update', $this->record) === true)
                ->form([
                    Select::make('exercise_id')
                        ->label('Esercizio Aperto')
                        ->options(fn (): array => Exercise::query()->where('company_id', $this->projectRecord()->company_id)->open()->orderByDesc('year')->pluck('year', 'id')->all())
                        ->live()
                        ->required(),
                    Select::make('cost_center_id')
                        ->label('Nuovo Centro di Costo')
                        ->options(fn (): array => CostCenter::query()->where('company_id', $this->projectRecord()->company_id)->active()->orderBy('name')->pluck('name', 'id')->all())
                        ->live()
                        ->placeholder('Non classificato'),
                    Placeholder::make('impact_preview')
                        ->label('Anteprima esatta')
                        ->content(function (Get $get): string {
                            $actor = auth()->user();
                            $exerciseId = $get('exercise_id');
                            if (! $actor instanceof User || blank($exerciseId)) {
                                return 'Selezionare l’Esercizio per calcolare l’anteprima.';
                            }
                            try {
                                $exercise = Exercise::query()->findOrFail((int) $exerciseId);
                                $costCenterId = filled($get('cost_center_id')) ? (int) $get('cost_center_id') : null;
                                $plan = app(UpdateProjectClassification::class)->preview($actor, $this->projectRecord(), $exercise, $costCenterId);
                            } catch (ValidationException $exception) {
                                return collect($exception->errors())->flatten()->first() ?? 'Anteprima non disponibile.';
                            }

                            $previous = $this->classificationLabel($plan->oldCostCenterId);
                            $next = $this->classificationLabel($plan->newCostCenterId);
                            $expenses = $this->projectRecord()->expenses()
                                ->whereIn('id', $plan->expenseIds)
                                ->orderBy('id')
                                ->get()
                                ->map(fn ($expense): string => $expense->originKey())
                                ->implode(', ');

                            return "Centro di Costo: {$previous} → {$next}. "
                                .count($plan->expenseIds).' Spese conservano identità e importi'
                                .($expenses === '' ? '' : " ({$expenses})")
                                .'; € '.$plan->allocation.' di Allocato ed € '.$plan->actual.' di Effettivo passano integralmente alla nuova classificazione annuale.';
                        }),
                    Checkbox::make('impact_confirmed')->label('Confermo l’anteprima corrente')->accepted()->required(),
                    Hidden::make('operation_id')->default(fn (): string => (string) Str::uuid()),
                ])
                ->action(function (array $data): void {
                    $actor = auth()->user();
                    abort_unless($actor instanceof User && $this->record instanceof Project, 403);
                    $exercise = Exercise::query()->findOrFail((int) $data['exercise_id']);
                    $costCenterId = filled($data['cost_center_id'] ?? null) ? (int) $data['cost_center_id'] : null;
                    $action = app(UpdateProjectClassification::class);
                    $preview = $action->preview($actor, $this->record, $exercise, $costCenterId);
                    $action->confirm($actor, $this->record, $preview, (string) $data['operation_id']);
                    $this->record->refresh();
                }),
            Action::make('archive')
                ->label('Archivia')
                ->color('warning')
                ->requiresConfirmation()
                ->modalHeading('Archivia il Progetto')
                ->modalDescription('Il Progetto resterà consultabile con valori, classificazioni e Timeline invariati.')
                ->modalSubmitActionLabel('Archivia Progetto')
                ->visible(fn (): bool => $this->canArchive())
                ->form([
                    Hidden::make('project_revision')->default(fn (): int => $this->projectRecord()->revision),
                    Hidden::make('operation_id')->default(fn (): string => (string) Str::uuid()),
                ])
                ->action(fn (array $data) => $this->setArchived(true, $data)),
            Action::make('restore')
                ->label('Ripristina')
                ->color('success')
                ->requiresConfirmation()
                ->modalHeading('Ripristina il Progetto')
                ->modalDescription('Il Progetto tornerà disponibile per le attività consentite dal suo stato corrente.')
                ->modalSubmitActionLabel('Ripristina Progetto')
                ->visible(fn (): bool => $this->canRestore())
                ->form([
                    Hidden::make('project_revision')->default(fn (): int => $this->projectRecord()->revision),
                    Hidden::make('operation_id')->default(fn (): string => (string) Str::uuid()),
                ])
                ->action(fn (array $data) => $this->setArchived(false, $data)),
        ];
    }

    private function projectRecord(): Project
    {
        $record = $this->getRecord();
        if (! $record instanceof Project) {
            throw new \UnexpectedValueException('Invalid Project record.');
        }

        return $record;
    }

    private function classificationLabel(?int $costCenterId): string
    {
        if ($costCenterId === null) {
            return 'Non classificato';
        }

        $costCenter = CostCenter::query()->find($costCenterId);

        return $costCenter === null
            ? 'Centro di Costo #'.$costCenterId
            : $costCenter->name.($costCenter->isArchived() ? ' · Archiviato' : '');
    }

    private function canArchive(): bool
    {
        $project = $this->projectRecord();
        $actor = auth()->user();
        $today = now($project->company->timezone)->toDateString();

        return $actor instanceof User
            && $actor->can('update', $project)
            && ! $project->isArchived()
            && in_array($project->stateAtDate($today), [ProjectState::Closed, ProjectState::Cancelled], true);
    }

    private function canRestore(): bool
    {
        $project = $this->projectRecord();
        $actor = auth()->user();

        return $actor instanceof User && $actor->can('update', $project) && $project->isArchived();
    }

    /** @param array<string, mixed> $data */
    private function setArchived(bool $archived, array $data): void
    {
        $actor = auth()->user();
        abort_unless($actor instanceof User, 403);
        $project = app(SetProjectArchived::class)->execute(
            $actor,
            $this->projectRecord(),
            $archived,
            (string) $data['operation_id'],
            (int) $data['project_revision'],
        );
        $this->record = $project->refresh();

        Notification::make()
            ->title($archived ? 'Progetto archiviato' : 'Progetto ripristinato')
            ->success()
            ->send();
    }
}
