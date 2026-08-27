<?php

namespace App\Filament\Resources\Exercises\Pages;

use App\Actions\LateCorrections\RecordHistoricalErrorAnnotation;
use App\Actions\LateCorrections\RecordLateCorrection;
use App\Actions\Operations\UploadAttachment;
use App\Actions\Proposals\InitializeProposal;
use App\Domain\Company\Capability;
use App\Domain\LateCorrections\HistoricalCorrectionSource;
use App\Domain\LateCorrections\HistoricalErrorKind;
use App\Domain\LateCorrections\HistoricalExpenseCompatibility;
use App\Filament\Resources\Budgets\BudgetResource;
use App\Filament\Resources\Closings\ClosingResource;
use App\Filament\Resources\Exercises\ExerciseResource;
use App\Filament\Resources\Expenses\ExpenseResource;
use App\Filament\Resources\Proposals\ProposalResource;
use App\Models\BudgetSnapshot;
use App\Models\ClosingSnapshot;
use App\Models\Company;
use App\Models\Contract;
use App\Models\ContractExerciseClassification;
use App\Models\CostCenter;
use App\Models\Exercise;
use App\Models\Expense;
use App\Models\Project;
use App\Models\ProjectExerciseClassification;
use App\Models\Proposal;
use App\Models\Supplier;
use App\Models\TenantCompany;
use App\Models\User;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Forms\Components\Checkbox;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Pages\ViewRecord;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class ViewExercise extends ViewRecord
{
    protected static string $resource = ExerciseResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('viewClosing')
                ->label('Apri Chiusura')
                ->url(fn (): string => ClosingResource::getUrl('view', [
                    'record' => ClosingSnapshot::query()->where('exercise_id', $this->exerciseRecord()->id)->firstOrFail(),
                ]))
                ->visible(fn (): bool => ClosingSnapshot::query()->where('exercise_id', $this->exerciseRecord()->id)->exists()),
            Action::make('closeExercise')
                ->label('Chiudi esercizio')
                ->color('danger')
                ->url(fn (): string => ExerciseResource::getUrl('close', ['record' => $this->exerciseRecord()]))
                ->visible(fn (): bool => $this->canCloseExercise()),
            Action::make('viewProposal')->label('Apri Proposta')->url(fn (): string => ProposalResource::getUrl('view', ['record' => Proposal::query()->where('exercise_id', $this->exerciseRecord()->id)->latest('id')->firstOrFail()]))->visible(fn (): bool => Proposal::query()->where('exercise_id', $this->exerciseRecord()->id)->exists()),
            Action::make('viewBudget')->label('Apri Budget')->url(fn (): string => BudgetResource::getUrl('view', ['record' => BudgetSnapshot::query()->where('exercise_id', $this->exerciseRecord()->id)->latest('version')->firstOrFail()]))->visible(fn (): bool => BudgetSnapshot::query()->where('exercise_id', $this->exerciseRecord()->id)->exists()),
            Action::make('initializeProposal')
                ->label(fn (): string => $this->hasBudget() ? 'Crea revisione' : 'Inizializza proposta')
                ->requiresConfirmation()
                ->modalHeading(fn (): string => $this->hasBudget() ? 'Crea Revisione di Budget' : 'Inizializza Proposta di Budget')
                ->modalDescription(fn (): string => $this->hasBudget()
                    ? 'La base è la realtà corrente. L’ultimo Budget resta immutabile e viene mostrato solo come confronto; gli Effettivi restano in sola lettura.'
                    : 'La Proposta resta isolata: gli Effettivi sono mostrati in sola lettura e non vengono modificati.')
                ->modalSubmitActionLabel(fn (): string => $this->hasBudget() ? 'Crea revisione' : 'Inizializza proposta')
                ->visible(fn (): bool => $this->canManageProposals() && $this->exerciseRecord()->isOpen())
                ->disabled(fn (): bool => $this->proposalDisabledReason() !== null)
                ->tooltip(fn (): ?string => $this->proposalDisabledReason())
                ->form([Hidden::make('operation_id')->default(fn (): string => (string) Str::uuid())])
                ->action(function (array $data): void {
                    $actor = auth()->user();
                    $tenant = Filament::getTenant();
                    $company = $tenant instanceof TenantCompany ? $tenant->company : null;
                    abort_unless($actor instanceof User && $company instanceof Company, 403);
                    $proposal = app(InitializeProposal::class)->execute($actor, $company, $this->exerciseRecord(), (string) $data['operation_id']);
                    $this->redirect(ProposalResource::getUrl('view', ['record' => $proposal], tenant: $company));
                }),
            Action::make('lateCorrection')
                ->label('Registra correzione tardiva')
                ->requiresConfirmation()
                ->modalHeading('Registra correzione tardiva')
                ->modalDescription('L’importo apparteneva realmente a questo Esercizio Chiuso. L’Esercizio non verrà riaperto: la correzione aggiunge un solo Effettivo e lascia invariati Snapshot, Budget, Riporto, stato e imputazioni storiche.')
                ->modalSubmitActionLabel('Registra correzione')
                ->visible(fn (): bool => $this->canCorrectClosedExercise())
                ->disabled(fn (): bool => ! ClosingSnapshot::query()->where('exercise_id', $this->exerciseRecord()->id)->exists())
                ->tooltip(fn (): ?string => ClosingSnapshot::query()->where('exercise_id', $this->exerciseRecord()->id)->exists() ? null : 'La Snapshot di Chiusura canonica non è disponibile.')
                ->form([
                    Select::make('source_type')
                        ->label('Sorgente economica storica')
                        ->options([
                            'expense' => 'Spesa autonoma',
                            'project' => 'Progetto',
                            'contract' => 'Contratto',
                        ])
                        ->live()
                        ->afterStateUpdated(function (Set $set): void {
                            $set('source_origin_id', null);
                            $set('expected_source_revision', null);
                        })
                        ->required(),
                    Select::make('source_origin_id')
                        ->label('Sorgente')
                        ->options(fn (Get $get): array => $this->lateCorrectionSourceOptions((string) $get('source_type')))
                        ->required()
                        ->searchable()
                        ->live()
                        ->afterStateUpdated(function (Get $get, Set $set): void {
                            $set('expected_source_revision', $this->lateCorrectionSourceRevision((string) $get('source_type'), $get('source_origin_id')));
                        }),
                    Select::make('historical_expense_id')
                        ->label('Spesa storica (opzionale)')
                        ->placeholder('Nuova Spesa tardiva')
                        ->options(fn (): array => Expense::query()
                            ->where('company_id', $this->exerciseRecord()->company_id)
                            ->where('exercise_id', $this->exerciseRecord()->id)
                            ->orderBy('description')
                            ->get()
                            ->mapWithKeys(fn (Expense $expense): array => [$expense->id => $expense->description.' · #'.$expense->id])
                            ->all())
                        ->live()
                        ->afterStateUpdated(function (Get $get, Set $set): void {
                            $set('expected_expense_revision', filled($get('historical_expense_id'))
                                ? Expense::query()->where('company_id', $this->exerciseRecord()->company_id)->find((int) $get('historical_expense_id'))?->revision
                                : null);
                        })
                        ->helperText(fn (Get $get): ?string => $this->lateCorrectionSelectionMessage($get)),
                    TextInput::make('description')
                        ->label('Descrizione nuova Spesa')
                        ->maxLength(255)
                        ->required(fn (Get $get): bool => $this->lateCorrectionDescriptionRequired($get)),
                    Select::make('supplier_id')
                        ->label('Fornitore storico (opzionale)')
                        ->placeholder('Nessun Fornitore')
                        ->options(fn (): array => $this->lateCorrectionSupplierOptions())
                        ->visible(fn (Get $get): bool => $this->lateCorrectionSupplierVisible($get)),
                    TextInput::make('amount')
                        ->label('Importo Effettivo')
                        ->numeric()
                        ->required(),
                    Select::make('original_expense_line_id')
                        ->label('Riga originaria (opzionale)')
                        ->placeholder('Non indicata')
                        ->options(fn (Get $get): array => $this->lateCorrectionLineOptions($get('historical_expense_id')))
                        ->searchable(),
                    Textarea::make('reason')
                        ->label('Motivo')
                        ->required()
                        ->maxLength(65535),
                    Textarea::make('notes')
                        ->label('Note aggiuntive (opzionali)')
                        ->maxLength(65535),
                    Checkbox::make('belongs_to_closed_exercise')
                        ->label('L’importo apparteneva realmente a questo Esercizio')
                        ->accepted()
                        ->required(),
                    FileUpload::make('evidence')
                        ->label('Evidenza conservata (opzionale)')
                        ->storeFiles(false),
                    Hidden::make('expected_exercise_revision')->default(fn (): int => $this->exerciseRecord()->revision),
                    Hidden::make('expected_source_revision'),
                    Hidden::make('expected_expense_revision'),
                    Hidden::make('operation_id')->default(fn (): string => (string) Str::uuid()),
                    Hidden::make('evidence_operation_id')->default(fn (): string => (string) Str::uuid()),
                ])
                ->action(function (array $data): void {
                    $actor = auth()->user();
                    $tenant = Filament::getTenant();
                    $company = $tenant instanceof TenantCompany ? $tenant->company : null;
                    $exercise = $this->exerciseRecord()->fresh();
                    abort_unless($actor instanceof User && $company instanceof Company && $exercise->company_id === $company->id, 403);

                    try {
                        $correction = app(RecordLateCorrection::class)->execute(
                            $actor,
                            $exercise,
                            $data,
                            (string) $data['operation_id'],
                        );
                        if (($data['evidence'] ?? null) instanceof UploadedFile) {
                            app(UploadAttachment::class)->execute(
                                $actor,
                                $correction->expenseLine,
                                $data['evidence'],
                                (string) $data['evidence_operation_id'],
                            );
                        }
                    } catch (ValidationException $exception) {
                        $this->refreshLateCorrectionContext($data, $exception);
                        throw $exception;
                    }

                    $this->record = ExerciseResource::getEloquentQuery()->findOrFail($exercise->id);
                    $this->cacheSchema('infolist', null);
                    $this->dispatch('late-correction-recorded', correctionId: $correction->id);
                }),

            Action::make('historicalErrorAnnotation')
                ->label('Annota errore storico')
                ->requiresConfirmation()
                ->modalHeading('Annota errore storico')
                ->modalDescription('L’Annotazione conserva i dati storici senza riclassificare o modificare alcun valore: ha Nessun impatto economico e non riapre l’Esercizio.')
                ->modalSubmitActionLabel('Registra Annotazione')
                ->visible(fn (): bool => $this->canAnnotateHistoricalError())
                ->disabled(fn (): bool => ! ClosingSnapshot::query()->where('exercise_id', $this->exerciseRecord()->id)->exists())
                ->tooltip(fn (): ?string => ClosingSnapshot::query()->where('exercise_id', $this->exerciseRecord()->id)->exists() ? null : 'La Snapshot di Chiusura canonica non è disponibile.')
                ->form([
                    Select::make('kind')
                        ->label('Tipo di errore storico')
                        ->options(HistoricalErrorKind::options())
                        ->required(),
                    Textarea::make('recorded_fact')
                        ->label('Dato registrato')
                        ->helperText('Descrivere il dato memorizzato, senza usare JSON o identificativi tecnici.')
                        ->required(),
                    Textarea::make('believed_correct_fact')
                        ->label('Dato ritenuto corretto')
                        ->helperText('Descrivere il dato che si ritiene corretto, senza modificare lo storico.')
                        ->required(),
                    Select::make('affected_source_selector')
                        ->label('Sorgenti storiche interessate')
                        ->options(fn (): array => $this->historicalAnnotationSourceOptions())
                        ->multiple()
                        ->searchable()
                        ->required(),
                    Textarea::make('reason')
                        ->label('Motivo')
                        ->required()
                        ->maxLength(65535),
                    FileUpload::make('evidence')
                        ->label('Evidenza conservata (opzionale)')
                        ->storeFiles(false),
                    Hidden::make('expected_exercise_revision')->default(fn (): int => $this->exerciseRecord()->revision),
                    Hidden::make('operation_id')->default(fn (): string => (string) Str::uuid()),
                    Hidden::make('evidence_operation_id')->default(fn (): string => (string) Str::uuid()),
                ])
                ->action(function (array $data): void {
                    $actor = auth()->user();
                    $tenant = Filament::getTenant();
                    $company = $tenant instanceof TenantCompany ? $tenant->company : null;
                    $exercise = $this->exerciseRecord()->fresh();
                    abort_unless($actor instanceof User && $company instanceof Company && $exercise->company_id === $company->id, 403);

                    try {
                        $data['recorded_facts'] = ['value' => trim((string) ($data['recorded_fact'] ?? ''))];
                        $data['believed_correct_facts'] = ['value' => trim((string) ($data['believed_correct_fact'] ?? ''))];
                        $data['affected_sources'] = $this->materializeHistoricalAnnotationSources($data['affected_source_selector'] ?? null);
                        $annotation = app(RecordHistoricalErrorAnnotation::class)->execute(
                            $actor,
                            $exercise,
                            $data,
                            (string) $data['operation_id'],
                        );
                        if (($data['evidence'] ?? null) instanceof UploadedFile) {
                            app(UploadAttachment::class)->execute(
                                $actor,
                                $annotation,
                                $data['evidence'],
                                (string) $data['evidence_operation_id'],
                            );
                        }
                    } catch (ValidationException $exception) {
                        $this->refreshHistoricalAnnotationContext($exception);
                        throw $exception;
                    }

                    $this->record = ExerciseResource::getEloquentQuery()->findOrFail($exercise->id);
                    $this->cacheSchema('infolist', null);
                    $this->dispatch('historical-error-annotation-recorded', annotationId: $annotation->id);
                }),

            Action::make('createExpense')
                ->label('Nuova spesa')
                ->url(ExpenseResource::getUrl('create'))
                ->visible(fn (): bool => $this->exerciseRecord()->isOpen()),
        ];
    }

    private function exerciseRecord(): Exercise
    {
        if (! $this->record instanceof Exercise) {
            throw new \UnexpectedValueException('Invalid Exercise record.');
        }

        return $this->record;
    }

    private function canManageProposals(): bool
    {
        $actor = auth()->user();
        $tenant = Filament::getTenant();
        $company = $tenant instanceof TenantCompany ? $tenant->company : null;

        return $actor instanceof User && $company instanceof Company && $actor->hasCapability($company, Capability::ManageProposals);
    }

    private function canCloseExercise(): bool
    {
        $actor = auth()->user();
        $tenant = Filament::getTenant();
        $company = $tenant instanceof TenantCompany ? $tenant->company : null;

        return $this->exerciseRecord()->isOpen()
            && $actor instanceof User
            && $company instanceof Company
            && $actor->hasCapability($company, Capability::CloseExercise);
    }

    private function proposalDisabledReason(): ?string
    {
        $exercise = $this->exerciseRecord();
        if (! $exercise->isOpen()) {
            return 'L’Esercizio non è Aperto.';
        }
        if (Proposal::query()->where('exercise_id', $exercise->id)->where('status', 'draft')->exists()) {
            return 'Esiste già una Proposta attiva.';
        }

        return null;
    }

    private function hasBudget(): bool
    {
        return BudgetSnapshot::query()->where('exercise_id', $this->exerciseRecord()->id)->exists();
    }

    private function canCorrectClosedExercise(): bool
    {
        $actor = auth()->user();
        $tenant = Filament::getTenant();
        $company = $tenant instanceof TenantCompany ? $tenant->company : null;
        $exercise = $this->exerciseRecord();

        return $actor instanceof User
            && $company instanceof Company
            && $exercise->company_id === $company->id
            && $actor->can('correctClosed', $exercise);
    }

    private function canAnnotateHistoricalError(): bool
    {
        $actor = auth()->user();
        $tenant = Filament::getTenant();
        $company = $tenant instanceof TenantCompany ? $tenant->company : null;
        $exercise = $this->exerciseRecord();

        return $actor instanceof User
            && $company instanceof Company
            && $exercise->company_id === $company->id
            && $actor->can('annotateHistoricalError', $exercise);
    }

    private function refreshHistoricalAnnotationContext(ValidationException $exception): void
    {
        $errors = $exception->errors();
        $sourceError = $this->historicalAnnotationSourceError($errors);
        if (! isset($errors['expected_exercise_revision']) && ! $sourceError) {
            return;
        }

        $index = array_key_last($this->mountedActions);
        if ($index === null) {
            throw new \LogicException('Nessuna azione di Annotazione storica montata.');
        }

        $this->mountedActions[$index]['data']['expected_exercise_revision'] = (int) $this->exerciseRecord()->fresh()->revision;
        if ($sourceError) {
            $this->mountedActions[$index]['data']['affected_source_selector'] = [];
        }
    }

    /** @param array<string, array<int, string>> $errors */
    private function historicalAnnotationSourceError(array $errors): bool
    {
        foreach (array_keys($errors) as $key) {
            if (str_starts_with($key, 'affected_sources.')) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return list<array{type: string, id: int, revision: int}>
     */
    private function materializeHistoricalAnnotationSources(mixed $selected): array
    {
        if (! is_array($selected) || $selected === []) {
            throw ValidationException::withMessages([
                'affected_source_selector' => 'Selezionare almeno una sorgente storica.',
            ]);
        }

        $sources = [];
        foreach ($selected as $value) {
            if (! is_string($value)) {
                throw ValidationException::withMessages([
                    'affected_source_selector' => 'La sorgente storica selezionata non è valida.',
                ]);
            }
            $parts = explode(':', $value, 3);
            if (count($parts) !== 3 || ! is_numeric($parts[1]) || ! is_numeric($parts[2])) {
                throw ValidationException::withMessages([
                    'affected_source_selector' => 'La sorgente storica selezionata non è aggiornata.',
                ]);
            }
            $sources[] = [
                'type' => $parts[0],
                'id' => (int) $parts[1],
                'revision' => (int) $parts[2],
            ];
        }

        return $sources;
    }

    /** @return array<string, string> */
    private function historicalAnnotationSourceOptions(): array
    {
        $exercise = $this->exerciseRecord();
        $companyId = (int) $exercise->company_id;
        $endOfYear = $exercise->year.'-12-31';
        $options = [
            'exercise:'.$exercise->id.':'.$exercise->revision => 'Esercizio '.$exercise->year,
        ];
        $snapshot = ClosingSnapshot::query()
            ->where('company_id', $companyId)
            ->where('exercise_id', $exercise->id)
            ->first();
        if ($snapshot !== null) {
            $options['closing_snapshot:'.$snapshot->id.':0'] = 'Snapshot di Chiusura '.$snapshot->exercise_year;
        }

        Expense::query()
            ->where('company_id', $companyId)
            ->where('exercise_id', $exercise->id)
            ->whereNull('project_id')
            ->whereNull('contract_id')
            ->orderBy('description')
            ->get()
            ->each(function (Expense $expense) use (&$options): void {
                $options['expense:'.$expense->id.':'.$expense->revision] = 'Spesa · '.$expense->description.' · #'.$expense->id;
            });
        Project::query()
            ->where('company_id', $companyId)
            ->where(function ($query) use ($exercise, $endOfYear): void {
                $query->whereHas('expenses', fn ($expenses) => $expenses->where('exercise_id', $exercise->id))
                    ->orWhereHas('classifications', fn ($classifications) => $classifications->where('exercise_id', $exercise->id))
                    ->orWhereDate('initial_effective_date', '<=', $endOfYear);
            })
            ->orderBy('title')
            ->get()
            ->each(function (Project $project) use (&$options): void {
                $options['project:'.$project->id.':'.$project->revision] = 'Progetto · '.$project->title.' · #'.$project->id;
            });
        Contract::query()
            ->where('company_id', $companyId)
            ->where(function ($query) use ($exercise, $endOfYear): void {
                $query->whereHas('expenses', fn ($expenses) => $expenses->where('exercise_id', $exercise->id))
                    ->orWhereHas('classifications', fn ($classifications) => $classifications->where('exercise_id', $exercise->id))
                    ->orWhereHas('lifecycleFacts', fn ($facts) => $facts->whereDate('declared_contractual_date', '<=', $endOfYear))
                    ->orWhereHas('conditions', fn ($conditions) => $conditions->whereDate('valid_from', '<=', $endOfYear))
                    ->orWhereDate('contractual_start_date', '<=', $endOfYear);
            })
            ->orderBy('title')
            ->get()
            ->each(function (Contract $contract) use (&$options): void {
                $options['contract:'.$contract->id.':'.$contract->revision] = 'Contratto · '.$contract->title.' · #'.$contract->id;
            });

        $supplierIds = Expense::query()
            ->where('company_id', $companyId)
            ->where('exercise_id', $exercise->id)
            ->whereNotNull('supplier_id')
            ->pluck('supplier_id')
            ->merge(Contract::query()->where('company_id', $companyId)->whereNotNull('supplier_id')->pluck('supplier_id'))
            ->unique();
        Supplier::query()->whereIn('id', $supplierIds)->orderBy('legal_name')->get()->each(function (Supplier $supplier) use (&$options): void {
            $label = $supplier->legal_name.($supplier->isArchived() ? ' · Archiviato' : '');
            $options['supplier:'.$supplier->id.':'.($supplier->updated_at?->getTimestamp() ?? 0)] = 'Fornitore · '.$label.' · #'.$supplier->id;
        });

        $costCenterIds = Expense::query()
            ->where('company_id', $companyId)
            ->where('exercise_id', $exercise->id)
            ->whereNotNull('direct_cost_center_id')
            ->pluck('direct_cost_center_id')
            ->merge(ProjectExerciseClassification::query()->where('company_id', $companyId)->where('exercise_id', $exercise->id)->pluck('cost_center_id'))
            ->merge(ContractExerciseClassification::query()->where('company_id', $companyId)->where('exercise_id', $exercise->id)->pluck('cost_center_id'))
            ->unique();
        CostCenter::query()->whereIn('id', $costCenterIds)->orderBy('name')->get()->each(function (CostCenter $costCenter) use (&$options): void {
            $label = $costCenter->name.($costCenter->isArchived() ? ' · Archiviato' : '');
            $options['cost_center:'.$costCenter->id.':'.($costCenter->updated_at?->getTimestamp() ?? 0)] = 'Centro di Costo · '.$label.' · #'.$costCenter->id;
        });

        return $options;
    }

    /** @return array<int|string, string> */
    private function lateCorrectionSourceOptions(string $sourceType): array
    {
        $exercise = $this->exerciseRecord();
        $companyId = $exercise->company_id;

        return match ($sourceType) {
            'project' => app(HistoricalCorrectionSource::class)->projects($exercise)->orderBy('title')->pluck('title', 'id')->all(),
            'contract' => app(HistoricalCorrectionSource::class)->contracts($exercise)->orderBy('title')->pluck('title', 'id')->all(),
            'expense' => Expense::query()
                ->where('company_id', $companyId)
                ->where('exercise_id', $exercise->id)
                ->whereNull('project_id')
                ->whereNull('contract_id')
                ->orderBy('description')
                ->pluck('description', 'id')
                ->all(),
            default => [],
        };
    }

    private function lateCorrectionSource(string $sourceType, int $sourceId): Expense|Project|Contract
    {
        $exercise = $this->exerciseRecord();
        $source = match ($sourceType) {
            'project' => Project::query()->where('company_id', $exercise->company_id)->findOrFail($sourceId),
            'contract' => Contract::query()->where('company_id', $exercise->company_id)->findOrFail($sourceId),
            'expense' => Expense::query()
                ->where('company_id', $exercise->company_id)
                ->where('exercise_id', $exercise->id)
                ->findOrFail($sourceId),
            default => throw new \UnexpectedValueException('Invalid historical source type.'),
        };

        return $source;
    }

    private function lateCorrectionSourceRevision(string $sourceType, mixed $sourceId): ?int
    {
        if (! is_numeric($sourceId)) {
            return null;
        }

        return (int) $this->lateCorrectionSource($sourceType, (int) $sourceId)->revision;
    }

    /** @return array<int|string, string> */
    private function lateCorrectionSupplierOptions(): array
    {
        return Supplier::query()
            ->where('company_id', $this->exerciseRecord()->company_id)
            ->orderBy('legal_name')
            ->get()
            ->mapWithKeys(fn (Supplier $supplier): array => [
                $supplier->id => $supplier->legal_name.($supplier->isArchived() ? ' · Archiviato' : ''),
            ])
            ->all();
    }

    private function lateCorrectionSupplierVisible(Get $get): bool
    {
        if (! in_array($get('source_type'), ['expense', 'project'], true)) {
            return false;
        }
        if (blank($get('historical_expense_id'))) {
            return true;
        }

        return $this->lateCorrectionSelectionIsIncompatible($get);
    }

    private function lateCorrectionDescriptionRequired(Get $get): bool
    {
        if (blank($get('historical_expense_id'))) {
            return true;
        }

        return $this->lateCorrectionSelectionIsIncompatible($get);
    }

    private function lateCorrectionSelectionMessage(Get $get): ?string
    {
        if (blank($get('source_origin_id'))) {
            return null;
        }
        if (blank($get('historical_expense_id'))) {
            return 'Verrà creata una nuova Spesa manuale tardiva nello stesso contesto storico.';
        }

        return $this->lateCorrectionSelectionIsIncompatible($get)
            ? 'La Spesa selezionata non è compatibile: verrà creata una nuova Spesa manuale tardiva nello stesso contesto storico.'
            : 'La Spesa selezionata è compatibile: verrà aggiunta una nuova Riga Effettivo senza modificare le Righe esistenti.';
    }

    private function lateCorrectionSelectionIsIncompatible(Get $get): bool
    {
        if (! is_numeric($get('historical_expense_id')) || ! is_numeric($get('source_origin_id'))) {
            return true;
        }

        $source = $this->lateCorrectionSource((string) $get('source_type'), (int) $get('source_origin_id'));
        $expense = Expense::query()
            ->where('company_id', $this->exerciseRecord()->company_id)
            ->where('exercise_id', $this->exerciseRecord()->id)
            ->find((int) $get('historical_expense_id'));

        return $expense === null || ! app(HistoricalExpenseCompatibility::class)->accepts(
            $expense,
            $this->exerciseRecord(),
            (string) $get('source_type'),
            (int) $source->id,
        );
    }

    /** @param array<string, mixed> $data */
    private function refreshLateCorrectionContext(array $data, ValidationException $exception): void
    {
        $errors = $exception->errors();
        $exerciseStale = isset($errors['source_type'])
            && str_contains((string) ($errors['source_type'][0] ?? ''), 'Esercizio è cambiato');
        $sourceStale = isset($errors['source_origin_id'])
            && str_contains((string) ($errors['source_origin_id'][0] ?? ''), 'sorgente storica è cambiata');
        $expenseStale = isset($errors['historical_expense_id'])
            && str_contains((string) ($errors['historical_expense_id'][0] ?? ''), 'Spesa storica è cambiata');

        if ($exerciseStale) {
            $this->setLateCorrectionState('expected_exercise_revision', (int) $this->exerciseRecord()->fresh()->revision);
            $this->setLateCorrectionState('source_type', null);
            $this->setLateCorrectionState('source_origin_id', null);
            $this->setLateCorrectionState('expected_source_revision', null);
            $this->setLateCorrectionState('historical_expense_id', null);
            $this->setLateCorrectionState('expected_expense_revision', null);
            $this->setLateCorrectionState('original_expense_line_id', null);

            return;
        }

        if ($sourceStale && is_numeric($data['source_origin_id'] ?? null)) {
            $source = $this->lateCorrectionSource((string) $data['source_type'], (int) $data['source_origin_id']);
            $this->setLateCorrectionState('expected_source_revision', (int) $source->revision);
        }

        if ($expenseStale && is_numeric($data['historical_expense_id'] ?? null)) {
            $expense = Expense::query()
                ->where('company_id', $this->exerciseRecord()->company_id)
                ->where('exercise_id', $this->exerciseRecord()->id)
                ->find((int) $data['historical_expense_id']);
            $this->setLateCorrectionState('expected_expense_revision', $expense?->revision);
        }
    }

    private function setLateCorrectionState(string $field, mixed $value): void
    {
        $index = array_key_last($this->mountedActions);
        if ($index === null) {
            throw new \LogicException('Nessuna azione di correzione tardiva montata.');
        }

        $this->mountedActions[$index]['data'][$field] = $value;
    }

    /** @return array<int|string, string> */
    private function lateCorrectionLineOptions(mixed $expenseId): array
    {
        if (! is_numeric($expenseId)) {
            return [];
        }

        $expense = Expense::query()
            ->where('company_id', $this->exerciseRecord()->company_id)
            ->where('exercise_id', $this->exerciseRecord()->id)
            ->find((int) $expenseId);
        if ($expense === null) {
            return [];
        }

        return $expense->lines()
            ->orderBy('id')
            ->get()
            ->mapWithKeys(fn ($line): array => [$line->id => '#'.$line->id.' · '.$line->lineType()->label().' · € '.$line->amount])
            ->all();
    }
}
