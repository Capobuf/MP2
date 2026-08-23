<?php

namespace App\Domain\Proposals;

use App\Domain\Contracts\ContractAnnualAllocation;
use App\Domain\Contracts\ContractStateTimeline;
use App\Domain\Expenses\Decimal;
use App\Domain\Projects\ProjectState;
use App\Domain\Projects\ProjectStateTimeline;
use App\Models\BudgetSnapshot;
use App\Models\Exercise;
use App\Models\Proposal;
use App\Models\ProposalItem;
use Carbon\CarbonImmutable;

final class ProposalImpactPlan
{
    /** @return array<int, array<string, mixed>> */
    public static function build(Proposal $proposal): array
    {
        $proposal->loadMissing(['company', 'exercise', 'items.actions', 'items.expense', 'items.project', 'items.contract']);
        $exercises = Exercise::query()->where('company_id', $proposal->company_id)->orderBy('year')->get()->keyBy('id');
        $affectedIds = self::affectedExerciseIds($proposal);
        $stale = self::staleProposals($proposal);

        return collect($affectedIds)->map(function (int $exerciseId) use ($proposal, $exercises, $stale): array {
            $exercise = $exercises->get($exerciseId);
            if ($exercise === null) {
                return [
                    'exercise_id' => $exerciseId, 'year' => null, 'is_open' => false, 'will_apply' => false,
                    'historical_divergence' => false, 'divergence_reason' => null,
                    'allocation_before' => '0.00', 'allocation_after' => '0.00', 'allocation_delta' => '0.00',
                    'sources' => [], 'unchanged_budgets' => [], 'stale_proposals' => $stale, 'warnings' => [],
                    'blocks' => ['Esercizio interessato non disponibile nella stessa Azienda.'],
                ];
            }

            $rows = $proposal->items->map(fn (ProposalItem $item): array => self::row($item, $exercise))->filter(
                fn (array $row): bool => $exerciseId === $proposal->exercise_id
                    || Decimal::compare($row['before'], $row['after']) !== 0
                    || $row['state_before'] !== $row['state_after']
                    || in_array($exerciseId, $row['explicit_exercise_ids'], true),
            );
            $directTarget = $rows->contains(fn (array $row): bool => in_array($exerciseId, $row['direct_exercise_ids'], true));
            $rows = $rows->map(function (array $row): array {
                unset($row['explicit_exercise_ids']);
                unset($row['direct_exercise_ids']);

                return $row;
            })->values();
            $before = Decimal::sum($rows->pluck('before'));
            $after = Decimal::sum($rows->pluck('after'));
            $budgets = BudgetSnapshot::query()->where('exercise_id', $exerciseId)->orderBy('version')->get(['id', 'version'])->map(
                fn (BudgetSnapshot $budget): array => ['budget_id' => $budget->id, 'version' => $budget->version],
            )->all();
            $warnings = [];
            if ($budgets !== []) {
                $warnings[] = 'I Budget già approvati dell’Esercizio restano invariati.';
            }
            if ($stale !== []) {
                $warnings[] = 'Altre Proposte in Bozza sulle sorgenti interessate diventeranno Da riallineare.';
            }
            $historicalDivergence = ! $exercise->isOpen() && ($before !== $after || $rows->contains(fn (array $row): bool => $row['state_before'] !== $row['state_after']));
            $divergenceReason = $historicalDivergence ? 'L’Esercizio è Chiuso: lo storico resta invariato e viene registrata la differenza prodotta dalle regole correnti.' : null;
            if ($historicalDivergence) {
                $warnings[] = $divergenceReason;
            }

            return [
                'exercise_id' => $exerciseId, 'year' => $exercise->year, 'is_open' => $exercise->isOpen(), 'will_apply' => $exercise->isOpen(),
                'historical_divergence' => $historicalDivergence, 'divergence_reason' => $divergenceReason,
                'allocation_before' => $before, 'allocation_after' => $after, 'allocation_delta' => Decimal::subtract($after, $before),
                'sources' => $rows->all(), 'unchanged_budgets' => $budgets, 'stale_proposals' => $stale,
                'warnings' => $warnings, 'blocks' => ! $exercise->isOpen() && $directTarget ? ['Una decisione tenta di modificare direttamente un Esercizio Chiuso.'] : [],
            ];
        })->values()->all();
    }

    /** @return list<int> */
    public static function affectedExerciseIds(Proposal $proposal): array
    {
        $proposal->loadMissing(['items.actions', 'items.expense', 'items.project', 'items.contract']);
        $exercises = Exercise::query()->where('company_id', $proposal->company_id)->orderBy('id')->get();
        $ids = [$proposal->exercise_id];

        foreach ($proposal->items as $item) {
            self::appendExerciseId($ids, data_get($item->baseline, 'plan_baseline.exercise_id'));
            self::appendExerciseId($ids, $item->result['exercise_id'] ?? null);
            foreach (ProposalPlanData::rows($item->result['expense_plan'] ?? null, 'expense_plan') as $expense) {
                self::appendExerciseId($ids, $expense['exercise_id'] ?? null);
            }
            foreach ($item->actions as $action) {
                self::appendExerciseId($ids, $action->payload['exercise_id'] ?? null);
                foreach (array_keys($action->payload['exercise_impacts'] ?? []) as $exerciseId) {
                    self::appendExerciseId($ids, $exerciseId);
                }
            }
            if (in_array($item->source_type, [ProposalSourceType::Project, ProposalSourceType::Contract], true)) {
                foreach ($exercises as $exercise) {
                    $row = self::row($item, $exercise);
                    if (Decimal::compare($row['before'], $row['after']) !== 0 || $row['state_before'] !== $row['state_after']) {
                        $ids[] = $exercise->id;
                    }
                }
            }
        }

        $ids = array_values(array_unique($ids));
        sort($ids);

        return $ids;
    }

    /** @param list<int> $ids */
    private static function appendExerciseId(array &$ids, mixed $value): void
    {
        if (is_int($value) || (is_string($value) && ctype_digit($value))) {
            $ids[] = (int) $value;
        }
    }

    /** @param array<string, mixed> $result */
    public static function allocation(array $result): string
    {
        if (($result['reversed'] ?? false) === true || filled($result['reversed_at'] ?? null)) {
            return '0.00';
        }
        if (isset($result['approved_allocation'])) {
            return Decimal::money((string) $result['approved_allocation']);
        }
        $amounts = [];
        foreach ($result['estimate_lines'] ?? [] as $line) {
            if (is_array($line) && ! ($line['annulled'] ?? false) && ! filled($line['annulled_at'] ?? null)) {
                $amounts[] = (string) ($line['amount'] ?? '0');
            }
        }
        foreach ($result['expense_plan'] ?? [] as $expense) {
            if (is_array($expense)) {
                $amounts[] = self::allocation($expense);
            }
        }

        return Decimal::sum($amounts);
    }

    /** @return array<string, mixed> */
    private static function row(ProposalItem $item, Exercise $exercise): array
    {
        [$before, $after, $stateBefore, $stateAfter] = match ($item->source_type) {
            ProposalSourceType::Expense => self::expenseImpact($item, $exercise),
            ProposalSourceType::Project => self::projectImpact($item, $exercise),
            ProposalSourceType::Contract => self::contractImpact($item, $exercise),
        };

        return [
            'proposal_item_id' => $item->proposal_item_id, 'source_type' => $item->source_type->value,
            'origin_key' => data_get($item->baseline, 'plan_baseline.origin_key'),
            'before' => $before, 'after' => $after, 'delta' => Decimal::subtract($after, $before),
            'state_before' => $stateBefore, 'state_after' => $stateAfter,
            'explicit_exercise_ids' => self::explicitExerciseIds($item),
            'direct_exercise_ids' => self::directExerciseIds($item),
        ];
    }

    /** @return array{string, string, ?string, ?string} */
    private static function expenseImpact(ProposalItem $item, Exercise $exercise): array
    {
        $baselineExercise = (int) data_get($item->baseline, 'plan_baseline.exercise_id', $item->proposal->exercise_id);
        $resultExercise = (int) ($item->result['exercise_id'] ?? $item->proposal->exercise_id);
        $beforeReversed = filled(data_get($item->baseline, 'plan_baseline.reversed_at'));
        $afterReversed = (bool) ($item->result['reversed'] ?? filled($item->result['reversed_at'] ?? null));
        $before = $baselineExercise === $exercise->id && ! $beforeReversed ? self::allocation((array) data_get($item->baseline, 'plan_baseline', [])) : '0.00';
        $after = $resultExercise === $exercise->id && ! $afterReversed ? self::allocation($item->result) : '0.00';

        return [$before, $after, $beforeReversed ? 'reversed' : 'active', $afterReversed ? 'reversed' : 'active'];
    }

    /** @return array{string, string, ?string, ?string} */
    private static function projectImpact(ProposalItem $item, Exercise $exercise): array
    {
        $result = $item->result;
        $before = $item->project?->annualTotals()[$exercise->id]['allocation'] ?? '0.00';
        $expensePlan = collect(ProposalPlanData::rows($result['expense_plan'] ?? null, 'expense_plan'));
        $controlsExercise = $exercise->id === $item->proposal->exercise_id || $expensePlan->contains(
            fn (array $expense): bool => (int) ($expense['exercise_id'] ?? $item->proposal->exercise_id) === $exercise->id,
        );
        $plannedAfter = Decimal::sum($expensePlan->filter(
            fn (array $expense): bool => (int) ($expense['exercise_id'] ?? $item->proposal->exercise_id) === $exercise->id,
        )->map(fn (array $expense): string => self::allocation($expense)));
        $after = $controlsExercise ? $plannedAfter : Decimal::money($before);
        $reference = $exercise->year.'-12-31';
        $beforeState = $item->project?->stateAtDate($reference)?->value;
        $initialState = ProjectState::from((string) ($result['initial_state'] ?? 'planned'));
        $transitions = [...ProposalPlanData::rows($result['transitions'] ?? null, 'transitions'), ...collect(ProposalPlanData::rows($result['planned_transitions'] ?? null, 'planned_transitions'))->map(fn (array $transition): array => [...$transition, 'annulled_at' => null])->all()];
        $afterState = ProjectStateTimeline::stateAtDate($initialState, (string) $result['initial_effective_date'], $transitions, $reference)?->value;

        return [Decimal::money($before), $after, $beforeState, $afterState];
    }

    /** @return array{string, string, ?string, ?string} */
    private static function contractImpact(ProposalItem $item, Exercise $exercise): array
    {
        $result = $item->result;
        $contract = $item->contract;
        if ($contract !== null) {
            $contract->loadMissing(['conditions', 'lifecycleFacts', 'renewalConfigurations']);
            $beforeStateAt = fn (string $date) => ContractStateTimeline::stateAtDate(
                $contract->contractualStartDate()->toDateString(), $contract->lifecycleFacts, $date, $contract->renewalConfigurations,
            );
            $before = ContractAnnualAllocation::forYear($contract->conditions, $exercise->year, $beforeStateAt)->amount;
        } else {
            $before = '0.00';
        }
        $conditions = collect(ProposalPlanData::rows($result['conditions'] ?? null, 'conditions'));
        foreach (ProposalPlanData::rows($result['planned_condition_changes'] ?? null, 'planned_condition_changes') as $change) {
            $conditions = $conditions->flatMap(function (array $condition) use ($change): array {
                if ((int) ($condition['id'] ?? 0) !== (int) $change['condition_id']) {
                    return [$condition];
                }
                $replacement = [...$condition, 'id' => 0, 'amount' => $change['amount'], 'cycle' => $change['cycle'], 'attribution_mode' => $change['attribution_mode'], 'valid_from' => $change['effective_date'], 'annulled_at' => null];
                if ($change['future_replacement'] ?? false) {
                    return [$replacement];
                }

                return [[...$condition, 'valid_to' => CarbonImmutable::parse($change['effective_date'])->subDay()->toDateString()], $replacement];
            });
        }
        $conditions = $conditions->concat(ProposalPlanData::rows($result['planned_conditions'] ?? null, 'planned_conditions'))->values();
        $facts = collect(ProposalPlanData::rows($result['lifecycle_facts'] ?? null, 'lifecycle_facts'))->concat(collect(ProposalPlanData::rows($result['planned_lifecycle'] ?? null, 'planned_lifecycle'))->map(fn (array $fact): array => [
            ...$fact, 'id' => 0, 'state_change_date' => $fact['effective_date'], 'annulled_at' => null,
        ]));
        $stateAt = fn (string $date) => ContractStateTimeline::stateAtDate((string) $result['contractual_start_date'], $facts, $date, ProposalPlanData::rows($result['renewal_configurations'] ?? null, 'renewal_configurations'));
        $after = ContractAnnualAllocation::forYear($conditions, $exercise->year, $stateAt)->amount;
        $reference = $exercise->year.'-12-31';
        $beforeState = $contract?->stateAtDate($reference)->value;
        $afterState = $stateAt($reference)->value;

        return [Decimal::money($before), $after, $beforeState, $afterState];
    }

    /** @return list<int> */
    private static function explicitExerciseIds(ProposalItem $item): array
    {
        $ids = collect([data_get($item->baseline, 'plan_baseline.exercise_id'), $item->result['exercise_id'] ?? null]);
        foreach ($item->actions as $action) {
            $ids->push($action->payload['exercise_id'] ?? null);
            foreach (array_keys($action->payload['exercise_impacts'] ?? []) as $exerciseId) {
                $ids->push($exerciseId);
            }
        }

        return $ids->filter(fn (mixed $id): bool => is_numeric($id))->map(fn (mixed $id): int => (int) $id)->unique()->values()->all();
    }

    /** @return list<int> */
    private static function directExerciseIds(ProposalItem $item): array
    {
        $ids = collect([data_get($item->baseline, 'plan_baseline.exercise_id'), $item->result['exercise_id'] ?? null]);
        foreach ($item->actions as $action) {
            $ids->push($action->payload['exercise_id'] ?? $action->payload['target_exercise_id'] ?? null);
        }

        return $ids->filter(fn (mixed $id): bool => is_numeric($id))->map(fn (mixed $id): int => (int) $id)->unique()->values()->all();
    }

    /** @return array<int, array{proposal_id: int, exercise_id: int}> */
    private static function staleProposals(Proposal $proposal): array
    {
        $query = ProposalItem::query()->where('proposal_items.company_id', $proposal->company_id)
            ->where('proposal_items.proposal_id', '!=', $proposal->id)
            ->whereHas('proposal', fn ($builder) => $builder->where('status', 'draft'))
            ->where(function ($builder) use ($proposal): void {
                foreach (['expense_id', 'project_id', 'contract_id'] as $column) {
                    $ids = $proposal->items->pluck($column)->filter();
                    if ($ids->isNotEmpty()) {
                        $builder->orWhereIn($column, $ids);
                    }
                }
            });

        return $query->with('proposal:id,exercise_id')->get()->map(fn (ProposalItem $item): array => [
            'proposal_id' => $item->proposal_id, 'exercise_id' => $item->proposal->exercise_id,
        ])->unique('proposal_id')->values()->all();
    }
}
