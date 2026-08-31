<?php

namespace App\Actions\Reporting;

use App\Domain\Expenses\Decimal;
use App\Domain\Projects\ProjectAnnualReferenceDate;
use App\Domain\Projects\ProjectDeferralMode;
use App\Domain\Reporting\ActualReference;
use App\Domain\Reporting\ComparisonEngine;
use App\Domain\Reporting\ReferenceType;
use App\Domain\Reporting\ReportAggregator;
use App\Domain\Reporting\ReportDefinition;
use App\Domain\Reporting\ReportKind;
use App\Domain\Reporting\ReportReference;
use App\Domain\Reporting\ReportResult;
use App\Domain\Reporting\ReportSource;
use App\Domain\Reporting\SecondaryLabel;
use App\Models\Attachment;
use App\Models\BudgetSnapshot;
use App\Models\BudgetSourceRow;
use App\Models\ClosingSnapshot;
use App\Models\ClosingSourceRow;
use App\Models\Company;
use App\Models\Contract;
use App\Models\CostCenter;
use App\Models\Exercise;
use App\Models\Expense;
use App\Models\ExpenseLine;
use App\Models\HistoricalErrorAnnotation;
use App\Models\LateCorrection;
use App\Models\Project;
use App\Models\ProjectDeferral;
use App\Models\Supplier;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

final class BuildReport
{
    public function __construct(
        private readonly ComparisonEngine $comparisonEngine,
        private readonly ReportAggregator $aggregator,
    ) {}

    public function execute(User $user, ReportDefinition $definition): ReportResult
    {
        $company = Company::query()->findOrFail($definition->companyId);
        if ($company->tenantCompany === null
            || ! $user->canAccessTenant($company->tenantCompany)
            || ! $user->can('View:Reports')) {
            throw new AuthorizationException('Non autorizzato a visualizzare i report di questa Azienda.');
        }

        $exercise = Exercise::query()
            ->where('company_id', $company->id)
            ->findOrFail($definition->exerciseId);
        $comparisonExercise = $definition->comparisonExerciseId === null
            ? null
            : Exercise::query()->where('company_id', $company->id)->findOrFail($definition->comparisonExerciseId);
        $this->assertFilters($company, $exercise, $definition);

        [$initialSources, $finalSources] = $this->sourcesForDefinition($company, $exercise, $comparisonExercise, $definition);
        $initialSources = $this->applyFilters($initialSources, $definition->filters);
        $finalSources = $this->applyFilters($finalSources, $definition->filters);
        $sources = $finalSources !== [] ? $finalSources : $initialSources;
        $comparisons = [];
        if ($definition->kind->isComparison() || ($definition->initialReference !== null && $definition->finalReference !== null)) {
            $comparisons = $this->comparisonEngine->compare(
                $initialSources,
                $finalSources,
                budgetComparison: $definition->initialReference?->type === ReferenceType::Budget,
                exerciseClosed: ! $exercise->isOpen(),
                dateFrom: $definition->dateFrom,
                dateTo: $definition->dateTo,
                initialMeasure: $this->comparisonMeasures($definition)[0],
                finalMeasure: $this->comparisonMeasures($definition)[1],
            );
        }

        $categoryCounts = collect($comparisons)
            ->countBy(fn (array $row): string => $row['category']->value)
            ->all();
        $labelCounts = collect($comparisons)
            ->flatMap(fn (array $row): array => array_map(fn ($label): string => $label->value, $row['labels']))
            ->countBy()
            ->all();
        $totals = $this->aggregator->executive($sources);
        $sections = $this->sections($definition, $sources);

        return new ReportResult(
            $definition,
            $this->header($company, $exercise, $comparisonExercise, $definition),
            $this->annualTotals($company, $exercise, $definition, $totals),
            $sources,
            $comparisons,
            $categoryCounts,
            $labelCounts,
            $sections,
        );
    }

    /** @return array{array<int, ReportSource>, array<int, ReportSource>} */
    private function sourcesForDefinition(Company $company, Exercise $exercise, ?Exercise $comparisonExercise, ReportDefinition $definition): array
    {
        if ($definition->kind->isComparison() || ($definition->initialReference !== null && $definition->finalReference !== null)) {
            return [
                $this->sourcesForReference($company, $definition->initialReference),
                $this->sourcesForReference($company, $definition->finalReference),
            ];
        }

        $reference = $definition->finalReference ?? match ($definition->kind) {
            ReportKind::AnnualExecutive, ReportKind::OperationalVariance, ReportKind::Carryovers,
            ReportKind::Contracts, ReportKind::Projects, ReportKind::Suppliers => new ReportReference(
                ReferenceType::Current,
                $exercise->id,
                referenceDate: ProjectAnnualReferenceDate::forYear(
                    $exercise->year,
                    CarbonImmutable::now($company->timezone),
                ),
            ),
            default => null,
        };

        return [[], $reference === null ? [] : $this->sourcesForReference($company, $reference)];
    }

    /** @return array<int, ReportSource> */
    private function sourcesForReference(Company $company, ?ReportReference $reference): array
    {
        if ($reference === null) {
            throw ValidationException::withMessages(['reference' => 'Il riferimento richiesto è assente.']);
        }
        $exercise = Exercise::query()->where('company_id', $company->id)->findOrFail($reference->exerciseId);

        return match ($reference->type) {
            ReferenceType::Budget => $this->budgetSources($company, $exercise, $reference),
            ReferenceType::Current => $this->currentSources($company, $exercise, $reference),
            ReferenceType::Closing => $this->closingSources($company, $exercise, false),
            ReferenceType::CurrentKnowledge => $exercise->isOpen()
                ? $this->currentSources($company, $exercise, $reference)
                : $this->closingSources($company, $exercise, true),
        };
    }

    /** @return array<int, ReportSource> */
    private function budgetSources(Company $company, Exercise $exercise, ReportReference $reference): array
    {
        $budget = BudgetSnapshot::query()
            ->where('company_id', $company->id)
            ->where('exercise_id', $exercise->id)
            ->with('rows')
            ->findOrFail($reference->budgetSnapshotId);

        return $budget->rows->map(fn (BudgetSourceRow $row): ReportSource => new ReportSource(
            sourceType: $row->source_type->value,
            originId: $row->origin_id,
            originKey: $row->origin_key,
            copiedFromOriginKey: $row->copied_from_origin_key,
            label: $row->label,
            summary: $row->summary,
            supplierId: $row->supplier_id,
            supplierLabel: $row->supplier_label,
            costCenterId: $row->cost_center_id,
            costCenterLabel: $row->cost_center_label,
            state: $row->end_state,
            allocation: (string) $row->approved_allocation,
            actual: '0.00',
            hasActuals: false,
            carryover: (string) $row->approved_carryover,
            detail: $row->detail,
        ))->all();
    }

    /** @return array<int, ReportSource> */
    private function closingSources(Company $company, Exercise $exercise, bool $currentKnowledge): array
    {
        $snapshot = ClosingSnapshot::query()
            ->where('company_id', $company->id)
            ->where('exercise_id', $exercise->id)
            ->with(['rows', 'lateCorrections.expenseLine', 'historicalErrorAnnotations'])
            ->first();
        if ($snapshot === null) {
            throw ValidationException::withMessages(['reference' => 'La Snapshot di Chiusura richiesta non esiste.']);
        }

        $corrections = $snapshot->lateCorrections->groupBy('source_origin_key');
        $annotations = $snapshot->historicalErrorAnnotations;

        return $snapshot->rows->map(function (ClosingSourceRow $row) use ($currentKnowledge, $corrections, $annotations): ReportSource {
            /** @var Collection<int, LateCorrection> $rowCorrections */
            $rowCorrections = $corrections->get($row->origin_key, collect());
            $correctionRows = $rowCorrections->map(fn (LateCorrection $correction): array => [
                'id' => $correction->id,
                'amount' => (string) $correction->expenseLine->amount,
                'reason' => $correction->reason,
                'source_label' => $correction->source_label,
                'created_at' => $correction->created_at->toISOString(),
            ])->values()->all();
            $net = Decimal::sum($rowCorrections->map(fn (LateCorrection $correction): string => (string) $correction->expenseLine->amount));
            $rowAnnotations = $annotations->filter(fn (HistoricalErrorAnnotation $annotation): bool => collect($this->affectedSources($annotation))
                ->contains(fn (array $source): bool => ($source['origin_key'] ?? null) === $row->origin_key))
                ->map(fn (HistoricalErrorAnnotation $annotation): array => [
                    'id' => $annotation->id,
                    'kind' => (string) $annotation->getRawOriginal('kind'),
                    'reason' => $annotation->reason,
                    'economic_impact' => '0.00',
                    'affected_sources' => $this->affectedSources($annotation),
                ])->values()->all();
            $actual = $currentKnowledge ? Decimal::add((string) $row->closing_actual, $net) : (string) $row->closing_actual;
            $detail = $row->getAttribute('detail');
            $detail = is_array($detail) ? $detail : [];

            return new ReportSource(
                sourceType: (string) $row->getRawOriginal('source_type'),
                originId: $row->origin_id,
                originKey: $row->origin_key,
                copiedFromOriginKey: $row->copied_from_origin_key,
                label: $row->label,
                summary: $row->summary,
                supplierId: $row->supplier_id,
                supplierLabel: $row->supplier_label,
                costCenterId: $row->cost_center_id,
                costCenterLabel: $row->cost_center_label,
                state: $row->end_state,
                allocation: (string) $row->final_allocation,
                actual: $actual,
                hasActuals: (bool) $row->has_actuals,
                carryover: (string) ($detail['consolidated_carryover'] ?? '0.00'),
                residual: (string) ($detail['residual'] ?? '0.00'),
                saving: (string) ($detail['saving'] ?? '0.00'),
                unused: (string) ($detail['unused_allocation'] ?? '0.00'),
                detail: $detail,
                corrections: $currentKnowledge ? $correctionRows : [],
                annotations: $rowAnnotations,
            );
        })->all();
    }

    /** @return array<int, ReportSource> */
    private function currentSources(Company $company, Exercise $exercise, ReportReference $reference): array
    {
        $date = $reference->referenceDate ?? ProjectAnnualReferenceDate::forYear(
            $exercise->year,
            CarbonImmutable::now($company->timezone),
        );
        $sources = [];
        $expenses = Expense::query()
            ->where('company_id', $company->id)
            ->where('exercise_id', $exercise->id)
            ->whereNull('project_id')->whereNull('contract_id')
            ->with(['lines.attachments', 'supplier', 'directCostCenter'])
            ->orderBy('id')->get();
        foreach ($expenses as $expense) {
            $sources[] = $this->expenseSource($expense);
        }

        $projects = Project::query()->where('company_id', $company->id)
            ->with(['transitions', 'deferrals', 'classifications.costCenter', 'expenses' => fn ($query) => $query->where('exercise_id', $exercise->id)->with(['lines.attachments', 'supplier', 'directCostCenter'])])
            ->orderBy('id')->get();
        foreach ($projects as $project) {
            $totals = $this->loadedExpenseTotals($project->expenses);
            $incomingCarryover = Decimal::sum($project->deferrals
                ->filter(fn (ProjectDeferral $deferral): bool => (int) $deferral->destination_exercise_id === $exercise->id
                    && $deferral->mode === ProjectDeferralMode::Carryover)
                ->pluck('carryover_amount'));
            $totals['allocation'] = Decimal::add($totals['allocation'], $incomingCarryover);
            $classification = $project->classifications->firstWhere('exercise_id', $exercise->id);
            $carryover = Decimal::sum($project->deferrals
                ->filter(fn (ProjectDeferral $deferral): bool => (int) $deferral->source_exercise_id === $exercise->id
                    && $deferral->mode === ProjectDeferralMode::Carryover)
                ->pluck('carryover_amount'));
            $state = $project->stateAtDate($date->toDateString());
            if ($state === null
                && Decimal::compare((string) $totals['allocation'], '0.00') === 0
                && ! (bool) $totals['has_actuals']
                && Decimal::compare($carryover, '0.00') === 0) {
                continue;
            }
            $balance = Decimal::subtract((string) $totals['allocation'], (string) $totals['actual']);
            $sources[] = new ReportSource(
                sourceType: 'project', originId: $project->id, originKey: $project->originKey(), copiedFromOriginKey: null,
                label: $project->title, summary: $project->description, supplierId: null, supplierLabel: null,
                costCenterId: $classification?->cost_center_id, costCenterLabel: $classification?->costCenter?->name,
                state: $state?->value,
                allocation: (string) $totals['allocation'], actual: (string) $totals['actual'], hasActuals: (bool) $totals['has_actuals'],
                carryover: $carryover,
                residual: in_array($state?->value, ['planned', 'open'], true) ? $balance : '0.00',
                saving: $state?->value === 'closed' ? $balance : '0.00',
                unused: $state?->value === 'cancelled' ? $balance : '0.00',
                detail: [
                    'expenses' => $project->expenses->map(fn (Expense $expense): array => $this->expenseDetail($expense))->all(),
                    'transitions' => $project->transitions->map(fn ($transition): array => $transition->toArray())->all(),
                    'deferrals' => $project->deferrals->map(fn ($deferral): array => $deferral->toArray())->all(),
                    'residual' => in_array($state?->value, ['planned', 'open'], true) ? $balance : '0.00',
                    'saving' => $state?->value === 'closed' ? $balance : '0.00',
                    'unused_allocation' => $state?->value === 'cancelled' ? $balance : '0.00',
                    'archived_or_reversed' => $project->isArchived(),
                    'deferred' => $carryover !== '0.00',
                ],
            );
        }

        $contracts = Contract::query()->where('company_id', $company->id)
            ->with(['supplier', 'conditions', 'lifecycleFacts', 'renewalConfigurations', 'classifications.costCenter', 'expenses' => fn ($query) => $query->where('exercise_id', $exercise->id)->with(['lines.attachments', 'supplier', 'directCostCenter'])])
            ->orderBy('id')->get();
        foreach ($contracts as $contract) {
            $totals = $this->loadedExpenseTotals($contract->expenses);
            $classification = $contract->classifications->firstWhere('exercise_id', $exercise->id);
            $state = $contract->stateAtDate($date->toDateString());
            $sources[] = new ReportSource(
                sourceType: 'contract', originId: $contract->id, originKey: $contract->originKey(), copiedFromOriginKey: null,
                label: $contract->title, summary: $contract->notes, supplierId: $contract->supplier_id, supplierLabel: $contract->supplier?->legal_name,
                costCenterId: $classification?->cost_center_id, costCenterLabel: $classification?->costCenter?->name,
                state: $state->value,
                allocation: (string) $totals['allocation'], actual: (string) $totals['actual'], hasActuals: (bool) $totals['has_actuals'],
                detail: [
                    'expenses' => $contract->expenses->map(fn (Expense $expense): array => $this->expenseDetail($expense))->all(),
                    'conditions' => $contract->conditions->map(fn ($condition): array => $condition->toArray())->all(),
                    'cycles' => $contract->renewalConfigurations->map(fn ($cycle): array => $cycle->toArray())->all(),
                    'events' => $contract->lifecycleFacts->map(fn ($event): array => $event->toArray())->all(),
                    'deadline' => $contract->nextExpiryDate()?->toDateString(),
                    'automatic_renewal' => (bool) $contract->automatic_renewal,
                    'notice_limit_date' => $contract->nextExpiryDate() !== null && $contract->notice_days !== null
                        ? $contract->nextExpiryDate()->subDays((int) $contract->notice_days)->toDateString()
                        : null,
                    'operational_variance' => Decimal::subtract((string) $totals['actual'], (string) $totals['allocation']),
                    'archived_or_reversed' => $contract->isArchived(),
                ],
            );
        }

        return $sources;
    }

    /**
     * @param  Collection<int, Expense>  $expenses
     * @return array{allocation: string, actual: string, has_actuals: bool}
     */
    private function loadedExpenseTotals(Collection $expenses): array
    {
        return [
            'allocation' => Decimal::sum($expenses->map(fn (Expense $expense): string => $expense->allocation())),
            'actual' => Decimal::sum($expenses->map(fn (Expense $expense): string => $expense->actual())),
            'has_actuals' => $expenses->contains(fn (Expense $expense): bool => $expense->hasActuals()),
        ];
    }

    private function expenseSource(Expense $expense): ReportSource
    {
        return new ReportSource(
            sourceType: 'expense', originId: $expense->id, originKey: $expense->originKey(), copiedFromOriginKey: $expense->copied_from_origin_key,
            label: $expense->description, summary: $expense->notes, supplierId: $expense->supplier_id, supplierLabel: $expense->supplier?->legal_name,
            costCenterId: $expense->direct_cost_center_id, costCenterLabel: $expense->directCostCenter?->name,
            state: $expense->isReversed() ? 'reversed' : 'active', allocation: $expense->allocation(), actual: $expense->actual(), hasActuals: $expense->hasActuals(),
            detail: [...$this->expenseDetail($expense), 'archived_or_reversed' => $expense->isReversed()],
        );
    }

    /** @return array<string, mixed> */
    private function expenseDetail(Expense $expense): array
    {
        return [
            'id' => $expense->id,
            'source' => $expense->description,
            'supplier_id' => $expense->supplier_id,
            'supplier_label' => $expense->supplier?->legal_name,
            'allocation' => $expense->allocation(),
            'actual' => $expense->actual(),
            'has_actuals' => $expense->hasActuals(),
            'lines' => $expense->lines->map(fn (ExpenseLine $line): array => [
                'id' => $line->id,
                'type' => $line->lineType()->value,
                'amount' => (string) $line->amount,
                'note' => $line->note,
                'annulled' => $line->isAnnulled(),
                'attachments' => $line->attachments->map(fn (Attachment $attachment): array => ['id' => $attachment->id, 'name' => $attachment->original_name])->all(),
            ])->all(),
        ];
    }

    /**
     * @param  array<string, string|int>  $sourceTotals
     * @return array<string, string|int>
     */
    private function annualTotals(Company $company, Exercise $exercise, ReportDefinition $definition, array $sourceTotals): array
    {
        $budgets = BudgetSnapshot::query()->where('company_id', $company->id)->where('exercise_id', $exercise->id)->orderBy('version')->get();
        $closing = ClosingSnapshot::query()->where('company_id', $company->id)->where('exercise_id', $exercise->id)->first();
        $corrections = LateCorrection::query()->where('company_id', $company->id)->where('exercise_id', $exercise->id)->with('expenseLine')->get();
        $positive = Decimal::sum($corrections->filter(fn (LateCorrection $item): bool => Decimal::compare((string) $item->expenseLine->amount, '0.00') > 0)->map(fn (LateCorrection $item): string => (string) $item->expenseLine->amount));
        $negative = Decimal::sum($corrections->filter(fn (LateCorrection $item): bool => Decimal::compare((string) $item->expenseLine->amount, '0.00') < 0)->map(fn (LateCorrection $item): string => (string) $item->expenseLine->amount));
        $net = Decimal::add($positive, $negative);

        $initialBudget = $budgets->first();
        $currentBudget = $budgets->last();
        $closingActual = $closing instanceof ClosingSnapshot ? (string) $closing->total_closing_actual : $exercise->actual();
        $selectedBudget = $definition->initialReference?->budgetSnapshotId === null
            ? null
            : BudgetSnapshot::query()->where('company_id', $company->id)->where('exercise_id', $exercise->id)->find($definition->initialReference->budgetSnapshotId);
        $selectedBudgetAmount = $selectedBudget instanceof BudgetSnapshot ? (string) $selectedBudget->total_approved_allocation : '0.00';
        $selectedActual = match ($definition->actualReference) {
            ActualReference::Current => $exercise->actual(),
            ActualReference::Closing => $closing instanceof ClosingSnapshot ? (string) $closing->total_closing_actual : '0.00',
            ActualReference::CurrentKnowledge => Decimal::add($closingActual, $net),
            null => '0.00',
        };

        return [
            ...$sourceTotals,
            'initial_budget' => $initialBudget instanceof BudgetSnapshot ? (string) $initialBudget->total_approved_allocation : '0.00',
            'current_budget' => $currentBudget instanceof BudgetSnapshot ? (string) $currentBudget->total_approved_allocation : '0.00',
            'current_allocation' => $exercise->allocation(),
            'current_actual' => $exercise->actual(),
            'current_operational_variance' => $exercise->operationalVariance(),
            'closing_actual' => $closing instanceof ClosingSnapshot ? (string) $closing->total_closing_actual : '0.00',
            'late_corrections_positive' => $positive,
            'late_corrections_negative' => $negative,
            'late_corrections_net' => $net,
            'current_knowledge_actual' => Decimal::add($closingActual, $net),
            'annotation_count' => HistoricalErrorAnnotation::query()->where('company_id', $company->id)->where('exercise_id', $exercise->id)->count(),
            'selected_budget' => $selectedBudgetAmount,
            'selected_actual' => $selectedActual,
            'allocation_vs_selected_budget' => $selectedBudget instanceof BudgetSnapshot ? Decimal::subtract($exercise->allocation(), $selectedBudgetAmount) : '0.00',
            'selected_budget_actual_variance' => $selectedBudget instanceof BudgetSnapshot && $definition->actualReference !== null
                ? Decimal::subtract($selectedActual, $selectedBudgetAmount)
                : '0.00',
        ];
    }

    /** @return array<string, mixed> */
    private function header(Company $company, Exercise $exercise, ?Exercise $comparisonExercise, ReportDefinition $definition): array
    {
        $budgetId = $definition->initialReference instanceof ReportReference
            ? $definition->initialReference->budgetSnapshotId
            : $definition->finalReference?->budgetSnapshotId;
        $budget = $budgetId === null ? null : BudgetSnapshot::query()->where('company_id', $company->id)->findOrFail($budgetId);
        $budgets = BudgetSnapshot::query()
            ->where('company_id', $company->id)
            ->where('exercise_id', $exercise->id)
            ->orderBy('version')
            ->get();
        $closingExists = ClosingSnapshot::query()
            ->where('company_id', $company->id)
            ->where('exercise_id', $exercise->id)
            ->exists();

        return [
            'company_id' => $company->id,
            'company_name' => $company->name,
            'exercise_id' => $exercise->id,
            'exercise_year' => $exercise->year,
            'comparison_exercise_year' => $comparisonExercise?->year,
            'kind' => $definition->kind->value,
            'title' => $definition->kind->label(),
            'initial_reference' => $definition->initialReference?->type->label(),
            'final_reference' => $definition->finalReference?->type->label(),
            'initial_reference_label' => $this->referenceLabel($company, $definition->initialReference),
            'final_reference_label' => $this->referenceLabel($company, $definition->finalReference),
            'budget_version' => $budget?->version,
            'budget_purpose' => $budget?->purpose->label(),
            'initial_budget_label' => $budgets->first() instanceof BudgetSnapshot
                ? 'Budget v'.$budgets->first()->version.' · '.$budgets->first()->purpose->label()
                : null,
            'current_budget_label' => $budgets->last() instanceof BudgetSnapshot
                ? 'Budget v'.$budgets->last()->version.' · '.$budgets->last()->purpose->label()
                : null,
            'availability' => [
                'initial_budget' => $budgets->isNotEmpty(),
                'current_budget' => $budgets->isNotEmpty(),
                'selected_budget' => $budget instanceof BudgetSnapshot,
                'closing' => $closingExists,
            ],
            'actual_reference' => $definition->actualReference?->label(),
            'reference_date' => ProjectAnnualReferenceDate::forYear($exercise->year, CarbonImmutable::now($company->timezone))->toDateString(),
            'generated_at' => $definition->generatedAt->setTimezone($company->timezone)->toDateTimeString(),
            'date_from' => $definition->dateFrom?->toDateString(),
            'date_to' => $definition->dateTo?->toDateString(),
            'filters' => $definition->filters,
            'filter_labels' => $this->filterLabels($company, $definition),
            'currency' => 'EUR',
            'amount_basis' => 'Importi netti IVA',
        ];
    }

    private function referenceLabel(Company $company, ?ReportReference $reference): ?string
    {
        if ($reference === null) {
            return null;
        }

        $exercise = Exercise::query()
            ->where('company_id', $company->id)
            ->findOrFail($reference->exerciseId);

        if ($reference->type !== ReferenceType::Budget) {
            $label = match ($reference->type) {
                ReferenceType::Current => 'Situazione Corrente',
                ReferenceType::Closing => 'Snapshot di Chiusura',
                ReferenceType::CurrentKnowledge => 'Effettivo a Conoscenza Corrente',
            };

            return $label.' · Esercizio '.$exercise->year;
        }

        $budget = BudgetSnapshot::query()
            ->where('company_id', $company->id)
            ->where('exercise_id', $exercise->id)
            ->findOrFail($reference->budgetSnapshotId);

        return 'Budget v'.$budget->version.' · '.$budget->purpose->label().' · Esercizio '.$exercise->year;
    }

    /**
     * @param  array<int, ReportSource>  $sources
     * @return array<int, array<string, mixed>>
     */
    private function sections(ReportDefinition $definition, array $sources): array
    {
        return match ($definition->kind) {
            ReportKind::Suppliers => [['title' => 'Aggregazione per Fornitore', 'rows' => $this->aggregator->suppliers($sources)]],
            ReportKind::Contracts => [['title' => 'Contratti', 'rows' => $this->contractRows($definition, $sources)]],
            ReportKind::Projects => [['title' => 'Progetti', 'rows' => array_values(array_filter($sources, fn (ReportSource $source): bool => $source->sourceType === 'project'))]],
            ReportKind::Carryovers => [['title' => 'Riporti', 'rows' => array_values(array_filter($sources, fn (ReportSource $source): bool => $source->sourceType === 'project'))]],
            default => [],
        };
    }

    /**
     * @param  array<int, ReportSource>  $sources
     * @return array<int, array<string, mixed>>
     */
    private function contractRows(ReportDefinition $definition, array $sources): array
    {
        return collect($sources)
            ->filter(fn (ReportSource $source): bool => $source->sourceType === 'contract')
            ->map(function (ReportSource $source) use ($definition): array {
                $deadline = isset($source->detail['deadline'])
                    ? CarbonImmutable::parse((string) $source->detail['deadline'])
                    : null;
                $labels = [];
                if ($deadline === null) {
                    $labels[] = SecondaryLabel::UndefinedExpiry->label();
                } elseif ($definition->dateFrom !== null && $definition->dateTo !== null
                    && $deadline->betweenIncluded($definition->dateFrom, $definition->dateTo)) {
                    $labels[] = SecondaryLabel::ContractExpiryInSelectedInterval->label();
                }

                return [
                    'label' => $source->label,
                    'origin_key' => $source->originKey,
                    'state' => $source->state,
                    'allocation' => $source->allocation,
                    'actual' => $source->actual,
                    'operational_variance' => Decimal::subtract($source->actual, $source->allocation),
                    'labels' => $labels,
                    'detail' => $source->detail,
                ];
            })
            ->values()
            ->all();
    }

    /** @return array<int, string> */
    private function filterLabels(Company $company, ReportDefinition $definition): array
    {
        return collect($definition->filters)->map(function (int|string|null $id, string $key) use ($company): ?string {
            if ($id === null) {
                return null;
            }

            return match ($key) {
                'cost_center_id' => 'Centro di Costo: '.CostCenter::query()->where('company_id', $company->id)->findOrFail($id)->name,
                'project_id' => 'Progetto: '.Project::query()->where('company_id', $company->id)->findOrFail($id)->title,
                'contract_id' => 'Contratto: '.Contract::query()->where('company_id', $company->id)->findOrFail($id)->title,
                'expense_id' => 'Spesa autonoma: '.Expense::query()->where('company_id', $company->id)->findOrFail($id)->description,
                'supplier_id' => 'Fornitore: '.Supplier::query()->where('company_id', $company->id)->findOrFail($id)->legal_name,
                default => null,
            };
        })->filter()->values()->all();
    }

    private function assertFilters(Company $company, Exercise $exercise, ReportDefinition $definition): void
    {
        $models = [
            'cost_center_id' => CostCenter::class,
            'project_id' => Project::class,
            'contract_id' => Contract::class,
            'expense_id' => Expense::class,
            'supplier_id' => Supplier::class,
        ];
        foreach ($definition->filters as $key => $id) {
            if ($id === null) {
                continue;
            }
            $model = $models[$key];
            $query = $model::query()->where('company_id', $company->id);
            if ($key === 'expense_id') {
                $query->where('exercise_id', $exercise->id);
            }
            if ($query->find($id) === null) {
                throw (new ModelNotFoundException)->setModel($model, [$id]);
            }
        }
    }

    /**
     * @param  array<int, ReportSource>  $sources
     * @param  array<string, int|string|null>  $filters
     * @return array<int, ReportSource>
     */
    private function applyFilters(array $sources, array $filters): array
    {
        return array_values(array_filter($sources, function (ReportSource $source) use ($filters): bool {
            foreach ($filters as $key => $value) {
                if ($value === null) {
                    continue;
                }
                $id = (int) $value;
                $expenseDetails = $source->detail['expenses'] ?? [];
                /** @var array<int, array<string, mixed>> $expenseDetails */
                $expenseDetails = is_array($expenseDetails) ? $expenseDetails : [];
                $expenses = collect($expenseDetails);
                $matches = match ($key) {
                    'cost_center_id' => $source->costCenterId === $id,
                    'project_id' => $source->sourceType === 'project' && $source->originId === $id,
                    'contract_id' => $source->sourceType === 'contract' && $source->originId === $id,
                    'expense_id' => ($source->sourceType === 'expense' && $source->originId === $id)
                        || $expenses->contains(fn (array $expense): bool => (int) ($expense['id'] ?? 0) === $id),
                    'supplier_id' => $source->supplierId === $id
                        || $expenses->contains(fn (array $expense): bool => (int) ($expense['supplier_id'] ?? 0) === $id),
                    default => false,
                };
                if (! $matches) {
                    return false;
                }
            }

            return true;
        }));
    }

    /** @return array{string, string} */
    private function comparisonMeasures(ReportDefinition $definition): array
    {
        return match ($definition->kind) {
            ReportKind::BudgetActual => ['allocation', 'actual'],
            ReportKind::BudgetCurrentAllocation, ReportKind::BudgetVersions => ['allocation', 'allocation'],
            ReportKind::Exercises => $definition->initialReference?->type === ReferenceType::Budget
                ? ['allocation', 'allocation']
                : ['actual', 'actual'],
            default => ['automatic', 'automatic'],
        };
    }

    /** @return array<int, array<string, mixed>> */
    private function affectedSources(HistoricalErrorAnnotation $annotation): array
    {
        $sources = $annotation->getAttribute('affected_sources');

        return is_array($sources) ? $sources : [];
    }
}
