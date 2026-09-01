<?php

namespace App\Filament\Pages;

use App\Actions\Reporting\BuildReport;
use App\Domain\Expenses\Decimal;
use App\Domain\Reporting\ActualReference;
use App\Domain\Reporting\ComparisonCategory;
use App\Domain\Reporting\ReportDefinition;
use App\Domain\Reporting\ReportKind;
use App\Domain\Reporting\ReportResult;
use App\Domain\Reporting\ReportSource;
use App\Domain\Reporting\SecondaryLabel;
use App\Models\BudgetSnapshot;
use App\Models\Company;
use App\Models\Contract;
use App\Models\CostCenter;
use App\Models\Exercise;
use App\Models\Expense;
use App\Models\Project;
use App\Models\Supplier;
use App\Models\TenantCompany;
use App\Models\User;
use BackedEnum;
use BezhanSalleh\FilamentShield\Traits\HasPageShield;
use Filament\Facades\Filament;
use Filament\Forms\Components\DatePicker;
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

    public ?int $secondBudgetId = null;

    #[Url]
    public ?string $actualReference = null;

    public ?int $comparisonExerciseId = null;

    public ?string $exerciseMeasure = null;

    public ?string $dateFrom = null;

    public ?string $dateTo = null;

    #[Url]
    public ?int $costCenterId = null;

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
                DatePicker::make('dateFrom')->label('Intervallo dal')
                    ->native(false)->live()
                    ->visible(fn (): bool => $this->kind === ReportKind::Contracts->value),
                DatePicker::make('dateTo')->label('Intervallo al')
                    ->native(false)->live()
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
        $this->reset('costCenterId', 'projectId', 'contractId', 'expenseId', 'supplierId', 'dateFrom', 'dateTo');
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

    /** @return array<int, string> */
    public function costCenterOptions(): array
    {
        return CostCenter::query()->where('company_id', $this->company()->id)->orderBy('name')->pluck('name', 'id')->all();
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
        return ['expense' => 'Spesa Autonoma', 'project' => 'Progetto', 'contract' => 'Contratto'][$type] ?? $type;
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
            'date_from' => $this->dateFrom,
            'date_to' => $this->dateTo,
            'filters' => array_filter([
                'cost_center_id' => $this->costCenterId,
                'project_id' => $this->projectId,
                'contract_id' => $this->contractId,
                'expense_id' => $this->expenseId,
                'supplier_id' => $this->supplierId,
            ], fn (?int $value): bool => $value !== null),
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
        $source = fn (ReportSource $item): array => [
            'source_type' => $item->sourceType, 'origin_id' => $item->originId,
            'origin_key' => $item->originKey, 'label' => $item->label, 'summary' => $item->summary,
            'cost_center' => $item->costCenterLabel, 'supplier' => $item->supplierLabel, 'state' => $item->state,
            'allocation' => $item->allocation, 'actual' => $item->actual,
            'operational_variance' => Decimal::subtract($item->actual, $item->allocation),
            'has_actuals' => $item->hasActuals, 'carryover' => $item->carryover,
            'residual' => $item->residual, 'saving' => $item->saving, 'unused' => $item->unused,
            'detail' => $item->detail, 'corrections' => $item->corrections, 'annotations' => $item->annotations,
        ];
        $sources = array_map($source, $result->sources);
        $comparisons = array_map(fn (array $row): array => [
            'origin_key' => $row['origin_key'], 'label' => $row['label'],
            'initial_value' => $row['initial_value'], 'final_value' => $row['final_value'], 'delta' => $row['delta'],
            'category' => $row['category']->label(), 'category_key' => $row['category']->value,
            'dimensions' => array_map(fn ($dimension): string => $dimension->label(), $row['dimensions']),
            'labels' => array_map(fn ($label): string => $label->label(), $row['labels']),
            'derived_from_origin_key' => $row['derived_from_origin_key'],
            'insufficiently_explained' => $row['insufficiently_explained'],
        ], $result->comparisons);
        $sections = array_map(fn (array $section): array => [
            'title' => $section['title'],
            'rows' => array_map(fn (mixed $row): array => $row instanceof ReportSource ? $source($row) : $row, $section['rows']),
        ], $result->sections);
        $comparisonTotals = $this->comparisonTotals($result->comparisons);

        return [
            'header' => $result->header,
            'totals' => $result->totals,
            'sources' => $sources,
            'comparisons' => $comparisons,
            'category_counts' => $result->categoryCounts,
            'label_counts' => $result->labelCounts,
            'category_items' => collect(ComparisonCategory::cases())->map(fn (ComparisonCategory $category): array => [
                'key' => $category->value,
                'label' => $category->label(),
                'count' => (int) ($result->categoryCounts[$category->value] ?? 0),
            ])->filter(fn (array $item): bool => $item['count'] > 0)->values()->all(),
            'label_items' => collect(SecondaryLabel::cases())->map(fn (SecondaryLabel $label): array => [
                'key' => $label->value,
                'label' => $label->label(),
                'count' => (int) ($result->labelCounts[$label->value] ?? 0),
            ])->filter(fn (array $item): bool => $item['count'] > 0)->values()->all(),
            'sections' => $sections,
            'comparison_totals' => $comparisonTotals,
            'specialist_totals' => $this->specialistTotals($result->definition->kind, $sections),
            'charts' => $this->charts($result, $comparisonTotals),
        ];
    }

    /**
     * @param  array{initial: string, final: string, delta: string, source_count: int}  $comparisonTotals
     * @return array<int, array<string, mixed>>
     */
    private function charts(ReportResult $result, array $comparisonTotals): array
    {
        $charts = [];
        $kind = $result->definition->kind;

        if ($kind === ReportKind::AnnualExecutive) {
            $labels = [];
            $values = [];
            $availability = $result->header['availability'];
            foreach ([
                ['initial_budget', 'Budget Iniziale', (bool) $availability['initial_budget']],
                ['current_budget', 'Budget Corrente', (bool) $availability['current_budget']],
                ['current_allocation', 'Allocato Corrente', true],
                ['selected_actual', (string) $result->header['actual_reference'], true],
            ] as [$key, $label, $available]) {
                if ($available) {
                    $labels[] = $label;
                    $values[] = (float) $result->totals[$key];
                }
            }
            $charts[] = $this->currencyBarChart(
                'annual-summary', 'Sintesi Economica',
                'Riferimenti Economici Esplicitamente Disponibili per l’Esercizio.', $labels, $values,
            );

            $costCenters = [];
            foreach ($result->sources as $source) {
                $key = $source->costCenterId === null ? 'unclassified' : 'cost-center:'.$source->costCenterId;
                $costCenters[$key] ??= ['label' => $source->costCenterLabel ?? 'Non classificato', 'allocation' => '0.00', 'actual' => '0.00'];
                $costCenters[$key]['allocation'] = Decimal::add($costCenters[$key]['allocation'], $source->allocation);
                $costCenters[$key]['actual'] = Decimal::add($costCenters[$key]['actual'], $source->actual);
            }
            if ($costCenters !== []) {
                $charts[] = $this->groupedBarChart(
                    'annual-cost-centers', 'Allocato ed Effettivo per Centro di Costo',
                    'Tutte le Sorgenti del Risultato; Non Classificato Resta un Bucket Esplicito.',
                    array_column($costCenters, 'label'),
                    [
                        ['label' => 'Allocato', 'data' => array_map('floatval', array_column($costCenters, 'allocation')), 'color' => '#39D5C4'],
                        ['label' => (string) $result->header['actual_reference'], 'data' => array_map('floatval', array_column($costCenters, 'actual')), 'color' => '#60A5FA'],
                    ],
                );
            }
            $categoryChart = $this->categoryChart($result);
            if ($categoryChart !== null) {
                $charts[] = $categoryChart;
            }
        } elseif (in_array($kind, [
            ReportKind::BudgetActual, ReportKind::BudgetCurrentAllocation,
            ReportKind::BudgetVersions, ReportKind::Exercises,
        ], true)) {
            $charts[] = $this->currencyBarChart(
                'comparison-totals', 'Confronto Complessivo',
                'Somma Esatta dei Valori Iniziali e Finali delle Sorgenti Confrontate.',
                [
                    (string) ($result->header['initial_reference_label'] ?? $result->header['initial_reference']),
                    (string) ($result->header['final_reference_label'] ?? $result->header['final_reference']),
                ],
                [
                    (float) $comparisonTotals['initial'],
                    (float) $comparisonTotals['final'],
                ],
            );
            $categoryChart = $this->categoryChart($result);
            if ($categoryChart !== null) {
                $charts[] = $categoryChart;
            }
        } elseif ($kind === ReportKind::OperationalVariance && $result->sources !== []) {
            $charts[] = [
                'id' => 'operational-variance', 'heading' => 'Scostamento Operativo per Sorgente',
                'description' => 'Effettivo Corrente meno Allocato Corrente.',
                'type' => 'bar', 'variant' => 'variance-horizontal',
                'data' => [
                    'labels' => array_map(fn (ReportSource $source): string => $source->label, $result->sources),
                    'datasets' => [[
                        'label' => 'Scostamento Operativo',
                        'data' => array_map(fn (ReportSource $source): float => (float) Decimal::subtract($source->actual, $source->allocation), $result->sources),
                        'backgroundColor' => array_map(fn (ReportSource $source): string => match (Decimal::compare(Decimal::subtract($source->actual, $source->allocation), '0.00')) {
                            1 => '#EF4444', -1 => '#60A5FA', default => '#91A3A8',
                        }, $result->sources),
                        'borderRadius' => 5, 'borderSkipped' => false,
                    ]],
                ],
            ];
        } elseif (in_array($kind, [ReportKind::Projects, ReportKind::Contracts], true)) {
            $type = $kind === ReportKind::Projects ? 'project' : 'contract';
            $sources = array_values(array_filter($result->sources, fn (ReportSource $source): bool => $source->sourceType === $type));
            if ($sources !== []) {
                $charts[] = $this->groupedBarChart(
                    $type.'-values', ($kind === ReportKind::Projects ? 'Progetti' : 'Contratti').' · Allocato ed Effettivo',
                    'Valori delle Sorgenti Pertinenti Presenti nel Risultato.',
                    array_map(fn (ReportSource $source): string => $source->label, $sources),
                    [
                        ['label' => 'Allocato', 'data' => array_map(fn (ReportSource $source): float => (float) $source->allocation, $sources), 'color' => '#39D5C4'],
                        ['label' => 'Effettivo', 'data' => array_map(fn (ReportSource $source): float => (float) $source->actual, $sources), 'color' => '#60A5FA'],
                    ],
                );
            }
        } elseif ($kind === ReportKind::Suppliers && ($result->sections[0]['rows'] ?? []) !== []) {
            $rows = $result->sections[0]['rows'];
            $charts[] = $this->groupedBarChart(
                'supplier-values', 'Allocato ed Effettivo per Fornitore',
                'Aggregazione Canonica Già Prodotta dal Report Fornitori.', array_column($rows, 'label'),
                [
                    ['label' => 'Allocato', 'data' => array_map('floatval', array_column($rows, 'allocation')), 'color' => '#39D5C4'],
                    ['label' => 'Effettivo', 'data' => array_map('floatval', array_column($rows, 'actual')), 'color' => '#60A5FA'],
                ],
            );
        } elseif ($kind === ReportKind::Carryovers) {
            $sources = array_values(array_filter($result->sources, fn (ReportSource $source): bool => $source->sourceType === 'project'));
            if ($sources !== []) {
                $charts[] = $this->groupedBarChart(
                    'carryover-values', 'Riporti per Progetto',
                    'Riporto Insieme ad Allocato ed Effettivo Già Disponibili nel Risultato.',
                    array_map(fn (ReportSource $source): string => $source->label, $sources),
                    [
                        ['label' => 'Riporto', 'data' => array_map(fn (ReportSource $source): float => (float) $source->carryover, $sources), 'color' => '#F59E0B'],
                        ['label' => 'Allocato', 'data' => array_map(fn (ReportSource $source): float => (float) $source->allocation, $sources), 'color' => '#39D5C4'],
                        ['label' => 'Effettivo', 'data' => array_map(fn (ReportSource $source): float => (float) $source->actual, $sources), 'color' => '#60A5FA'],
                    ],
                );
            }
        }

        return $charts;
    }

    /** @param array<int, string> $labels
     * @param  array<int, float>  $values
     * @return array<string, mixed>
     */
    private function currencyBarChart(string $id, string $heading, string $description, array $labels, array $values): array
    {
        return [
            'id' => $id, 'heading' => $heading, 'description' => $description,
            'type' => 'bar', 'variant' => 'currency-bar',
            'data' => ['labels' => $labels, 'datasets' => [[
                'label' => 'Importo', 'data' => $values,
                'backgroundColor' => ['#91A3A8', '#39D5C4', '#60A5FA', '#F59E0B'],
                'borderRadius' => 6, 'borderSkipped' => false,
            ]]],
        ];
    }

    /** @param array<int, string> $labels
     * @param  array<int, array{label: string, data: array<int, float>, color: string}>  $series
     * @return array<string, mixed>
     */
    private function groupedBarChart(string $id, string $heading, string $description, array $labels, array $series): array
    {
        return [
            'id' => $id, 'heading' => $heading, 'description' => $description,
            'type' => 'bar', 'variant' => 'grouped-horizontal',
            'data' => [
                'labels' => $labels,
                'datasets' => array_map(fn (array $item): array => [
                    'label' => $item['label'], 'data' => $item['data'], 'backgroundColor' => $item['color'],
                    'borderRadius' => 4, 'borderSkipped' => false,
                ], $series),
            ],
        ];
    }

    /** @return array<string, mixed>|null */
    private function categoryChart(ReportResult $result): ?array
    {
        if ($result->comparisons === [] || array_sum($result->categoryCounts) === 0) {
            return null;
        }
        $categories = ComparisonCategory::cases();

        return [
            'id' => 'comparison-categories', 'heading' => 'Classificazione delle Variazioni',
            'description' => count($result->comparisons).' Sorgenti Primarie Confrontate.',
            'type' => 'doughnut', 'variant' => 'category-doughnut',
            'data' => [
                'labels' => array_map(fn (ComparisonCategory $category): string => $category->label(), $categories),
                'datasets' => [[
                    'label' => 'Sorgenti',
                    'data' => array_map(fn (ComparisonCategory $category): int => (int) ($result->categoryCounts[$category->value] ?? 0), $categories),
                    'backgroundColor' => ['#39D5C4', '#60A5FA', '#EF4444', '#F59E0B'],
                    'borderColor' => '#0B1D25', 'borderWidth' => 3, 'hoverOffset' => 8,
                ]],
            ],
        ];
    }

    /** @return array<int, string> */
    private function configurationProperties(): array
    {
        return [
            'exerciseId', 'kind', 'budgetId', 'secondBudgetId', 'actualReference',
            'comparisonExerciseId', 'exerciseMeasure', 'dateFrom', 'dateTo',
            'costCenterId', 'projectId', 'contractId', 'expenseId', 'supplierId',
        ];
    }

    private function preserveCompatibleContext(ReportKind $kind): void
    {
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
    private function optionExists(?int $value, array $options): bool
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
