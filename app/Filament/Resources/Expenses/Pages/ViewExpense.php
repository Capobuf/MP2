<?php

namespace App\Filament\Resources\Expenses\Pages;

use App\Actions\MasterData\CreateSupplier;
use App\Actions\Operations\UpdateExpense;
use App\Domain\Company\Capability;
use App\Domain\Contracts\ContractActualKind;
use App\Domain\Expenses\ExpenseImpactPlan;
use App\Domain\Projects\ProjectActualKind;
use App\Domain\Projects\ProjectOverspend;
use App\Domain\Projects\ProjectOverspendResult;
use App\Domain\Projects\ProjectState;
use App\Filament\Resources\Contracts\ContractResource;
use App\Filament\Resources\Expenses\ExpenseResource;
use App\Filament\Resources\Projects\ProjectResource;
use App\Filament\Support\ProjectOverspendNotifier;
use App\Livewire\ExpenseDetail;
use App\Models\Contract;
use App\Models\CostCenter;
use App\Models\Exercise;
use App\Models\Expense;
use App\Models\Project;
use App\Models\Supplier;
use App\Models\User;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Checkbox;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Pages\ViewRecord;
use Filament\Schemas\Components\Livewire;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Filament\Support\Enums\Width;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Number;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class ViewExpense extends ViewRecord
{
    protected static string $resource = ExpenseResource::class;

    public function getHeader(): ?View
    {
        $expense = $this->expenseRecord();

        return view('filament.resources.expenses.components.object-header', [
            'expense' => $expense,
            'expensesUrl' => ExpenseResource::getUrl('index', tenant: $expense->company),
            'reference' => $expense->project !== null
                ? [
                    'label' => 'Progetto di riferimento',
                    'title' => $expense->project->title,
                    'url' => ProjectResource::getUrl('view', ['record' => $expense->project], tenant: $expense->company),
                    'icon' => 'heroicon-m-briefcase',
                ]
                : ($expense->contract === null ? null : [
                    'label' => 'Contratto di riferimento',
                    'title' => $expense->contract->title,
                    'url' => ContractResource::getUrl('view', ['record' => $expense->contract], tenant: $expense->company),
                    'icon' => 'heroicon-m-document-text',
                ]),
            'money' => [
                'allocation' => Number::currency((float) $expense->allocation(), in: 'EUR', locale: 'it'),
                'actual' => Number::currency((float) $expense->actual(), in: 'EUR', locale: 'it'),
                'variance' => Number::currency((float) $expense->operationalVariance(), in: 'EUR', locale: 'it'),
            ],
        ]);
    }

    public function getMaxContentWidth(): Width
    {
        return Width::Full;
    }

    /** @return array<string, string> */
    public function getExtraBodyAttributes(): array
    {
        return ['class' => 'mp2-object-page mp2-expense-object-page'];
    }

    public function content(Schema $schema): Schema
    {
        return $schema->components([
            Livewire::make(ExpenseDetail::class, fn (): array => [
                'expenseId' => $this->getRecord()->getKey(),
                'compact' => false,
            ])->key(fn (): string => 'expense-full-'.$this->getRecord()->getKey()),
        ]);
    }

    protected function getHeaderActions(): array
    {
        return [
            EditAction::make()->label('Modifica')->icon('heroicon-m-pencil-square')->color('primary')
                ->extraAttributes(['class' => 'mp2-object-primary-action']),
            ActionGroup::make([
                $this->moveOrReclassifyAction(),
                ExpenseResource::reverseAction(),
                ExpenseResource::restoreAction(),
            ])->label('Azioni')->icon('heroicon-m-chevron-down')->color('gray')->button()->outlined(),
        ];
    }

    private function moveOrReclassifyAction(): Action
    {
        return Action::make('moveOrReclassify')
            ->label('Sposta o riclassifica')
            ->visible(fn (Expense $record): bool => ExpenseResource::canEdit($record))
            ->modalHeading('Sposta o riclassifica la Spesa')
            ->modalSubmitActionLabel('Conferma modifica')
            ->modalWidth(Width::FourExtraLarge)
            ->form($this->impactForm())
            ->action(function (array $data, Expense $record): void {
                $actor = auth()->user();
                abort_unless($actor instanceof User, 403);
                $preview = app(UpdateExpense::class)->preview($actor, $record, $data);
                app(UpdateExpense::class)->confirm($actor, $record, $preview, (string) $data['operation_id']);
                ProjectOverspendNotifier::sendForOperation((string) $data['operation_id']);
                $record->refresh();
            });
    }

    private function expenseRecord(): Expense
    {
        $record = $this->getRecord();
        if (! $record instanceof Expense) {
            throw new \UnexpectedValueException('Invalid Expense record.');
        }

        return $record;
    }

    /** @return array<int, mixed> */
    private function impactForm(): array
    {
        /** @var Expense $expense */
        $expense = $this->record;
        $invalidate = fn (Set $set): mixed => $set('impact_confirmed', false);

        return [
            Section::make('Destinazione')
                ->description('Modifica solo i riferimenti che devono cambiare. Le Righe e lo storico della Spesa restano invariati.')
                ->schema([
                    Select::make('exercise_id')->label('Esercizio')
                        ->options(Exercise::query()->where('company_id', $expense->company_id)->open()->orderByDesc('year')->pluck('year', 'id')->all())
                        ->default($expense->exercise_id)->required()->live()->afterStateUpdated($invalidate),
                    Select::make('project_id')->label('Progetto')
                        ->options(Project::query()->where('company_id', $expense->company_id)->active()->orderBy('title')->pluck('title', 'id')->all())
                        ->default($expense->project_id)->placeholder('Nessuno')->searchable()->live()
                        ->afterStateUpdated(function (Set $set, mixed $state) use ($invalidate): void {
                            if (filled($state)) {
                                $set('contract_id', null);
                            }
                            $invalidate($set);
                        }),
                    Select::make('contract_id')->label('Contratto')
                        ->options(Contract::query()->where('company_id', $expense->company_id)->active()->orderBy('title')->pluck('title', 'id')->all())
                        ->default($expense->contract_id)->placeholder('Nessuno')->searchable()->live()
                        ->afterStateUpdated(function (Set $set, mixed $state) use ($invalidate): void {
                            if (filled($state)) {
                                $set('project_id', null);
                            }
                            $invalidate($set);
                        }),
                    Select::make('supplier_id')->label('Fornitore')->options($this->supplierOptions($expense))
                        ->default($expense->supplier_id)->placeholder('Nessuno')->searchable()->live()->afterStateUpdated($invalidate)
                        ->createOptionForm([
                            TextInput::make('legal_name')->label('Ragione sociale')->required()->maxLength(255),
                            TextInput::make('vat_number')->label('Partita IVA')->maxLength(64),
                            Textarea::make('notes')->label('Note'),
                        ])
                        ->createOptionUsing(function (array $data) use ($expense): int {
                            $actor = auth()->user();
                            abort_unless($actor instanceof User, 403);

                            return app(CreateSupplier::class)->execute($actor, $expense->company, $data, (string) Str::uuid())->id;
                        })
                        ->createOptionAction(fn (Action $action): Action => $action
                            ->label('Crea fornitore')
                            ->modalHeading('Nuovo fornitore')
                            ->visible(fn (): bool => $this->canCreateSupplier($expense)))
                        ->visible(fn (Get $get): bool => blank($get('contract_id'))),
                    Select::make('direct_cost_center_id')->label('Centro di Costo')->options($this->costCenterOptions($expense))
                        ->default($expense->direct_cost_center_id)->placeholder('Non classificata')->searchable()->live()->afterStateUpdated($invalidate)
                        ->visible(fn (Get $get): bool => blank($get('project_id')) && blank($get('contract_id'))),
                    Textarea::make('reason')->label('Motivo della modifica')
                        ->helperText('Obbligatorio quando la modifica riclassifica Effettivi o interviene dopo un Budget approvato.')
                        ->visible(fn (Get $get): bool => $this->requiresChangeReason($get, $expense))
                        ->required(fn (Get $get): bool => $this->requiresChangeReason($get, $expense))
                        ->dehydrated(fn (Get $get): bool => $this->requiresChangeReason($get, $expense))
                        ->live()->afterStateUpdated($invalidate)->columnSpanFull(),
                ])->columns(2),
            Section::make('Informazioni richieste')
                ->description('Compaiono soltanto le dichiarazioni necessarie per la destinazione selezionata.')
                ->schema([
                    Select::make('actual_kind')->label('Tipo di attribuzione degli Effettivi')
                        ->options(fn (Get $get): array => filled($get('contract_id')) ? ContractActualKind::options() : ProjectActualKind::options())
                        ->placeholder('Ordinario')
                        ->visible(fn (Get $get): bool => $this->requiresActivityDeclaration($get, $expense))
                        ->live()->afterStateUpdated($invalidate),
                    Checkbox::make('open_project')
                        ->label('Apri il Progetto insieme allo spostamento')
                        ->helperText('Il Progetto selezionato è Pianificato: per attribuirgli gli Effettivi verrà aperto nella stessa operazione.')
                        ->visible(fn (Get $get): bool => $this->requiresProjectOpening($get, $expense))
                        ->accepted()->required()->live()->afterStateUpdated($invalidate),
                    Textarea::make('activity_note')
                        ->label('Motivazione dell’attribuzione')
                        ->helperText('Obbligatoria per Effettivi tardivi, rimborsi, costi di cessazione o correzioni.')
                        ->visible(fn (Get $get): bool => $this->requiresActivityNote($get, $expense))
                        ->required()->live()->afterStateUpdated($invalidate)->columnSpanFull(),
                    Textarea::make('overspend_note')
                        ->label('Motivazione della sovraspesa')
                        ->helperText('Questa modifica crea o aumenta una sovraspesa e l’impostazione aziendale richiede una nota.')
                        ->visible(fn (Get $get): bool => $this->requiresOverspendNote($get, $expense))
                        ->required()->live()->afterStateUpdated($invalidate)->columnSpanFull(),
                    Checkbox::make('supplier_replacement_acknowledged')->label('Confermo l’uso del Fornitore associato al Contratto')
                        ->helperText('Il Fornitore della Spesa verrà sostituito con quello del Contratto selezionato.')
                        ->visible(fn (Get $get): bool => $this->requiresSupplierReplacement($get, $expense))
                        ->accepted()->required()->live()->afterStateUpdated($invalidate)->columnSpanFull(),
                ])
                ->columns(2)
                ->visible(fn (Get $get): bool => $this->requiresActivityDeclaration($get, $expense)
                    || $this->requiresOverspendNote($get, $expense)
                    || $this->requiresSupplierReplacement($get, $expense)),
            Section::make('Riepilogo della modifica')
                ->schema([
                    Placeholder::make('impact_preview')->hiddenLabel()
                        ->content(fn (Get $get): View => $this->impactPreview($get, $expense)),
                    Checkbox::make('impact_confirmed')->label('Ho verificato e confermo queste modifiche')->accepted()->required(),
                ]),
            Hidden::make('operation_id')->default(fn (): string => (string) Str::uuid()),
        ];
    }

    /** @return array<int, string> */
    private function supplierOptions(Expense $expense): array
    {
        $options = Supplier::query()->where('company_id', $expense->company_id)->active()->orderBy('legal_name')->pluck('legal_name', 'id')->all();
        if ($expense->supplier !== null && $expense->supplier->isArchived()) {
            $options[$expense->supplier->id] = $expense->supplier->legal_name.' · Archiviato';
        }

        return $options;
    }

    /** @return array<int, string> */
    private function costCenterOptions(Expense $expense): array
    {
        $options = CostCenter::query()->where('company_id', $expense->company_id)->active()->orderBy('name')->pluck('name', 'id')->all();
        if ($expense->directCostCenter !== null && $expense->directCostCenter->isArchived()) {
            $options[$expense->directCostCenter->id] = $expense->directCostCenter->name.' · Archiviato';
        }

        return $options;
    }

    private function canCreateSupplier(Expense $expense): bool
    {
        $actor = auth()->user();

        return $actor instanceof User && $actor->hasCapability($expense->company, Capability::ManageMasterData);
    }

    private function requiresActivityDeclaration(Get $get, Expense $expense): bool
    {
        return $expense->hasActuals()
            && $this->attributionChanges($get, $expense)
            && (filled($get('project_id')) || filled($get('contract_id')));
    }

    private function requiresChangeReason(Get $get, Expense $expense): bool
    {
        $exerciseId = filled($get('exercise_id')) ? (int) $get('exercise_id') : $expense->exercise_id;
        $targetExercise = Exercise::query()
            ->where('company_id', $expense->company_id)
            ->find($exerciseId);
        $supplierId = filled($get('contract_id'))
            ? Contract::query()->where('company_id', $expense->company_id)->find((int) $get('contract_id'))?->supplier_id
            : (filled($get('supplier_id')) ? (int) $get('supplier_id') : null);
        $costCenterId = blank($get('project_id')) && blank($get('contract_id')) && filled($get('direct_cost_center_id'))
            ? (int) $get('direct_cost_center_id')
            : null;
        $changes = $exerciseId !== $expense->exercise_id
            || $this->ownerChanges($get, $expense)
            || $supplierId !== $expense->supplier_id
            || $costCenterId !== $expense->direct_cost_center_id;

        return ($expense->hasActuals() && ($exerciseId !== $expense->exercise_id || $this->ownerChanges($get, $expense)))
            || ($changes && ($expense->exercise->hasApprovedBudget() || $targetExercise?->hasApprovedBudget() === true));
    }

    private function requiresProjectOpening(Get $get, Expense $expense): bool
    {
        if (! $this->requiresActivityDeclaration($get, $expense)
            || blank($get('project_id'))
            || ! in_array($get('actual_kind'), [null, '', ProjectActualKind::Ordinary->value], true)) {
            return false;
        }

        $project = Project::query()
            ->where('company_id', $expense->company_id)
            ->find((int) $get('project_id'));

        return $project !== null
            && $project->stateAtDate(now($project->company->timezone)->toDateString()) === ProjectState::Planned;
    }

    private function requiresActivityNote(Get $get, Expense $expense): bool
    {
        return $this->requiresActivityDeclaration($get, $expense)
            && filled($get('actual_kind'))
            && $get('actual_kind') !== ProjectActualKind::Ordinary->value;
    }

    private function requiresSupplierReplacement(Get $get, Expense $expense): bool
    {
        if (blank($get('contract_id')) || ! $this->ownerChanges($get, $expense)) {
            return false;
        }

        $contract = Contract::query()
            ->where('company_id', $expense->company_id)
            ->find((int) $get('contract_id'));

        return $contract !== null && $expense->supplier_id !== $contract->supplier_id;
    }

    private function requiresOverspendNote(Get $get, Expense $expense): bool
    {
        if (! $expense->company->overspend_note_required) {
            return false;
        }

        try {
            $plan = $this->previewPlan($get, $expense);
        } catch (ValidationException) {
            return false;
        }

        foreach ($plan->projectImpacts as $impact) {
            $overspend = ProjectOverspend::detect((string) $impact['variance_before'], (string) $impact['variance_after']);
            if ($overspend !== ProjectOverspendResult::None) {
                return true;
            }
        }

        return false;
    }

    private function ownerChanges(Get $get, Expense $expense): bool
    {
        $projectId = filled($get('project_id')) ? (int) $get('project_id') : null;
        $contractId = filled($get('contract_id')) ? (int) $get('contract_id') : null;

        return $projectId !== $expense->project_id || $contractId !== $expense->contract_id;
    }

    private function attributionChanges(Get $get, Expense $expense): bool
    {
        return $this->ownerChanges($get, $expense)
            || (int) $get('exercise_id') !== $expense->exercise_id;
    }

    private function impactPreview(Get $get, Expense $expense): View
    {
        try {
            $plan = $this->previewPlan($get, $expense);
        } catch (ValidationException $exception) {
            return view('filament.resources.expenses.components.impact-preview', [
                'error' => collect($exception->errors())->flatten()->first()
                    ?? 'Non è possibile calcolare l’anteprima.',
                'summary' => null,
            ]);
        }

        return view('filament.resources.expenses.components.impact-preview', [
            'error' => null,
            'summary' => $this->impactSummary($plan, $expense),
        ]);
    }

    private function previewPlan(Get $get, Expense $expense): ExpenseImpactPlan
    {
        $actor = auth()->user();
        if (! $actor instanceof User || $get('exercise_id') === null) {
            throw ValidationException::withMessages([
                'exercise_id' => 'Selezionare i nuovi riferimenti per calcolare l’anteprima.',
            ]);
        }

        return app(UpdateExpense::class)->preview($actor, $expense, $this->impactInput($get));
    }

    /** @return array<string, mixed> */
    private function impactInput(Get $get): array
    {
        return [
            'exercise_id' => $get('exercise_id'),
            'supplier_id' => $get('supplier_id'),
            'direct_cost_center_id' => $get('direct_cost_center_id'),
            'project_id' => $get('project_id'),
            'contract_id' => $get('contract_id'),
            'reason' => $get('reason'),
            'actual_kind' => $get('actual_kind'),
            'open_project' => $get('open_project'),
            'activity_note' => $get('activity_note'),
            'overspend_note' => $get('overspend_note'),
            'supplier_replacement_acknowledged' => $get('supplier_replacement_acknowledged'),
        ];
    }

    /** @return array<string, mixed> */
    private function impactSummary(ExpenseImpactPlan $plan, Expense $expense): array
    {
        $sourceExercise = Exercise::query()->findOrFail($plan->sourceExerciseId);
        $targetExercise = Exercise::query()->findOrFail($plan->targetExerciseId);
        $changes = [
            $this->changeRow('Esercizio', (string) $sourceExercise->year, (string) $targetExercise->year),
            $this->changeRow('Contenitore', $this->ownerLabel($plan->sourceProjectId, $plan->sourceContractId), $this->ownerLabel($plan->targetProjectId, $plan->targetContractId)),
            $this->changeRow('Fornitore', $this->supplierLabel($expense->supplier_id), $this->supplierLabel($plan->supplierId)),
            $this->changeRow('Centro di Costo', $this->costCenterLabel($plan->sourceCostCenterId), $this->costCenterLabel($plan->targetCostCenterId)),
        ];
        $exerciseImpacts = collect($plan->exerciseImpacts)->map(fn (array $impact): array => [
            'year' => (int) $impact['year'],
            'allocation_before' => $this->money((string) $impact['allocation_before']),
            'allocation_after' => $this->money((string) $impact['allocation_after']),
            'allocation_delta' => $this->signedMoney((string) $impact['allocation_delta']),
            'actual_before' => $this->money((string) $impact['actual_before']),
            'actual_after' => $this->money((string) $impact['actual_after']),
            'actual_delta' => $this->signedMoney((string) $impact['actual_delta']),
        ])->values()->all();
        $projectImpacts = collect($plan->projectImpacts)
            ->filter(fn (array $impact): bool => (float) $impact['allocation_delta'] !== 0.0 || (float) $impact['actual_delta'] !== 0.0)
            ->map(function (array $impact): array {
                $overspend = ProjectOverspend::detect((string) $impact['variance_before'], (string) $impact['variance_after']);

                return [
                    'label' => $this->ownerLabel((int) $impact['owner_id'], null).' · Esercizio '.$impact['year'],
                    'allocation_before' => $this->money((string) $impact['allocation_before']),
                    'allocation_after' => $this->money((string) $impact['allocation_after']),
                    'actual_before' => $this->money((string) $impact['actual_before']),
                    'actual_after' => $this->money((string) $impact['actual_after']),
                    'variance_before' => $this->money((string) $impact['variance_before']),
                    'variance_after' => $this->money((string) $impact['variance_after']),
                    'warning' => match ($overspend) {
                        ProjectOverspendResult::Created => 'La modifica crea una sovraspesa.',
                        ProjectOverspendResult::Increased => 'La modifica aumenta la sovraspesa.',
                        ProjectOverspendResult::None => null,
                    },
                ];
            })->values()->all();

        return [
            'changed' => collect($changes)->contains(fn (array $change): bool => $change['changed']),
            'identity' => 'La Spesa #'.$plan->expenseId.' e le sue '.count($plan->lineIds).' Righe mantengono identità e storico.',
            'changes' => $changes,
            'exercise_impacts' => $exerciseImpacts,
            'exercise_totals_change' => collect($plan->exerciseImpacts)->contains(
                fn (array $impact): bool => (float) $impact['allocation_delta'] !== 0.0 || (float) $impact['actual_delta'] !== 0.0,
            ),
            'project_impacts' => $projectImpacts,
            'opens_project' => $plan->openProject,
        ];
    }

    /** @return array{label: string, from: string, to: string, changed: bool} */
    private function changeRow(string $label, string $from, string $to): array
    {
        return ['label' => $label, 'from' => $from, 'to' => $to, 'changed' => $from !== $to];
    }

    private function money(string $amount): string
    {
        return Number::currency((float) $amount, in: 'EUR', locale: 'it');
    }

    private function signedMoney(string $amount): string
    {
        return ((float) $amount > 0 ? '+' : '').$this->money($amount);
    }

    private function costCenterLabel(?int $costCenterId): string
    {
        if ($costCenterId === null) {
            return 'Non classificato';
        }

        $costCenter = CostCenter::query()->find($costCenterId);

        return $costCenter === null
            ? 'Centro di Costo #'.$costCenterId
            : $costCenter->name.($costCenter->isArchived() ? ' · Archiviato' : '');
    }

    private function ownerLabel(?int $projectId, ?int $contractId): string
    {
        if ($projectId !== null) {
            $project = Project::query()->find($projectId);

            return $project === null ? 'Progetto #'.$projectId : 'Progetto · '.$project->title;
        }

        if ($contractId === null) {
            return 'Spesa autonoma';
        }

        $contract = Contract::query()->find($contractId);

        return $contract === null ? 'Contratto #'.$contractId : 'Contratto · '.$contract->title;
    }

    private function supplierLabel(?int $supplierId): string
    {
        if ($supplierId === null) {
            return 'Nessun Fornitore';
        }

        $supplier = Supplier::query()->find($supplierId);

        return $supplier === null
            ? 'Fornitore #'.$supplierId
            : $supplier->legal_name.($supplier->isArchived() ? ' · Archiviato' : '');
    }
}
