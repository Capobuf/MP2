<?php

namespace App\Filament\Pages;

use App\Actions\Reporting\BuildReport;
use App\Domain\CostCenters\CostCenterHierarchy;
use App\Domain\Expenses\Decimal;
use App\Domain\Reporting\ActualReference;
use App\Domain\Reporting\ComparisonCategory;
use App\Domain\Reporting\ReferenceType;
use App\Domain\Reporting\ReportDefinition;
use App\Domain\Reporting\ReportKind;
use App\Domain\Reporting\ReportResult;
use App\Domain\Reporting\ReportSource;
use App\Domain\Reporting\SecondaryLabel;
use App\Filament\Forms\DateInput;
use App\Filament\Resources\Contracts\ContractResource;
use App\Filament\Resources\Expenses\ExpenseResource;
use App\Filament\Resources\Projects\ProjectResource;
use App\Models\BudgetSnapshot;
use App\Models\Company;
use App\Models\Contract;
use App\Models\Exercise;
use App\Models\Expense;
use App\Models\Project;
use App\Models\Supplier;
use App\Models\TenantCompany;
use App\Models\User;
use App\Support\Reporting\ReportChartDefinitions;
use BackedEnum;
use BezhanSalleh\FilamentShield\Traits\HasPageShield;
use Filament\Facades\Filament;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\ToggleButtons;
use Filament\Pages\Page;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Url;

/**
 * @property-read Schema $references
 * @property-read Schema $filters
 */
class Reports extends Page
{
    use HasPageShield;

    protected string $view = 'filament.pages.reports';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedChartBarSquare;

    protected static ?string $navigationLabel = 'Report';

    protected static ?string $navigationParentItem = 'Panoramica';

    protected static ?string $title = 'Reportistica';

    protected static ?int $navigationSort = 10;

    #[Url]
    public ?int $exerciseId = null;

    #[Url]
    public ?string $kind = null;

    #[Url]
    public ?int $budgetId = null;

    #[Url]
    public ?int $secondBudgetId = null;

    #[Url]
    public ?string $actualReference = null;

    #[Url]
    public ?int $comparisonExerciseId = null;

    #[Url]
    public ?string $exerciseMeasure = null;

    #[Url]
    public ?string $dateFrom = null;

    #[Url]
    public ?string $dateTo = null;

    #[Url]
    public int|string|null $costCenterId = null;

    #[Url]
    public ?int $projectId = null;

    #[Url]
    public ?int $contractId = null;

    #[Url]
    public ?int $expenseId = null;

    #[Url]
    public ?int $supplierId = null;

    #[Url]
    public bool $auto = false;

    #[Url]
    public ?string $comparisonCategory = null;

    public bool $filtersOpen = false;

    /** @var array<string, mixed>|null */
    public ?array $report = null;

    /** @var array<string, mixed>|null */
    public ?array $definition = null;

    public function mount(): void
    {
        abort_unless(static::canAccess(), 403);

        if ($this->kind !== null && ReportKind::tryFrom($this->kind) === null) {
            $this->addError('kind', 'Famiglia report non supportata.');

            return;
        }

        if ($this->kind !== null) {
            $this->preserveCompatibleContext(ReportKind::from($this->kind));
        }

        if ($this->isReportConfigurationComplete()) {
            $this->generate();
        }
    }

    public function references(Schema $schema): Schema
    {
        return $schema
            ->columns(['default' => 1, 'md' => 2, 'xl' => 4])
            ->components([
                Select::make('exerciseId')
                    ->label('Esercizio')
                    ->placeholder('Seleziona l’Esercizio')
                    ->options(fn (): array => $this->exerciseOptions())
                    ->native(false)
                    ->live(),
                Select::make('budgetId')
                    ->label(fn (): string => $this->kind === ReportKind::AnnualExecutive->value
                        ? 'Budget per il confronto (opzionale)'
                        : 'Budget iniziale')
                    ->placeholder('Seleziona il Budget')
                    ->options(fn (): array => $this->budgetOptions())
                    ->native(false)
                    ->live()
                    ->visible(fn (): bool => in_array($this->kind, [
                        ReportKind::AnnualExecutive->value,
                        ReportKind::BudgetActual->value,
                        ReportKind::BudgetCurrentAllocation->value,
                        ReportKind::BudgetVersions->value,
                    ], true)),
                Select::make('secondBudgetId')
                    ->label('Budget Finale')
                    ->placeholder('Seleziona il secondo Budget')
                    ->options(fn (): array => $this->budgetOptions())
                    ->native(false)
                    ->live()
                    ->visible(fn (): bool => $this->kind === ReportKind::BudgetVersions->value),
                ToggleButtons::make('actualReference')
                    ->label('Tipo di Effettivo')
                    ->options(fn (): array => $this->actualOptions())
                    ->grouped()
                    ->live()
                    ->columnSpan(['default' => 1, 'md' => 2])
                    ->visible(fn (): bool => in_array($this->kind, [
                        ReportKind::AnnualExecutive->value,
                        ReportKind::BudgetActual->value,
                    ], true)),
                Select::make('comparisonExerciseId')
                    ->label('Secondo Esercizio')
                    ->placeholder('Seleziona il secondo Esercizio')
                    ->options(fn (): array => $this->exerciseOptions())
                    ->native(false)
                    ->live()
                    ->visible(fn (): bool => $this->kind === ReportKind::Exercises->value),
                ToggleButtons::make('exerciseMeasure')
                    ->label('Stessa Misura')
                    ->options($this->exerciseMeasureOptions())
                    ->grouped()
                    ->live()
                    ->columnSpan(['default' => 1, 'md' => 2])
                    ->visible(fn (): bool => $this->kind === ReportKind::Exercises->value),
            ]);
    }

    public function filters(Schema $schema): Schema
    {
        return $schema
            ->columns(['default' => 1, 'md' => 2, 'xl' => 3])
            ->components([
                Select::make('costCenterId')->label('Centro di Costo')
                    ->placeholder('Tutti')->options(fn (): array => $this->costCenterOptions())
                    ->native(false)->searchable()->live(),
                Select::make('projectId')->label('Progetto')
                    ->placeholder('Tutti')->options(fn (): array => $this->projectOptions())
                    ->native(false)->searchable()->live(),
                Select::make('contractId')->label('Contratto')
                    ->placeholder('Tutti')->options(fn (): array => $this->contractOptions())
                    ->native(false)->searchable()->live(),
                Select::make('expenseId')->label('Spesa Autonoma')
                    ->placeholder('Tutte')->options(fn (): array => $this->expenseOptions())
                    ->native(false)->searchable()->live(),
                Select::make('supplierId')->label('Fornitore')
                    ->placeholder('Tutti')->options(fn (): array => $this->supplierOptions())
                    ->native(false)->searchable()->live(),
                DateInput::make('dateFrom')->label('Intervallo dal')
                    ->live()
                    ->visible(fn (): bool => $this->kind === ReportKind::Contracts->value),
                DateInput::make('dateTo')->label('Intervallo al')
                    ->live()
                    ->visible(fn (): bool => $this->kind === ReportKind::Contracts->value),
            ]);
    }

    public function updated(string $property): void
    {
        if (! in_array($property, $this->configurationProperties(), true)) {
            return;
        }

        $this->resetErrorBag();

        if ($property === 'kind') {
            $kind = $this->kind === null ? null : ReportKind::tryFrom($this->kind);
            if ($kind instanceof ReportKind) {
                $this->preserveCompatibleContext($kind);
            }
        } elseif ($property === 'exerciseId') {
            $this->resetExerciseReferences();
        } elseif (in_array($property, [
            'budgetId', 'secondBudgetId', 'actualReference', 'comparisonExerciseId',
            'exerciseMeasure', 'dateFrom', 'dateTo',
        ], true) && $this->kind !== null) {
            $kind = ReportKind::tryFrom($this->kind);
            if ($kind instanceof ReportKind) {
                $this->preserveCompatibleContext($kind);
            }
        }

        if ($this->dateIntervalIncomplete()) {
            $this->report = null;
            $this->definition = null;

            return;
        }

        if (! $this->isReportConfigurationComplete()) {
            $this->report = null;
            $this->definition = null;

            return;
        }

        $this->generate();
    }

    public function selectReport(string $kind): void
    {
        $this->switchReport($kind);
    }

    public function switchReport(string $kind): void
    {
        $reportKind = ReportKind::tryFrom($kind);
        abort_unless($reportKind instanceof ReportKind, 404);

        $this->kind = $reportKind->value;
        $this->resetErrorBag();
        $this->preserveCompatibleContext($reportKind);

        if (! $this->isReportConfigurationComplete()) {
            $this->report = null;
            $this->definition = null;

            return;
        }

        $this->generate();
    }

    public function toggleFilters(): void
    {
        $this->filtersOpen = ! $this->filtersOpen;
    }

    public function clearFilters(bool $refresh = true): void
    {
        $this->reset('costCenterId', 'projectId', 'contractId', 'expenseId', 'supplierId', 'dateFrom', 'dateTo', 'comparisonCategory');
        $this->resetErrorBag();

        if ($refresh && $this->isReportConfigurationComplete()) {
            $this->generate();
        }
    }

    public function isReportConfigurationComplete(): bool
    {
        $kind = $this->kind === null ? null : ReportKind::tryFrom($this->kind);
        if ($kind === null || $this->exerciseId === null || $this->dateIntervalIncomplete()) {
            return false;
        }

        return match ($kind) {
            ReportKind::AnnualExecutive => $this->actualReference !== null,
            ReportKind::BudgetActual => $this->budgetId !== null && $this->actualReference !== null,
            ReportKind::BudgetCurrentAllocation => $this->budgetId !== null,
            ReportKind::OperationalVariance, ReportKind::Carryovers, ReportKind::Contracts,
            ReportKind::Projects, ReportKind::Suppliers => true,
            ReportKind::BudgetVersions => $this->budgetId !== null && $this->secondBudgetId !== null,
            ReportKind::Exercises => $this->comparisonExerciseId !== null && $this->exerciseMeasure !== null,
        };
    }

    /** @return array<int, string> */
    public function missingReferences(): array
    {
        if ($this->kind === null) {
            return [];
        }

        $missing = [];
        if ($this->exerciseId === null) {
            $missing[] = 'Esercizio';
        }

        $kind = ReportKind::tryFrom($this->kind);
        if ($kind === null) {
            return $missing;
        }
        if (in_array($kind, [ReportKind::BudgetActual, ReportKind::BudgetCurrentAllocation, ReportKind::BudgetVersions], true)
            && $this->budgetId === null) {
            $missing[] = 'Budget iniziale';
        }
        if ($kind === ReportKind::BudgetVersions && $this->secondBudgetId === null) {
            $missing[] = 'Budget finale';
        }
        if (in_array($kind, [ReportKind::AnnualExecutive, ReportKind::BudgetActual], true) && $this->actualReference === null) {
            $missing[] = 'Tipo di Effettivo';
        }
        if ($kind === ReportKind::Exercises && $this->comparisonExerciseId === null) {
            $missing[] = 'Secondo Esercizio';
        }
        if ($kind === ReportKind::Exercises && $this->exerciseMeasure === null) {
            $missing[] = 'Misura del confronto';
        }

        return $missing;
    }

    public function dateIntervalIncomplete(): bool
    {
        return $this->kind === ReportKind::Contracts->value
            && (($this->dateFrom === null) !== ($this->dateTo === null));
    }

    public function currentKindLabel(): ?string
    {
        return $this->kind === null ? null : ReportKind::tryFrom($this->kind)?->label();
    }

    /** @return array<string, string> */
    public function reportChoices(): array
    {
        return collect(ReportKind::cases())
            ->mapWithKeys(fn (ReportKind $kind): array => [$kind->value => $kind->label()])
            ->all();
    }

    public function reportDescription(string $kind): string
    {
        return [
            ReportKind::AnnualExecutive->value => 'Budget, Allocato, Effettivo e scostamenti dell’Esercizio.',
            ReportKind::BudgetActual->value => 'Confronta una versione Budget con un Effettivo esplicito.',
            ReportKind::BudgetCurrentAllocation->value => 'Confronta il Budget selezionato con l’Allocato Corrente.',
            ReportKind::OperationalVariance->value => 'Confronta Effettivo Corrente e Allocato Corrente.',
            ReportKind::BudgetVersions->value => 'Confronta due versioni Budget dello stesso Esercizio.',
            ReportKind::Exercises->value => 'Confronta la stessa misura fra due Esercizi.',
            ReportKind::Carryovers->value => 'Analizza i Riporti dei Progetti.',
            ReportKind::Contracts->value => 'Analizza valori, scadenze ed eventi dei Contratti.',
            ReportKind::Projects->value => 'Analizza valori, stato, Riporti ed eventi dei Progetti.',
            ReportKind::Suppliers->value => 'Aggrega Allocato ed Effettivo per Fornitore.',
        ][$kind];
    }

    /** @return array<int, string> */
    public function activeFilterLabels(): array
    {
        $labels = [];
        foreach ([
            [$this->costCenterId, 'Centro di Costo', $this->costCenterOptions()],
            [$this->projectId, 'Progetto', $this->projectOptions()],
            [$this->contractId, 'Contratto', $this->contractOptions()],
            [$this->expenseId, 'Spesa autonoma', $this->expenseOptions()],
            [$this->supplierId, 'Fornitore', $this->supplierOptions()],
        ] as [$id, $prefix, $options]) {
            if ($id !== null) {
                $labels[] = $prefix.': '.($options[$id] ?? '#'.$id);
            }
        }
        if ($this->dateFrom !== null && $this->dateTo !== null) {
            $labels[] = 'Intervallo: '.str($this->dateFrom)->before(' ')->toString()
                .' – '.str($this->dateTo)->before(' ')->toString();
        }

        if ($this->comparisonCategory !== null && ($category = ComparisonCategory::tryFrom($this->comparisonCategory)) !== null) {
            $labels[] = 'Categoria visualizzata: '.$category->label();
        }

        return $labels;
    }

    public function activeFilterCount(): int
    {
        return count($this->activeFilterLabels());
    }

    public function generate(): void
    {
        abort_unless(static::canAccess(), 403);
        if (! $this->isReportConfigurationComplete()) {
            return;
        }

        $this->resetErrorBag();

        try {
            if ($this->comparisonCategory !== null && ComparisonCategory::tryFrom($this->comparisonCategory) === null) {
                throw ValidationException::withMessages(['comparisonCategory' => 'Categoria di confronto non valida.']);
            }
            $definition = ReportDefinition::fromArray($this->definitionInput());
            /** @var User $user */
            $user = auth()->user();
            $result = app(BuildReport::class)->execute($user, $definition);
            $this->definition = $definition->toArray();
            $this->report = $this->serializeResult($result);
        } catch (ValidationException $exception) {
            $this->report = null;
            $this->definition = null;
            foreach ($exception->errors() as $field => $messages) {
                $this->addError($this->uiErrorField($field, $messages[0]), $messages[0]);
            }
        } catch (\InvalidArgumentException $exception) {
            $this->report = null;
            $this->definition = null;
            $this->addError($this->uiErrorField('kind', $exception->getMessage()), $exception->getMessage());
        }
    }

    /** @return array<int, string> */
    public function exerciseOptions(): array
    {
        return Exercise::query()->where('company_id', $this->company()->id)->orderByDesc('year')
            ->pluck('year', 'id')->map(fn ($year): string => (string) $year)->all();
    }

    /** @return array<int, string> */
    public function budgetOptions(): array
    {
        if ($this->exerciseId === null) {
            return [];
        }

        return BudgetSnapshot::query()->where('company_id', $this->company()->id)->where('exercise_id', $this->exerciseId)
            ->orderBy('version')->get()->mapWithKeys(fn (BudgetSnapshot $budget): array => [
                $budget->id => 'Budget v'.$budget->version.' · '.$budget->purpose->label(),
            ])->all();
    }

    /** @return array<string, string> */
    public function actualOptions(): array
    {
        return collect(ActualReference::cases())
            ->mapWithKeys(fn (ActualReference $reference): array => [$reference->value => $reference->label()])
            ->all();
    }

    /** @return array<string, string> */
    public function exerciseMeasureOptions(): array
    {
        return [
            'current' => 'Situazione Corrente',
            'closing' => 'Chiusura',
            'current_knowledge' => 'Conoscenza Corrente',
        ];
    }

    /** @return array<int|string, string> */
    public function costCenterOptions(): array
    {
        return ['unclassified' => 'Non classificato']
            + CostCenterHierarchy::forCompany((int) $this->company()->id)->options(activeOnly: false);
    }

    /** @return array<int, string> */
    public function projectOptions(): array
    {
        return Project::query()->where('company_id', $this->company()->id)->orderBy('title')->pluck('title', 'id')->all();
    }

    /** @return array<int, string> */
    public function contractOptions(): array
    {
        return Contract::query()->where('company_id', $this->company()->id)->orderBy('title')->pluck('title', 'id')->all();
    }

    /** @return array<int, string> */
    public function expenseOptions(): array
    {
        if ($this->exerciseId === null) {
            return [];
        }

        return Expense::query()->where('company_id', $this->company()->id)->where('exercise_id', $this->exerciseId)
            ->whereNull('project_id')->whereNull('contract_id')->orderBy('description')->pluck('description', 'id')->all();
    }

    /** @return array<int, string> */
    public function supplierOptions(): array
    {
        return Supplier::query()->where('company_id', $this->company()->id)->orderBy('legal_name')->pluck('legal_name', 'id')->all();
    }

    public function totalLabel(string $key): string
    {
        return [
            'source_count' => 'Sorgenti Primarie',
            'allocation' => 'Allocato del Riferimento',
            'actual' => 'Effettivo del Riferimento',
            'operational_variance' => 'Scostamento Operativo del Riferimento',
            'carryover' => 'Riporto',
            'unclassified' => 'Non Classificato',
            'initial_budget' => 'Budget Iniziale Approvato',
            'current_budget' => 'Budget Approvato Corrente',
            'current_allocation' => 'Allocato Corrente',
            'current_actual' => 'Effettivo Corrente',
            'current_operational_variance' => 'Scostamento Operativo',
            'closing_actual' => 'Effettivo alla Chiusura',
            'late_corrections_positive' => 'Correzioni Tardive Positive',
            'late_corrections_negative' => 'Correzioni Tardive Negative',
            'late_corrections_net' => 'Correzioni Tardive Nette',
            'current_knowledge_actual' => 'Effettivo a Conoscenza Corrente',
            'annotation_count' => 'Annotazioni di Errore Storico',
            'selected_budget' => 'Budget Selezionato',
            'selected_actual' => 'Effettivo Selezionato',
            'allocation_vs_selected_budget' => 'Variazione Allocato vs Budget Selezionato',
            'selected_budget_actual_variance' => 'Varianza Budget vs Actual Selezionato',
        ][$key] ?? str_replace('_', ' ', $key);
    }

    public function sourceTypeLabel(string $type): string
    {
        return ['expense' => 'Spesa Autonoma', 'project' => 'Progetto', 'contract' => 'Contratto', 'carryover' => 'Riporto'][$type] ?? $type;
    }

    public function stateLabel(?string $state): string
    {
        if ($state === null) {
            return '—';
        }

        return [
            'active' => 'Attivo', 'planned' => 'Pianificato', 'open' => 'Aperto', 'closed' => 'Chiuso',
            'cancelled' => 'Cancellato', 'reversed' => 'Stornata', 'terminated' => 'Cessato',
        ][$state] ?? str($state)->replace('_', ' ')->ucfirst()->toString();
    }

    /** @return array<string, mixed> */
    private function definitionInput(): array
    {
        if ($this->exerciseId === null || $this->kind === null) {
            throw ValidationException::withMessages(['exerciseId' => 'Esercizio e famiglia report sono obbligatori.']);
        }
        $input = [
            'company_id' => $this->company()->id,
            'exercise_id' => $this->exerciseId,
            'kind' => $this->kind,
            'date_from' => DateInput::toIso($this->dateFrom),
            'date_to' => DateInput::toIso($this->dateTo),
            'filters' => array_filter([
                'cost_center_id' => $this->costCenterId,
                'project_id' => $this->projectId,
                'contract_id' => $this->contractId,
                'expense_id' => $this->expenseId,
                'supplier_id' => $this->supplierId,
            ], fn (mixed $value): bool => $value !== null),
        ];
        $input = array_filter($input, fn (mixed $value): bool => $value !== null && $value !== '');
        if ($this->kind === ReportKind::AnnualExecutive->value) {
            if ($this->budgetId !== null) {
                $input['initial_reference'] = ['type' => 'budget', 'exercise_id' => $this->exerciseId, 'budget_snapshot_id' => $this->budgetId];
            }
            $input['actual_reference'] = $this->actualReference;
            $input['final_reference'] = ['type' => $this->actualReference, 'exercise_id' => $this->exerciseId];
            if ($this->budgetId === null && $this->actualReference === ActualReference::CurrentKnowledge->value) {
                $exercise = Exercise::query()->where('company_id', $this->company()->id)->findOrFail($this->exerciseId);
                if (! $exercise->isOpen()) {
                    $input['initial_reference'] = ['type' => 'closing', 'exercise_id' => $this->exerciseId];
                }
            }
        } elseif ($this->kind === ReportKind::BudgetActual->value) {
            $input['actual_reference'] = $this->actualReference;
            $input['initial_reference'] = ['type' => 'budget', 'exercise_id' => $this->exerciseId, 'budget_snapshot_id' => $this->budgetId];
            $input['final_reference'] = ['type' => $this->actualReference, 'exercise_id' => $this->exerciseId];
        } elseif ($this->kind === ReportKind::BudgetCurrentAllocation->value) {
            $input['initial_reference'] = ['type' => 'budget', 'exercise_id' => $this->exerciseId, 'budget_snapshot_id' => $this->budgetId];
            $input['final_reference'] = ['type' => 'current', 'exercise_id' => $this->exerciseId];
        } elseif ($this->kind === ReportKind::BudgetVersions->value) {
            $input['initial_reference'] = ['type' => 'budget', 'exercise_id' => $this->exerciseId, 'budget_snapshot_id' => $this->budgetId];
            $input['final_reference'] = ['type' => 'budget', 'exercise_id' => $this->exerciseId, 'budget_snapshot_id' => $this->secondBudgetId];
        } elseif ($this->kind === ReportKind::Exercises->value) {
            $input['comparison_exercise_id'] = $this->comparisonExerciseId;
            $input['initial_reference'] = ['type' => $this->exerciseMeasure, 'exercise_id' => $this->exerciseId];
            $input['final_reference'] = ['type' => $this->exerciseMeasure, 'exercise_id' => $this->comparisonExerciseId];
        }

        return $input;
    }

    /** @return array<string, mixed> */
    private function serializeResult(ReportResult $result): array
    {
        $hideActualSemantics = in_array($result->definition->kind, [
            ReportKind::BudgetCurrentAllocation,
            ReportKind::BudgetVersions,
        ], true);
        $hiddenActualLabels = [
            SecondaryLabel::PlannedNotOccurred,
            SecondaryLabel::WithoutActuals,
            SecondaryLabel::LateCorrection,
        ];
        $source = fn (ReportSource $item): array => [
            'source_type' => $item->sourceType, 'origin_id' => $item->originId,
            'origin_key' => $item->originKey, 'copied_from_origin_key' => $item->copiedFromOriginKey,
            'label' => $item->label, 'summary' => $item->summary,
            'cost_center' => $item->costCenterLabel, 'supplier' => $item->supplierLabel, 'state' => $item->state,
            'allocation' => $item->allocation, 'actual' => $item->actual,
            'operational_variance' => Decimal::subtract($item->actual, $item->allocation),
            'has_actuals' => $item->hasActuals, 'carryover' => $item->carryover,
            'residual' => $item->residual, 'saving' => $item->saving, 'unused' => $item->unused,
            'url' => $this->sourceUrl($item, $result),
            'detail' => $item->detail, 'corrections' => $item->corrections, 'annotations' => $item->annotations,
        ];
        $sources = array_map($source, $result->sources);
        $comparisons = array_map(function (array $row) use ($hideActualSemantics, $hiddenActualLabels, $source): array {
            /** @var ReportSource|null $displaySource */
            $displaySource = $row['final_source'] ?? $row['initial_source'] ?? null;
            $dimensions = $hideActualSemantics
                ? array_values(array_filter($row['dimensions'], fn ($dimension): bool => $dimension->value !== 'actual'))
                : $row['dimensions'];
            $labels = $hideActualSemantics
                ? array_values(array_filter($row['labels'], fn ($label): bool => ! in_array($label, $hiddenActualLabels, true)))
                : $row['labels'];

            return [
                'origin_key' => $row['origin_key'], 'label' => $row['label'],
                'source_type' => $displaySource?->sourceType,
                'origin_id' => $displaySource?->originId,
                'initial_source' => $row['initial_source'] === null ? null : $source($row['initial_source']),
                'final_source' => $row['final_source'] === null ? null : $source($row['final_source']),
                'cost_center' => $displaySource?->costCenterLabel,
                'supplier' => $displaySource?->supplierLabel,
                'state' => $displaySource?->state,
                'initial_value' => $row['initial_value'], 'final_value' => $row['final_value'], 'delta' => $row['delta'],
                'category' => $row['category']->label(), 'category_key' => $row['category']->value,
                'dimensions' => array_map(fn ($dimension): string => $dimension->label(), $dimensions),
                'labels' => array_map(fn ($label): string => $label->label(), $labels),
                'derived_from_origin_key' => $row['derived_from_origin_key'],
                'insufficiently_explained' => $row['insufficiently_explained'],
            ];
        }, $result->comparisons);
        if ($this->comparisonCategory !== null) {
            $comparisons = array_values(array_filter($comparisons, fn (array $row): bool => $row['category_key'] === $this->comparisonCategory));
        }
        $sourcesByKey = collect($result->sources)->keyBy(fn (ReportSource $item): string => $item->originKey);
        $sections = array_map(fn (array $section): array => [
            'title' => $section['title'],
            'rows' => array_map(fn (mixed $row): array => $row instanceof ReportSource ? $source($row) : [
                ...$row,
                'url' => isset($row['key'])
                    ? $this->supplierUrl($row['key'])
                    : $this->sourceUrl($sourcesByKey[$row['origin_key']], $result),
            ], $section['rows']),
        ], $result->sections);
        $comparisonTotals = $this->comparisonTotals($result->comparisons);

        return [
            'header' => $result->header,
            'totals' => $result->totals,
            'sources' => $sources,
            'cost_centers' => array_map(fn (array $row): array => [
                ...$row, 'url' => $this->reportUrl(['costCenterId' => $row['cost_center_id'] ?? 'unclassified']),
            ], $result->costCenters),
            'comparisons' => $comparisons,
            'category_counts' => $result->categoryCounts,
            'label_counts' => $result->labelCounts,
            'category_items' => collect(ComparisonCategory::cases())->map(fn (ComparisonCategory $category): array => [
                'key' => $category->value,
                'label' => $category->label(),
                'count' => (int) ($result->categoryCounts[$category->value] ?? 0),
            ])->filter(fn (array $item): bool => $item['count'] > 0)->values()->all(),
            'label_items' => collect(SecondaryLabel::cases())
                ->reject(fn (SecondaryLabel $label): bool => $hideActualSemantics && in_array($label, $hiddenActualLabels, true))
                ->map(fn (SecondaryLabel $label): array => [
                    'key' => $label->value,
                    'label' => $label->label(),
                    'count' => (int) ($result->labelCounts[$label->value] ?? 0),
                ])->filter(fn (array $item): bool => $item['count'] > 0)->values()->all(),
            'sections' => $sections,
            'comparison_totals' => $comparisonTotals,
            'specialist_totals' => $this->specialistTotals($result->definition->kind, $sections),
            'charts' => $this->charts($result),
        ];
    }

    /** @return array<int, array<string, mixed>> */
    private function charts(ReportResult $result): array
    {
        $charts = app(ReportChartDefinitions::class)->definitions($result);
        $sourcesByKey = collect($result->sources)->keyBy(fn (ReportSource $source): string => $source->originKey);
        foreach ($charts as &$chart) {
            $chart['data']['drilldownUrls'] = match ($chart['id']) {
                'annual-cost-centers' => array_map(fn (array $row): string => $this->reportUrl(['costCenterId' => $row['cost_center_id'] ?? 'unclassified']), $result->costCenters),
                'comparison-categories' => array_map(fn (ComparisonCategory $category): string => $this->reportUrl(['comparisonCategory' => $category->value]), ComparisonCategory::cases()),
                'operational-variance' => array_map(fn (ReportSource $source): ?string => $this->sourceUrl($source, $result), $result->sources),
                'contract-values', 'project-values' => array_values(array_map(
                    fn (ReportSource $source): ?string => $this->sourceUrl($source, $result),
                    array_filter($result->sources, fn (ReportSource $source): bool => $source->sourceType === ($chart['id'] === 'contract-values' ? 'contract' : 'project')),
                )),
                'supplier-values' => array_map(fn (array $row): ?string => $this->supplierUrl($row['key']), $result->sections[0]['rows']),
                'carryover-values' => array_map(fn (array $row): string => $this->reportUrl(['projectId' => $sourcesByKey[$row['origin_key']]->originId]), $result->sections[0]['rows']),
                default => [],
            };
        }
        unset($chart);

        return $charts;
    }

    /** @param array<string, int|string|bool|null> $overrides */
    public function reportUrl(array $overrides = []): string
    {
        $parameters = [];
        foreach ($this->configurationProperties() as $property) {
            $parameters[$property] = $this->{$property};
        }

        return static::getUrl(array_filter(
            [...$parameters, 'auto' => 1, ...$overrides],
            fn (mixed $value): bool => $value !== null && $value !== '',
        ), tenant: $this->company());
    }

    private function sourceUrl(ReportSource $source, ReportResult $result): ?string
    {
        if ($result->definition->kind->isComparison()
            || ($result->definition->finalReference !== null && $result->definition->finalReference->type !== ReferenceType::Current)) {
            return null;
        }

        $resource = match ($source->sourceType) {
            'expense' => ExpenseResource::class,
            'project' => ProjectResource::class,
            'contract' => ContractResource::class,
            default => throw new \UnexpectedValueException('Tipo di sorgente economica non supportato.'),
        };
        if (! auth()->user()?->can('View:'.ucfirst($source->sourceType))) {
            return null;
        }

        return $resource::getUrl('view', ['record' => $source->originId], tenant: $this->company());
    }

    private function supplierUrl(string $key): ?string
    {
        return str_starts_with($key, 'supplier:')
            ? $this->reportUrl(['supplierId' => (int) substr($key, strlen('supplier:'))])
            : null;
    }

    /** @return array<int, string> */
    private function configurationProperties(): array
    {
        return [
            'exerciseId', 'kind', 'budgetId', 'secondBudgetId', 'actualReference',
            'comparisonExerciseId', 'exerciseMeasure', 'dateFrom', 'dateTo',
            'costCenterId', 'projectId', 'contractId', 'expenseId', 'supplierId', 'comparisonCategory',
        ];
    }

    private function preserveCompatibleContext(ReportKind $kind): void
    {
        if (! $kind->isComparison() && $kind !== ReportKind::AnnualExecutive) {
            $this->comparisonCategory = null;
        }
        if (! in_array($kind, [
            ReportKind::AnnualExecutive,
            ReportKind::BudgetActual,
            ReportKind::BudgetCurrentAllocation,
            ReportKind::BudgetVersions,
        ], true) || ! $this->optionExists($this->budgetId, $this->budgetOptions())) {
            $this->budgetId = null;
        }

        if ($kind !== ReportKind::BudgetVersions || ! $this->optionExists($this->secondBudgetId, $this->budgetOptions())) {
            $this->secondBudgetId = null;
        }

        if (! in_array($kind, [ReportKind::AnnualExecutive, ReportKind::BudgetActual], true)
            || $this->actualReference === null
            || ActualReference::tryFrom($this->actualReference) === null) {
            $this->actualReference = null;
        }

        if ($kind !== ReportKind::Exercises) {
            $this->comparisonExerciseId = null;
            $this->exerciseMeasure = null;
        } else {
            if (! $this->optionExists($this->comparisonExerciseId, $this->exerciseOptions())
                || $this->comparisonExerciseId === $this->exerciseId) {
                $this->comparisonExerciseId = null;
            }
            if ($this->exerciseMeasure === null || ! array_key_exists($this->exerciseMeasure, $this->exerciseMeasureOptions())) {
                $this->exerciseMeasure = null;
            }
        }

        if ($kind !== ReportKind::Contracts) {
            $this->reset('dateFrom', 'dateTo');
        }

        foreach ([
            'costCenterId' => $this->costCenterOptions(),
            'projectId' => $this->projectOptions(),
            'contractId' => $this->contractOptions(),
            'expenseId' => $this->expenseOptions(),
            'supplierId' => $this->supplierOptions(),
        ] as $property => $options) {
            if (! $this->optionExists($this->{$property}, $options)) {
                $this->{$property} = null;
            }
        }
    }

    /**
     * @param  array<int|string, string>  $options
     */
    private function optionExists(int|string|null $value, array $options): bool
    {
        return $value !== null && array_key_exists($value, $options);
    }

    /**
     * @param  array<int, array<string, mixed>>  $comparisons
     * @return array{initial: string, final: string, delta: string, source_count: int}
     */
    private function comparisonTotals(array $comparisons): array
    {
        $initial = Decimal::sum(array_column($comparisons, 'initial_value'));
        $final = Decimal::sum(array_column($comparisons, 'final_value'));

        return [
            'initial' => $initial,
            'final' => $final,
            'delta' => Decimal::subtract($final, $initial),
            'source_count' => count($comparisons),
        ];
    }

    /**
     * @param  array<int, array{title: string, rows: array<int, array<string, mixed>>}>  $sections
     * @return array<string, string|int>
     */
    private function specialistTotals(ReportKind $kind, array $sections): array
    {
        if (! in_array($kind, [ReportKind::Suppliers, ReportKind::Contracts, ReportKind::Projects, ReportKind::Carryovers], true)) {
            return [];
        }

        $rows = $sections[0]['rows'] ?? [];

        return [
            'allocation' => Decimal::sum(array_column($rows, 'allocation')),
            'actual' => Decimal::sum(array_column($rows, 'actual')),
            'operational_variance' => Decimal::sum(array_column($rows, 'operational_variance')),
            'carryover' => Decimal::sum(array_column($rows, 'carryover')),
            'item_count' => count($rows),
        ];
    }

    private function resetExerciseReferences(): void
    {
        $this->reset('budgetId', 'secondBudgetId', 'actualReference', 'comparisonExerciseId', 'exerciseMeasure', 'expenseId');
        $this->report = null;
        $this->definition = null;
    }

    private function uiErrorField(string $field, string $message): string
    {
        return match (true) {
            str_contains($message, 'Snapshot di Chiusura'), str_contains($message, 'tipo di Effettivo') => 'actualReference',
            str_contains($message, 'secondo Esercizio'), str_contains($message, 'due Esercizi') => 'comparisonExerciseId',
            str_contains($message, 'stessa misura') => 'exerciseMeasure',
            str_contains($message, 'intervallo'), str_contains($message, 'data iniziale') => 'dateFrom',
            str_contains($message, 'Budget') => $this->kind === ReportKind::BudgetVersions->value && $this->secondBudgetId !== null
                ? 'secondBudgetId'
                : 'budgetId',
            $field === 'reference' => 'kind',
            default => $field,
        };
    }

    private function company(): Company
    {
        $tenant = Filament::getTenant();
        $company = $tenant instanceof TenantCompany ? $tenant->company : null;
        abort_unless($company instanceof Company, 404);

        return $company;
    }
}
