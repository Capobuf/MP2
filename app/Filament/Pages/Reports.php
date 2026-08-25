<?php

namespace App\Filament\Pages;

use App\Actions\Reporting\BuildReport;
use App\Domain\Company\Capability;
use App\Domain\Reporting\ActualReference;
use App\Domain\Reporting\ReportDefinition;
use App\Domain\Reporting\ReportKind;
use App\Domain\Reporting\ReportResult;
use App\Domain\Reporting\ReportSource;
use App\Models\BudgetSnapshot;
use App\Models\Company;
use App\Models\Contract;
use App\Models\CostCenter;
use App\Models\Exercise;
use App\Models\Expense;
use App\Models\Project;
use App\Models\Supplier;
use App\Models\User;
use BackedEnum;
use Filament\Facades\Filament;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Url;

class Reports extends Page
{
    protected string $view = 'filament.pages.reports';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedChartBarSquare;

    protected static ?string $navigationLabel = 'Report';

    protected static string|\UnitEnum|null $navigationGroup = 'Controllo';

    protected static ?string $title = 'Reportistica';

    protected static ?int $navigationSort = 30;

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

    /** @var array<string, mixed>|null */
    public ?array $report = null;

    /** @var array<string, mixed>|null */
    public ?array $definition = null;

    public function mount(): void
    {
        abort_unless(static::canAccess(), 403);

        if ($this->auto) {
            $this->generate();
        }
    }

    public function updated(string $property): void
    {
        if (in_array($property, [
            'exerciseId', 'kind', 'budgetId', 'secondBudgetId', 'actualReference',
            'comparisonExerciseId', 'exerciseMeasure', 'dateFrom', 'dateTo',
            'costCenterId', 'projectId', 'contractId', 'expenseId', 'supplierId',
        ], true)) {
            $this->report = null;
            $this->definition = null;
            $this->resetErrorBag();
        }
    }

    public static function canAccess(): bool
    {
        $user = auth()->user();
        $company = Filament::getTenant();

        return $user instanceof User && $company instanceof Company
            && $user->hasCapability($company, Capability::View);
    }

    public function generate(): void
    {
        abort_unless(static::canAccess(), 403);
        $this->resetErrorBag();
        try {
            $definition = ReportDefinition::fromArray($this->definitionInput());
            /** @var User $user */
            $user = auth()->user();
            $result = app(BuildReport::class)->execute($user, $definition);
            $this->definition = $definition->toArray();
            $this->report = $this->serializeResult($result);
        } catch (ValidationException $exception) {
            foreach ($exception->errors() as $field => $messages) {
                $this->addError($field, $messages[0]);
            }
            $this->report = null;
        } catch (\InvalidArgumentException $exception) {
            $this->addError('kind', $exception->getMessage());
            $this->report = null;
        }
    }

    /** @return array<int, string> */
    public function exerciseOptions(): array
    {
        return Exercise::query()->where('company_id', $this->company()->id)->orderByDesc('year')->pluck('year', 'id')->map(fn ($year): string => (string) $year)->all();
    }

    /** @return array<int, string> */
    public function budgetOptions(): array
    {
        if ($this->exerciseId === null) {
            return [];
        }

        return BudgetSnapshot::query()->where('company_id', $this->company()->id)->where('exercise_id', $this->exerciseId)
            ->orderBy('version')->get()->mapWithKeys(fn (BudgetSnapshot $budget): array => [$budget->id => 'Budget v'.$budget->version.' · '.$budget->purpose->label()])->all();
    }

    /** @return array<string, string> */
    public function kindOptions(): array
    {
        return collect(ReportKind::cases())->mapWithKeys(fn (ReportKind $kind): array => [$kind->value => $kind->label()])->all();
    }

    /** @return array<string, string> */
    public function actualOptions(): array
    {
        return collect(ActualReference::cases())->mapWithKeys(fn (ActualReference $reference): array => [$reference->value => $reference->label()])->all();
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

        return Expense::query()
            ->where('company_id', $this->company()->id)
            ->where('exercise_id', $this->exerciseId)
            ->whereNull('project_id')
            ->whereNull('contract_id')
            ->orderBy('description')
            ->pluck('description', 'id')
            ->all();
    }

    /** @return array<int, string> */
    public function supplierOptions(): array
    {
        return Supplier::query()->where('company_id', $this->company()->id)->orderBy('legal_name')->pluck('legal_name', 'id')->all();
    }

    public function totalLabel(string $key): string
    {
        return [
            'source_count' => 'Sorgenti primarie',
            'allocation' => 'Allocato del riferimento',
            'actual' => 'Effettivo del riferimento',
            'operational_variance' => 'Scostamento Operativo del riferimento',
            'carryover' => 'Riporto',
            'unclassified' => 'Non classificato',
            'initial_budget' => 'Budget iniziale approvato',
            'current_budget' => 'Budget approvato corrente',
            'current_allocation' => 'Allocato Corrente',
            'current_actual' => 'Effettivo Corrente',
            'current_operational_variance' => 'Scostamento Operativo',
            'closing_actual' => 'Effettivo alla Chiusura',
            'late_corrections_positive' => 'Correzioni tardive positive',
            'late_corrections_negative' => 'Correzioni tardive negative',
            'late_corrections_net' => 'Correzioni tardive nette',
            'current_knowledge_actual' => 'Effettivo a Conoscenza Corrente',
            'annotation_count' => 'Annotazioni di errore storico',
            'selected_budget' => 'Budget selezionato',
            'selected_actual' => 'Effettivo selezionato',
            'allocation_vs_selected_budget' => 'Variazione Allocato vs Budget selezionato',
            'selected_budget_actual_variance' => 'Varianza Budget vs Actual selezionato',
        ][$key] ?? str_replace('_', ' ', $key);
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
        if ($this->kind === ReportKind::AnnualExecutive->value && ($this->budgetId !== null || $this->actualReference !== null)) {
            if ($this->budgetId !== null && $this->actualReference === null) {
                throw ValidationException::withMessages(['kind' => 'Il Budget selezionato richiede un tipo di Effettivo esplicito.']);
            }
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
            'source_type' => $item->sourceType, 'origin_key' => $item->originKey, 'label' => $item->label,
            'cost_center' => $item->costCenterLabel, 'supplier' => $item->supplierLabel, 'state' => $item->state,
            'allocation' => $item->allocation, 'actual' => $item->actual, 'carryover' => $item->carryover,
            'residual' => $item->residual, 'saving' => $item->saving, 'unused' => $item->unused,
            'detail' => $item->detail, 'corrections' => $item->corrections, 'annotations' => $item->annotations,
        ];

        return [
            'header' => $result->header,
            'totals' => $result->totals,
            'sources' => array_map($source, $result->sources),
            'comparisons' => array_map(fn (array $row): array => [
                'origin_key' => $row['origin_key'], 'label' => $row['label'],
                'initial_value' => $row['initial_value'], 'final_value' => $row['final_value'], 'delta' => $row['delta'],
                'category' => $row['category']->label(),
                'dimensions' => array_map(fn ($dimension): string => $dimension->label(), $row['dimensions']),
                'labels' => array_map(fn ($label): string => $label->label(), $row['labels']),
                'derived_from_origin_key' => $row['derived_from_origin_key'],
                'insufficiently_explained' => $row['insufficiently_explained'],
            ], $result->comparisons),
            'category_counts' => $result->categoryCounts,
            'label_counts' => $result->labelCounts,
            'sections' => array_map(fn (array $section): array => [
                'title' => $section['title'],
                'rows' => array_map(fn (mixed $row): array => $row instanceof ReportSource ? $source($row) : $row, $section['rows']),
            ], $result->sections),
        ];
    }

    private function company(): Company
    {
        $company = Filament::getTenant();
        abort_unless($company instanceof Company, 404);

        return $company;
    }
}
