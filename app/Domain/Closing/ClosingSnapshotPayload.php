<?php

namespace App\Domain\Closing;

use App\Domain\Contracts\ContractAnnualAllocation;
use App\Domain\Contracts\ContractRenewalSchedule;
use App\Domain\Contracts\ContractState;
use App\Domain\Expenses\Decimal;
use App\Domain\Projects\ProjectDeferralMode;
use App\Domain\Projects\ProjectDeferralValues;
use App\Domain\Projects\ProjectState;
use App\Models\BudgetSnapshot;
use App\Models\Contract;
use App\Models\ContractCondition;
use App\Models\ContractLifecycleFact;
use App\Models\ContractRenewalConfiguration;
use App\Models\Exercise;
use App\Models\Expense;
use App\Models\ExpenseLine;
use App\Models\Project;
use App\Models\ProjectContractLink;
use App\Models\ProjectDeferral;
use App\Models\ProjectTransition;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

final class ClosingSnapshotPayload
{
    /**
     * @param  list<array<string, mixed>>  $projectDecisions
     * @param  array<string, list<array{operation_id: string, event_sequence: int}>>  $eventReferences
     * @return array{rows: list<array<string, mixed>>, total_final_allocation: string, total_closing_actual: string, total_operational_variance: string, total_consolidated_carryover: string}
     */
    public static function build(Exercise $exercise, array $projectDecisions, array $eventReferences = []): array
    {
        $exercise->loadMissing('company');
        $yearStart = CarbonImmutable::create($exercise->year, 1, 1, 0, 0, 0, $exercise->company->timezone);
        $yearEnd = CarbonImmutable::create($exercise->year, 12, 31, 0, 0, 0, $exercise->company->timezone);
        $budgets = BudgetSnapshot::query()->where('exercise_id', $exercise->id)->with('rows')->orderBy('version')->get();
        /** @var Collection<string, int> $budgetOriginKeys */
        $budgetOriginKeys = $budgets->flatMap->rows->pluck('origin_key')->unique()->flip();
        $decisions = collect($projectDecisions)->keyBy('project_id');
        $rows = [];

        $standaloneExpenses = Expense::query()
            ->where('company_id', $exercise->company_id)
            ->where('exercise_id', $exercise->id)
            ->whereNull('project_id')
            ->whereNull('contract_id')
            ->with(['lines', 'supplier', 'project', 'contract', 'directCostCenter'])
            ->orderBy('id')
            ->get();
        foreach ($standaloneExpenses as $expense) {
            if (! self::includeStandalone($expense, $budgetOriginKeys, $exercise->year)) {
                continue;
            }
            $rows[] = self::expenseRow($expense, $eventReferences[$expense->originKey()] ?? []);
        }

        $projects = Project::query()
            ->where('company_id', $exercise->company_id)
            ->with([
                'transitions',
                'classifications.costCenter',
                'deferrals',
                'expenses.lines',
                'expenses.supplier',
                'expenses.project.classifications.costCenter',
                'expenses.contract.classifications.costCenter',
                'expenses.directCostCenter',
            ])
            ->orderBy('id')
            ->get();
        foreach ($projects as $project) {
            $decision = $decisions->get($project->id);
            $decision = is_array($decision) ? $decision : null;
            if (! self::includeProject($project, $exercise, $budgetOriginKeys, $yearStart, $yearEnd, $decision)) {
                continue;
            }
            $rows[] = self::projectRow(
                $project,
                $exercise,
                $yearStart,
                $yearEnd,
                $decision,
                $eventReferences[$project->originKey()] ?? [],
            );
        }

        $contracts = Contract::query()
            ->where('company_id', $exercise->company_id)
            ->with([
                'supplier',
                'conditions',
                'lifecycleFacts.renewalConfiguration',
                'renewalConfigurations',
                'classifications.costCenter',
                'expenses.lines',
                'expenses.supplier',
                'expenses.project.classifications.costCenter',
                'expenses.contract.classifications.costCenter',
                'expenses.directCostCenter',
            ])
            ->orderBy('id')
            ->get();
        foreach ($contracts as $contract) {
            if (! self::includeContract($contract, $exercise, $budgetOriginKeys, $yearStart, $yearEnd)) {
                continue;
            }
            $rows[] = self::contractRow(
                $contract,
                $exercise,
                $yearStart,
                $yearEnd,
                $eventReferences[$contract->originKey()] ?? [],
            );
        }

        $allocation = Decimal::sum(collect($rows)->pluck('final_allocation'));
        $actual = Decimal::sum(collect($rows)->pluck('closing_actual'));
        $variance = Decimal::subtract($actual, $allocation);
        $consolidatedCarryover = Decimal::sum(ProjectDeferral::query()
            ->where('source_exercise_id', $exercise->id)
            ->where('mode', ProjectDeferralMode::Carryover->value)
            ->where('carryover_state', 'consolidated')
            ->pluck('carryover_amount')
            ->map(fn (mixed $value): string => (string) $value));

        if (Decimal::compare($allocation, $exercise->allocation()) !== 0
            || Decimal::compare($actual, $exercise->actual()) !== 0) {
            throw ValidationException::withMessages([
                'closing_snapshot' => 'I totali di primo livello della Snapshot non coincidono con i valori finali dell’Esercizio.',
            ]);
        }

        return [
            'rows' => $rows,
            'total_final_allocation' => $allocation,
            'total_closing_actual' => $actual,
            'total_operational_variance' => $variance,
            'total_consolidated_carryover' => $consolidatedCarryover,
        ];
    }

    /** @param Collection<string, int> $budgetOriginKeys */
    private static function includeStandalone(Expense $expense, Collection $budgetOriginKeys, int $year): bool
    {
        $reversedAt = $expense->getAttribute('reversed_at');
        $reversedInYear = $reversedAt instanceof \DateTimeInterface && (int) $reversedAt->format('Y') === $year;

        return Decimal::compare($expense->allocation(), '0.00') !== 0
            || $expense->hasActuals()
            || $budgetOriginKeys->has($expense->originKey())
            || $reversedInYear;
    }

    /** @param Collection<string, int> $budgetOriginKeys
     * @param  array<string, mixed>|null  $decision
     */
    private static function includeProject(Project $project, Exercise $exercise, Collection $budgetOriginKeys, CarbonImmutable $yearStart, CarbonImmutable $yearEnd, ?array $decision): bool
    {
        $totals = $project->annualTotals()[$exercise->id] ?? ['allocation' => '0.00', 'actual' => '0.00', 'has_actuals' => false];
        $state = $project->stateAtDate($yearEnd->toDateString());
        $transitionInYear = $project->transitions->contains(function (ProjectTransition $transition) use ($yearStart, $yearEnd): bool {
            if ($transition->annulledAt() !== null) {
                return false;
            }
            $date = CarbonImmutable::parse($transition->effectiveDate()->toDateString());

            return $date->betweenIncluded($yearStart, $yearEnd);
        });
        $outgoingConsolidated = $project->deferrals->contains(fn (ProjectDeferral $deferral): bool => $deferral->source_exercise_id === $exercise->id
            && $deferral->mode === ProjectDeferralMode::Carryover
            && $deferral->carryover_state === 'consolidated'
            && Decimal::compare((string) $deferral->carryover_amount, '0.00') !== 0);

        return Decimal::compare((string) $totals['allocation'], '0.00') !== 0
            || (bool) $totals['has_actuals']
            || $outgoingConsolidated
            || $budgetOriginKeys->has($project->originKey())
            || $transitionInYear
            || $decision !== null
            || in_array($state, [ProjectState::Planned, ProjectState::Open], true);
    }

    /** @param Collection<string, int> $budgetOriginKeys */
    private static function includeContract(Contract $contract, Exercise $exercise, Collection $budgetOriginKeys, CarbonImmutable $yearStart, CarbonImmutable $yearEnd): bool
    {
        $totals = $contract->annualTotals()[$exercise->id] ?? ['allocation' => '0.00', 'actual' => '0.00', 'has_actuals' => false];
        $state = $contract->stateAtDate($yearEnd->toDateString());
        $conditionInYear = $contract->conditions->contains(fn (ContractCondition $condition): bool => self::conditionOverlaps($condition, $yearStart, $yearEnd));
        $eventInYear = $contract->lifecycleFacts->contains(fn (ContractLifecycleFact $fact): bool => self::lifecycleEffectiveDate($fact)->betweenIncluded($yearStart, $yearEnd));

        return Decimal::compare((string) $totals['allocation'], '0.00') !== 0
            || (bool) $totals['has_actuals']
            || $budgetOriginKeys->has($contract->originKey())
            || $conditionInYear
            || $eventInYear
            || in_array($state, [ContractState::Planned, ContractState::Active], true);
    }

    /** @param list<array{operation_id: string, event_sequence: int}> $eventReferences
     * @return array<string, mixed>
     */
    private static function expenseRow(Expense $expense, array $eventReferences): array
    {
        $costCenter = $expense->directCostCenter;

        return [
            'company_id' => $expense->company_id,
            'source_type' => 'expense',
            'origin_id' => $expense->id,
            'origin_key' => $expense->originKey(),
            'copied_from_origin_key' => $expense->copied_from_origin_key,
            'label' => $expense->description,
            'summary' => $expense->notes,
            'supplier_id' => $expense->supplier_id,
            'supplier_label' => $expense->supplier?->legal_name,
            'cost_center_id' => $expense->direct_cost_center_id,
            'cost_center_label' => $costCenter === null ? 'Non classificato' : $costCenter->name,
            'end_state' => $expense->isReversed() ? 'reversed' : 'active',
            'has_actuals' => $expense->hasActuals(),
            'final_estimates' => $expense->allocation(),
            'received_carryover' => '0.00',
            'final_allocation' => $expense->allocation(),
            'closing_actual' => $expense->actual(),
            'operational_variance' => $expense->operationalVariance(),
            'detail_version' => 1,
            'detail' => self::expenseDetail($expense, $eventReferences),
        ];
    }

    /** @param array<string, mixed>|null $decision
     * @param  list<array{operation_id: string, event_sequence: int}>  $eventReferences
     * @return array<string, mixed>
     */
    private static function projectRow(Project $project, Exercise $exercise, CarbonImmutable $yearStart, CarbonImmutable $yearEnd, ?array $decision, array $eventReferences): array
    {
        $totals = $project->annualTotals()[$exercise->id] ?? ['allocation' => '0.00', 'actual' => '0.00', 'has_actuals' => false];
        $incoming = Decimal::sum($project->deferrals
            ->where('destination_exercise_id', $exercise->id)
            ->where('mode', ProjectDeferralMode::Carryover)
            ->pluck('carryover_amount'));
        $estimates = Decimal::subtract((string) $totals['allocation'], $incoming);
        $state = $project->stateAtDate($yearEnd->toDateString());
        $outgoing = $project->deferrals->firstWhere('source_exercise_id', $exercise->id);
        $outgoing = $outgoing instanceof ProjectDeferral ? $outgoing : null;
        $classification = $project->classifications->firstWhere('exercise_id', $exercise->id);
        $costCenter = $classification === null ? null : $classification->costCenter;
        $balance = ProjectDeferralValues::residual((string) $totals['allocation'], (string) $totals['actual']);
        $balanceFields = match ($state) {
            ProjectState::Closed => ['residual' => null, 'saving' => $balance, 'unused_allocation' => null],
            ProjectState::Cancelled => ['residual' => null, 'saving' => null, 'unused_allocation' => $balance],
            default => ['residual' => $balance, 'saving' => null, 'unused_allocation' => null],
        };

        return [
            'company_id' => $project->company_id,
            'source_type' => 'project',
            'origin_id' => $project->id,
            'origin_key' => $project->originKey(),
            'copied_from_origin_key' => null,
            'label' => $project->title,
            'summary' => $project->description,
            'supplier_id' => null,
            'supplier_label' => null,
            'cost_center_id' => $classification?->cost_center_id,
            'cost_center_label' => $costCenter === null ? 'Non classificato' : $costCenter->name,
            'end_state' => $state?->value,
            'has_actuals' => (bool) $totals['has_actuals'],
            'final_estimates' => $estimates,
            'received_carryover' => $incoming,
            'final_allocation' => (string) $totals['allocation'],
            'closing_actual' => (string) $totals['actual'],
            'operational_variance' => Decimal::subtract((string) $totals['actual'], (string) $totals['allocation']),
            'detail_version' => 1,
            'detail' => [
                'project_id' => $project->id,
                'title' => $project->title,
                'description' => $project->description,
                'state_at_31_december' => $state?->value,
                'classification' => [
                    'cost_center_id' => $classification?->cost_center_id,
                    'cost_center_label' => $costCenter?->name,
                ],
                'received_carryover' => $incoming,
                'final_estimates' => $estimates,
                'final_allocation' => (string) $totals['allocation'],
                'closing_actual' => (string) $totals['actual'],
                'operational_variance' => Decimal::subtract((string) $totals['actual'], (string) $totals['allocation']),
                ...$balanceFields,
                'deferral_mode' => $outgoing?->mode->value ?? ProjectDeferralMode::None->value,
                'reprogrammed_amount' => $outgoing?->mode === ProjectDeferralMode::Reprogramming ? (string) $outgoing->reprogrammed_amount : '0.00',
                'consolidated_carryover' => $outgoing?->mode === ProjectDeferralMode::Carryover && $outgoing->carryover_state === 'consolidated'
                    ? (string) $outgoing->carryover_amount
                    : '0.00',
                'closing_decision' => $decision,
                'transitions_in_exercise' => $project->transitions
                    ->filter(fn (ProjectTransition $transition): bool => $transition->annulledAt() === null
                        && CarbonImmutable::parse($transition->effectiveDate()->toDateString())->betweenIncluded($yearStart, $yearEnd))
                    ->map(fn (ProjectTransition $transition): array => [
                        'id' => $transition->id,
                        'from_state' => (string) $transition->getRawOriginal('from_state'),
                        'to_state' => (string) $transition->getRawOriginal('to_state'),
                        'effective_date' => $transition->effectiveDate()->toDateString(),
                        'reason' => $transition->reason,
                    ])->values()->all(),
                'expenses' => $project->expenses
                    ->where('exercise_id', $exercise->id)
                    ->sortBy('id')
                    ->map(fn (Expense $expense): array => self::expenseDetail($expense, []))
                    ->values()->all(),
                'relations' => self::relations($project),
                'event_references' => $eventReferences,
            ],
        ];
    }

    /** @param list<array{operation_id: string, event_sequence: int}> $eventReferences
     * @return array<string, mixed>
     */
    private static function contractRow(Contract $contract, Exercise $exercise, CarbonImmutable $yearStart, CarbonImmutable $yearEnd, array $eventReferences): array
    {
        $annual = ContractAnnualAllocation::forYear(
            $contract->conditions,
            $exercise->year,
            fn (string $date) => $contract->stateAtDate($date),
        );
        $totals = $contract->annualTotals()[$exercise->id] ?? ['allocation' => $annual->amount, 'actual' => '0.00', 'has_actuals' => false];
        $state = $contract->stateAtDate($yearEnd->toDateString());
        $classification = $contract->classifications->firstWhere('exercise_id', $exercise->id);
        $costCenter = $classification === null ? null : $classification->costCenter;
        $compositionConditionIds = collect($annual->composition)->pluck('condition_id')->map(fn (mixed $id): int => (int) $id)->unique();
        $conditions = $contract->conditions
            ->filter(fn (ContractCondition $condition): bool => self::conditionOverlaps($condition, $yearStart, $yearEnd)
                || $compositionConditionIds->contains($condition->id))
            ->sortBy('valid_from')
            ->map(fn (ContractCondition $condition): array => [
                'id' => $condition->id,
                'amount' => (string) $condition->amount,
                'cycle' => (string) $condition->cycle,
                'attribution_mode' => (string) $condition->attribution_mode,
                'valid_from' => $condition->validFrom()->toDateString(),
                'valid_to' => $condition->validTo()?->toDateString(),
                'annulled' => $condition->isAnnulled(),
                'reason' => $condition->reason,
            ])->values()->all();
        $lifecycle = $contract->lifecycleFacts
            ->filter(fn (ContractLifecycleFact $fact): bool => self::lifecycleEffectiveDate($fact)->betweenIncluded($yearStart, $yearEnd))
            ->sortBy(fn (ContractLifecycleFact $fact): string => self::lifecycleEffectiveDate($fact)->toDateString())
            ->map(fn (ContractLifecycleFact $fact): array => [
                'id' => $fact->id,
                'type' => (string) $fact->type,
                'declared_contractual_date' => $fact->declaredContractualDate()->toDateString(),
                'state_change_date' => $fact->stateChangeDate()?->toDateString(),
                'renewed_expiry_date' => $fact->renewedExpiryDate()?->toDateString(),
                'renewal_configuration_id' => $fact->renewal_configuration_id,
                'reason' => $fact->reason,
                'annulled_at' => $fact->annulledAt()?->toISOString(),
            ])->values()->all();
        $renewalConfiguration = ContractRenewalSchedule::configurationAtDate(
            $contract->renewalConfigurations,
            $yearEnd->toDateString(),
        );
        $renewalAtClosing = $renewalConfiguration instanceof ContractRenewalConfiguration
            ? [
                'id' => $renewalConfiguration->id,
                'effective_from' => $renewalConfiguration->effectiveFrom()->toDateString(),
                'automatic_renewal' => (bool) $renewalConfiguration->automatic_renewal,
                'expiry_anchor_date' => $renewalConfiguration->expiryAnchorDate()?->toDateString(),
                'renewal_duration_months' => $renewalConfiguration->renewal_duration_months,
                'notice_days' => $renewalConfiguration->notice_days,
            ]
            : [
                'id' => null,
                'effective_from' => null,
                'automatic_renewal' => (bool) $contract->automatic_renewal,
                'expiry_anchor_date' => $contract->renewalAnchorDate()?->toDateString(),
                'renewal_duration_months' => $contract->renewal_duration_months,
                'notice_days' => $contract->notice_days,
            ];

        return [
            'company_id' => $contract->company_id,
            'source_type' => 'contract',
            'origin_id' => $contract->id,
            'origin_key' => $contract->originKey(),
            'copied_from_origin_key' => null,
            'label' => $contract->title,
            'summary' => $contract->notes,
            'supplier_id' => $contract->supplier_id,
            'supplier_label' => $contract->supplier?->legal_name,
            'cost_center_id' => $classification?->cost_center_id,
            'cost_center_label' => $costCenter === null ? 'Non classificato' : $costCenter->name,
            'end_state' => $state->value,
            'has_actuals' => (bool) $totals['has_actuals'],
            'final_estimates' => (string) $totals['allocation'],
            'received_carryover' => '0.00',
            'final_allocation' => (string) $totals['allocation'],
            'closing_actual' => (string) $totals['actual'],
            'operational_variance' => Decimal::subtract((string) $totals['actual'], (string) $totals['allocation']),
            'detail_version' => 1,
            'detail' => [
                'contract_id' => $contract->id,
                'title' => $contract->title,
                'state_at_31_december' => $state->value,
                'supplier' => ['id' => $contract->supplier_id, 'label' => $contract->supplier?->legal_name],
                'classification' => [
                    'cost_center_id' => $classification?->cost_center_id,
                    'cost_center_label' => $costCenter?->name,
                ],
                'contractual_start_date' => $contract->contractualStartDate()->toDateString(),
                'next_expiry_date' => $contract->nextExpiryDate()?->toDateString(),
                'automatic_renewal' => $renewalAtClosing['automatic_renewal'],
                'renewal_duration_months' => $renewalAtClosing['renewal_duration_months'],
                'notice_days' => $renewalAtClosing['notice_days'],
                'renewal_configuration_at_31_december' => $renewalAtClosing,
                'conditions' => $conditions,
                'annual_composition' => $annual->composition,
                'lifecycle_events' => $lifecycle,
                'expenses' => $contract->expenses
                    ->where('exercise_id', $exercise->id)
                    ->sortBy('id')
                    ->map(fn (Expense $expense): array => self::expenseDetail($expense, []))
                    ->values()->all(),
                'relations' => self::relations($contract),
                'event_references' => $eventReferences,
            ],
        ];
    }

    /** @param list<array{operation_id: string, event_sequence: int}> $eventReferences
     * @return array<string, mixed>
     */
    private static function expenseDetail(Expense $expense, array $eventReferences): array
    {
        $expense->loadMissing([
            'lines',
            'supplier',
            'project.classifications.costCenter',
            'contract.classifications.costCenter',
            'directCostCenter',
        ]);
        $owner = match (true) {
            $expense->project !== null => ['type' => 'project', 'origin_id' => $expense->project->id, 'origin_key' => $expense->project->originKey(), 'label' => $expense->project->title],
            $expense->contract !== null => ['type' => 'contract', 'origin_id' => $expense->contract->id, 'origin_key' => $expense->contract->originKey(), 'label' => $expense->contract->title],
            default => ['type' => 'standalone', 'origin_id' => null, 'origin_key' => null, 'label' => 'Autonoma'],
        };
        $classification = $expense->project?->classifications->firstWhere('exercise_id', $expense->exercise_id)
            ?? $expense->contract?->classifications->firstWhere('exercise_id', $expense->exercise_id);
        $costCenter = $expense->directCostCenter ?? $classification?->costCenter;
        $costCenterSource = $expense->direct_cost_center_id !== null
            ? 'direct'
            : ($classification === null ? 'unclassified' : 'inherited');

        return [
            'expense_id' => $expense->id,
            'description' => $expense->description,
            'notes' => $expense->notes,
            'exercise_id' => $expense->exercise_id,
            'origin' => $expense->origin,
            'copied_from_origin_key' => $expense->copied_from_origin_key,
            'owner' => $owner,
            'supplier' => ['id' => $expense->supplier_id, 'label' => $expense->supplier?->legal_name],
            'cost_center' => [
                'id' => $costCenter?->id,
                'label' => $costCenter?->name,
                'source' => $costCenterSource,
            ],
            'state' => $expense->isReversed() ? 'reversed' : 'active',
            'final_estimate_total' => $expense->allocation(),
            'closing_actual_total' => $expense->actual(),
            'has_actuals' => $expense->hasActuals(),
            'lines' => $expense->lines->sortBy('id')->map(fn (ExpenseLine $line): array => [
                'line_id' => $line->id,
                'type' => $line->lineType()->value,
                'amount' => (string) $line->amount,
                'quantity' => $line->quantity,
                'unit_amount' => $line->unit_amount,
                'unit_of_measure' => $line->unit_of_measure,
                'note' => $line->note,
                'state' => $line->isAnnulled() ? 'annulled' : 'active',
            ])->values()->all(),
            'event_references' => $eventReferences,
        ];
    }

    /** @return list<array<string, mixed>> */
    private static function relations(Project|Contract $source): array
    {
        $links = ProjectContractLink::query()->active()->with(['project', 'contract'])->when(
            $source instanceof Project,
            fn ($query) => $query->where('project_id', $source->id),
            fn ($query) => $query->where('contract_id', $source->id),
        )->orderBy('id')->get();

        return $links->map(fn (ProjectContractLink $link): array => [
            'type' => 'linked',
            'link_id' => $link->id,
            'project_origin_key' => $link->project->originKey(),
            'project_label' => $link->project->title,
            'contract_origin_key' => $link->contract->originKey(),
            'contract_label' => $link->contract->title,
            'note' => $link->note,
        ])->values()->all();
    }

    private static function conditionOverlaps(ContractCondition $condition, CarbonImmutable $yearStart, CarbonImmutable $yearEnd): bool
    {
        if ($condition->isAnnulled()) {
            return false;
        }

        return ! $condition->validFrom()->greaterThan($yearEnd)
            && ($condition->validTo() === null || ! $condition->validTo()->lessThan($yearStart));
    }

    private static function lifecycleEffectiveDate(ContractLifecycleFact $fact): CarbonImmutable
    {
        $value = $fact->stateChangeDate()?->toDateString()
            ?? $fact->renewedExpiryDate()?->toDateString()
            ?? $fact->declaredContractualDate()->toDateString();

        return CarbonImmutable::parse($value);
    }
}
