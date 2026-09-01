<?php

namespace App\Filament\Resources\Projects\Pages;

use App\Actions\Operations\ChangeProjectDeferral;
use App\Actions\Operations\SetProjectArchived;
use App\Actions\Operations\UpdateProjectClassification;
use App\Domain\Projects\ProjectDeferralMode;
use App\Domain\Projects\ProjectState;
use App\Filament\Pages\CompanyAudit;
use App\Filament\Resources\Expenses\ExpenseResource;
use App\Filament\Resources\Projects\ProjectResource;
use App\Models\CostCenter;
use App\Models\Exercise;
use App\Models\Project;
use App\Models\ProjectDeferral;
use App\Models\ProjectTransition;
use App\Models\Supplier;
use App\Models\User;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Checkbox;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Support\Enums\Width;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Number;
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
            Action::make('createProjectExpense')
                ->label('Nuova Spesa')
                ->icon('heroicon-m-plus')
                ->extraAttributes(['class' => 'mp2-object-primary-action'])
                ->url(fn (): string => ExpenseResource::getUrl('create', [
                    'project' => $this->projectRecord()->getKey(),
                ]))
                ->visible(fn (): bool => $this->canCreateExpense()),
            Action::make('reclassify')
                ->label('Riclassifica Annualità')
                ->icon('heroicon-m-arrows-right-left')
                ->color('gray')
                ->outlined()
                ->modalHeading('Riclassifica il Progetto')
                ->modalDescription('L’anteprima riclassifica tutte le Spese figlie dell’Esercizio senza modificarne identità o importi.')
                ->modalSubmitActionLabel('Conferma Riclassificazione')
                ->visible(fn (): bool => $this->record instanceof Project && ! $this->record->isArchived() && auth()->user()?->can('update', $this->record) === true)
                ->form([
                    Select::make('exercise_id')
                        ->label('Esercizio Aperto')
                        ->options(fn (): array => Exercise::query()->where('company_id', $this->projectRecord()->company_id)->open()->orderByDesc('year')->pluck('year', 'id')->all())
                        ->live()
                        ->afterStateUpdated(fn (Set $set) => $set('impact_confirmed', false))
                        ->required(),
                    Select::make('cost_center_id')
                        ->label('Nuovo Centro di Costo')
                        ->options(fn (): array => CostCenter::query()->where('company_id', $this->projectRecord()->company_id)->active()->orderBy('name')->pluck('name', 'id')->all())
                        ->live()
                        ->afterStateUpdated(fn (Set $set) => $set('impact_confirmed', false))
                        ->placeholder('Non classificato'),
                    Placeholder::make('impact_preview')
                        ->hiddenLabel()
                        ->content(fn (Get $get): View => $this->reclassificationPreview($get)),
                    Textarea::make('reason')
                        ->label('Nota della Riclassificazione')
                        ->helperText('Richiesta quando la riclassificazione interessa Effettivi o un Budget approvato.')
                        ->visible(fn (Get $get): bool => $this->reclassificationReasonRequired($get))
                        ->required(fn (Get $get): bool => $this->reclassificationReasonRequired($get))
                        ->dehydrated(fn (Get $get): bool => $this->reclassificationReasonRequired($get)),
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
                    $action->confirm(
                        $actor,
                        $this->record,
                        $preview,
                        (string) $data['operation_id'],
                        isset($data['reason']) ? (string) $data['reason'] : null,
                    );
                    $this->record->refresh();
                }),
            Action::make('manage_deferral')
                ->label('Gestisci Rinvio')
                ->icon('heroicon-m-arrow-right-circle')
                ->color('gray')
                ->outlined()
                ->modalHeading('Gestisci Rinvio del Progetto')
                ->modalDescription('Sostituisce o rimuove un rinvio già applicato. I Budget esistenti restano invariati e i Draft interessati saranno da riallineare.')
                ->modalSubmitActionLabel('Conferma Cambio Rinvio')
                ->visible(fn (): bool => $this->canManageDeferral())
                ->form([
                    Select::make('deferral_id')
                        ->label('Passaggio tra Esercizi')
                        ->options(fn (): array => $this->manageableDeferrals()->mapWithKeys(
                            fn (ProjectDeferral $deferral): array => [$deferral->id => $deferral->sourceExercise->year.' → '.$deferral->destinationExercise->year.' · '.$deferral->mode->label()],
                        )->all())
                        ->live()
                        ->required(),
                    Select::make('mode')
                        ->label('Nuova Modalità')
                        ->options(function (Get $get): array {
                            $deferral = ProjectDeferral::query()->find($get('deferral_id'));

                            return match ($deferral?->mode) {
                                ProjectDeferralMode::Carryover => [
                                    ProjectDeferralMode::None->value => ProjectDeferralMode::None->label(),
                                    ProjectDeferralMode::Reprogramming->value => ProjectDeferralMode::Reprogramming->label(),
                                ],
                                ProjectDeferralMode::Reprogramming => [
                                    ProjectDeferralMode::None->value => ProjectDeferralMode::None->label(),
                                    ProjectDeferralMode::Carryover->value => ProjectDeferralMode::Carryover->label(),
                                ],
                                default => [],
                            };
                        })
                        ->live()
                        ->required(),
                    TextInput::make('carryover_amount')
                        ->label('Riporto Provvisorio')
                        ->numeric()
                        ->minValue(0.01)
                        ->prefix('€')
                        ->visible(fn (Get $get): bool => $get('mode') === ProjectDeferralMode::Carryover->value)
                        ->required(fn (Get $get): bool => $get('mode') === ProjectDeferralMode::Carryover->value),
                    Repeater::make('source_estimate_reductions')
                        ->label('Stime Origine da Ridurre')
                        ->schema([
                            Select::make('source_line_id')
                                ->label('Riga Stima')
                                ->options(fn (Get $get): array => $this->reprogrammableLineOptions((int) $get('../../deferral_id')))
                                ->required(),
                            TextInput::make('reduction_amount')->label('Riduzione')->numeric()->minValue(0.01)->prefix('€')->required(),
                            Select::make('destination_supplier_id')
                                ->label('Fornitore Destinazione')
                                ->options(fn (): array => ['none' => 'Nessun Fornitore'] + Supplier::query()->where('company_id', $this->projectRecord()->company_id)->active()->orderBy('legal_name')->pluck('legal_name', 'id')->all())
                                ->required(),
                        ])
                        ->columns(3)
                        ->minItems(1)
                        ->visible(fn (Get $get): bool => $get('mode') === ProjectDeferralMode::Reprogramming->value)
                        ->required(fn (Get $get): bool => $get('mode') === ProjectDeferralMode::Reprogramming->value),
                    Textarea::make('reason')->label('Motivazione')->required()->maxLength(2000),
                    Placeholder::make('deferral_preview')
                        ->label('Anteprima Esatta')
                        ->content(fn (Get $get): string => $this->deferralPreviewText($get)),
                    Checkbox::make('impact_confirmed')
                        ->label('Confermo l’impatto corrente, il riallineamento dei Draft e l’immutabilità dei Budget esistenti')
                        ->accepted()
                        ->required(),
                    Hidden::make('operation_id')->default(fn (): string => (string) Str::uuid()),
                ])
                ->action(function (array $data): void {
                    $actor = auth()->user();
                    abort_unless($actor instanceof User, 403);
                    $deferral = ProjectDeferral::query()
                        ->where('project_id', $this->projectRecord()->id)
                        ->with(['sourceExercise', 'destinationExercise'])
                        ->findOrFail((int) $data['deferral_id']);
                    $input = $this->deferralInput($data);
                    $action = app(ChangeProjectDeferral::class);
                    $preview = $action->preview($actor, $this->projectRecord()->refresh(), $deferral->sourceExercise, $deferral->destinationExercise, $input);
                    $action->execute(
                        $actor,
                        $this->projectRecord()->refresh(),
                        $deferral->sourceExercise->refresh(),
                        $deferral->destinationExercise->refresh(),
                        $input,
                        (string) $data['reason'],
                        (string) $data['operation_id'],
                        $preview['project_revision'],
                        $preview['fingerprint'],
                    );
                    $this->record->refresh();
                    Notification::make()->title('Rinvio del Progetto Modificato')->success()->send();
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
            ? 'Centro di Costo non disponibile'
            : $costCenter->name.($costCenter->isArchived() ? ' · Archiviato' : '');
    }

    private function reclassificationPreview(Get $get): View
    {
        $actor = auth()->user();
        $exerciseId = $get('exercise_id');
        if (! $actor instanceof User || blank($exerciseId)) {
            return view('filament.resources.projects.components.classification-impact-preview', [
                'error' => 'Selezionare l’Esercizio per calcolare l’anteprima.',
                'summary' => null,
            ]);
        }

        try {
            $exercise = Exercise::query()->findOrFail((int) $exerciseId);
            $costCenterId = filled($get('cost_center_id')) ? (int) $get('cost_center_id') : null;
            $plan = app(UpdateProjectClassification::class)->preview($actor, $this->projectRecord(), $exercise, $costCenterId);
        } catch (ValidationException $exception) {
            return view('filament.resources.projects.components.classification-impact-preview', [
                'error' => collect($exception->errors())->flatten()->first() ?? 'Anteprima non disponibile.',
                'summary' => null,
            ]);
        }

        return view('filament.resources.projects.components.classification-impact-preview', [
            'error' => null,
            'summary' => [
                'before' => $this->classificationLabel($plan->oldCostCenterId),
                'after' => $this->classificationLabel($plan->newCostCenterId),
                'changed' => $plan->oldCostCenterId !== $plan->newCostCenterId,
                'expense_count' => count($plan->expenseIds),
                'allocation' => Number::currency((float) $plan->allocation, in: 'EUR', locale: 'it'),
                'actual' => Number::currency((float) $plan->actual, in: 'EUR', locale: 'it'),
            ],
        ]);
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

    private function reclassificationReasonRequired(Get $get): bool
    {
        $exerciseId = filter_var($get('exercise_id'), FILTER_VALIDATE_INT);
        if (! is_int($exerciseId)) {
            return false;
        }

        $exercise = Exercise::query()
            ->where('company_id', $this->projectRecord()->company_id)
            ->open()
            ->find($exerciseId);
        if (! $exercise instanceof Exercise) {
            return false;
        }

        return $exercise->hasApprovedBudget()
            || $this->projectRecord()->expenses()
                ->whereNull('reversed_at')
                ->where('exercise_id', $exercise->id)
                ->whereHas('lines', fn ($query) => $query
                    ->whereNull('annulled_at')
                    ->where('type', 'actual')
                    ->where('amount', '!=', '0.00'))
                ->exists();
    }

    private function canCreateExpense(): bool
    {
        $project = $this->projectRecord();
        $actor = auth()->user();

        return $actor instanceof User
            && ! $project->isArchived()
            && $actor->can('update', $project);
    }

    private function canManageDeferral(): bool
    {
        $actor = auth()->user();

        return $actor instanceof User
            && $actor->can('update', $this->projectRecord())
            && $this->manageableDeferrals()->isNotEmpty();
    }

    /** @return Collection<int, ProjectDeferral> */
    private function manageableDeferrals(): Collection
    {
        return $this->projectRecord()->deferrals()
            ->with(['sourceExercise', 'destinationExercise'])
            ->whereIn('mode', [ProjectDeferralMode::Carryover->value, ProjectDeferralMode::Reprogramming->value])
            ->get()
            ->filter(fn (ProjectDeferral $deferral): bool => $deferral->sourceExercise->isOpen() && $deferral->destinationExercise->isOpen());
    }

    /** @return array<int, string> */
    private function reprogrammableLineOptions(int $deferralId): array
    {
        $deferral = ProjectDeferral::query()->where('project_id', $this->projectRecord()->id)->find($deferralId);
        if ($deferral === null) {
            return [];
        }

        return $this->projectRecord()->expenses()
            ->where('exercise_id', $deferral->source_exercise_id)
            ->whereNull('reversed_at')
            ->with(['lines' => fn ($query) => $query->where('type', 'estimate')->whereNull('annulled_at')->orderBy('id')])
            ->orderBy('id')
            ->get()
            ->flatMap(fn ($expense) => $expense->lines->mapWithKeys(
                fn ($line): array => [$line->id => $expense->originKey().' · '.$expense->description.' · € '.$line->amount],
            ))
            ->all();
    }

    private function deferralPreviewText(Get $get): string
    {
        $actor = auth()->user();
        $deferral = ProjectDeferral::query()->where('project_id', $this->projectRecord()->id)->with(['sourceExercise', 'destinationExercise'])->find($get('deferral_id'));
        if (! $actor instanceof User || $deferral === null || blank($get('mode'))) {
            return 'Selezionare passaggio e nuova modalità per calcolare l’anteprima.';
        }
        try {
            $preview = app(ChangeProjectDeferral::class)->preview(
                $actor,
                $this->projectRecord()->refresh(),
                $deferral->sourceExercise,
                $deferral->destinationExercise,
                $this->deferralInput([
                    'mode' => $get('mode'),
                    'carryover_amount' => $get('carryover_amount'),
                    'source_estimate_reductions' => $get('source_estimate_reductions'),
                ]),
            );
        } catch (ValidationException $exception) {
            return (string) (collect($exception->errors())->flatten()->first() ?? 'Anteprima non disponibile.');
        }

        return 'Allocato origine disponibile: € '.$preview['source_allocation']
            .' · Effettivo origine: € '.$preview['source_actual']
            .' · Massimo riportabile: € '.$preview['maximum_transferable']
            .' · Righe origine ripristinate: '.$preview['source_lines_restored']
            .' · Righe destinazione annullate: '.$preview['destination_lines_annulled']
            .'. Le allocazioni indipendenti non verranno modificate.';
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function deferralInput(array $data): array
    {
        return [
            'mode' => $data['mode'] ?? null,
            'carryover_amount' => $data['carryover_amount'] ?? null,
            'source_estimate_reductions' => collect((array) ($data['source_estimate_reductions'] ?? []))->map(function (mixed $reduction): array {
                $row = is_array($reduction) ? $reduction : [];
                if (($row['destination_supplier_id'] ?? null) === 'none') {
                    $row['destination_supplier_id'] = null;
                }

                return $row;
            })->values()->all(),
        ];
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
            ->title($archived ? 'Progetto Archiviato' : 'Progetto Ripristinato')
            ->success()
            ->send();
    }
}
