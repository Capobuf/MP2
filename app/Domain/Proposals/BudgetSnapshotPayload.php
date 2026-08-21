<?php

namespace App\Domain\Proposals;

use App\Domain\Contracts\ContractAnnualAllocation;
use App\Domain\Contracts\ContractDeadline;
use App\Domain\Contracts\ContractStateTimeline;
use App\Domain\Expenses\Decimal;
use App\Domain\Expenses\ExpenseLineType;
use App\Models\Contract;
use App\Models\CostCenter;
use App\Models\Expense;
use App\Models\ExpenseLine;
use App\Models\Project;
use App\Models\ProjectContractLink;
use App\Models\Proposal;
use App\Models\ProposalItem;
use Carbon\CarbonImmutable;
use Illuminate\Validation\ValidationException;

final class BudgetSnapshotPayload
{
    /**
     * @param  array<string, Expense|Project|Contract>  $identities
     * @param  array<int|string, int>  $eventSequences
     * @return array{rows: array<int, array<string, mixed>>, total: string}
     */
    public static function build(Proposal $proposal, array $identities, array $eventSequences): array
    {
        $proposal->loadMissing(['company', 'exercise', 'items.actions', 'actions']);
        $rows = [];
        $totalParts = [];

        foreach ($proposal->items->sortBy('id') as $item) {
            $live = self::liveIdentity($item, $identities);
            $allocation = self::allocation($live, $proposal->exercise_id);
            if ($live instanceof Project || $live instanceof Contract || ($live->project_id === null && $live->contract_id === null)) {
                $totalParts[] = $allocation;
            }
            $actions = $item->actions->sortBy('sequence')->map(fn ($action): array => [
                'sequence' => $action->sequence, 'type' => $action->action_type->value, 'payload_version' => $action->payload_version,
                'payload' => $action->payload, 'reason' => $action->reason,
            ])->values()->all();
            $common = [
                'schema_version' => 1,
                'identity' => [
                    'source_type' => $item->source_type->value, 'origin_id' => $live->id, 'origin_key' => $live->originKey(),
                    'proposal_item_id' => $item->proposal_item_id, 'copied_from_origin_key' => $item->copied_from_origin_key,
                ],
                'approved_actions' => $actions,
                'relations' => self::relations($live),
                'approval_event_sequences' => array_values($eventSequences),
            ];
            $detail = match (true) {
                $live instanceof Expense => [...$common, 'expense' => self::expenseDetail($live, $proposal)],
                $live instanceof Project => [...$common, 'project' => self::projectDetail($live, $proposal, $item)],
                $live instanceof Contract => [...$common, 'contract' => self::contractDetail($live, $proposal, $item)],
            };
            BudgetPayloadGuard::assertPlanOnly($detail);
            $costCenterId = self::costCenterId($live, $proposal->exercise_id);
            $rows[] = [
                'company_id' => $proposal->company_id, 'source_type' => $item->source_type->value,
                'origin_id' => $live->id, 'origin_key' => $live->originKey(), 'proposal_item_id' => $item->proposal_item_id,
                'copied_from_origin_key' => $item->copied_from_origin_key,
                'label' => $live instanceof Expense ? $live->description : $live->title,
                'summary' => $live instanceof Project ? $live->description : ($live->notes ?? null),
                'supplier_id' => $live instanceof Project ? null : $live->supplier_id,
                'supplier_label' => $live instanceof Project ? null : $live->supplier()->value('legal_name'),
                'cost_center_id' => $costCenterId, 'cost_center_label' => self::costCenterLabel($costCenterId),
                'approved_estimates' => $allocation, 'approved_carryover' => '0.00', 'carryover_state' => null,
                'approved_allocation' => $allocation,
                'start_state' => self::state($live, $proposal->exercise->year.'-01-01'),
                'end_state' => self::state($live, $proposal->exercise->year.'-12-31'),
                'detail_version' => 1, 'detail' => $detail,
            ];
        }

        self::assertConsistent($rows);
        $derivedTotal = Decimal::sum(collect($rows)->filter(fn (array $row): bool => in_array($row['source_type'], ['project', 'contract'], true)
            || data_get($row, 'detail.expense.owner.type') === 'standalone')->pluck('approved_allocation'));
        if (Decimal::compare($derivedTotal, Decimal::sum($totalParts)) !== 0) {
            throw ValidationException::withMessages(['budget' => 'Il totale Header non coincide con le righe di primo livello.']);
        }

        return ['rows' => $rows, 'total' => $derivedTotal];
    }

    /** @return array<string, mixed> */
    private static function expenseDetail(Expense $expense, Proposal $proposal): array
    {
        $expense->loadMissing(['lines', 'supplier', 'project', 'contract']);
        $owner = match (true) {
            $expense->project !== null => ['type' => 'project', 'origin_id' => $expense->project->id, 'origin_key' => $expense->project->originKey(), 'label' => $expense->project->title],
            $expense->contract !== null => ['type' => 'contract', 'origin_id' => $expense->contract->id, 'origin_key' => $expense->contract->originKey(), 'label' => $expense->contract->title],
            default => ['type' => 'standalone', 'origin_id' => null, 'origin_key' => null, 'label' => 'Autonoma'],
        };
        $lines = $expense->lines->filter(fn (ExpenseLine $line): bool => ! $line->isAnnulled() && $line->lineType() === ExpenseLineType::Estimate)->sortBy('id')->map(fn (ExpenseLine $line): array => [
            'line_id' => $line->id, 'amount' => (string) $line->amount, 'quantity' => $line->quantity,
            'unit_amount' => $line->unit_amount, 'unit_of_measure' => $line->unit_of_measure, 'note' => $line->note,
        ])->values()->all();

        return [
            'expense_id' => $expense->id, 'description' => $expense->description,
            'exercise_id' => $expense->exercise_id, 'exercise_year' => $proposal->exercise->year,
            'origin' => $expense->origin, 'owner' => $owner,
            'supplier' => ['id' => $expense->supplier_id, 'label' => $expense->supplier?->legal_name],
            'cost_center' => ['id' => self::costCenterId($expense, $proposal->exercise_id), 'label' => self::costCenterLabel(self::costCenterId($expense, $proposal->exercise_id))],
            'state' => $expense->isReversed() ? 'reversed' : 'active',
            'approved_estimate_total' => $expense->allocation(), 'active_estimate_lines' => $lines,
        ];
    }

    /** @return array<string, mixed> */
    private static function projectDetail(Project $project, Proposal $proposal, ProposalItem $item): array
    {
        $project->loadMissing(['transitions', 'expenses.lines', 'expenses.supplier', 'classifications.costCenter']);
        $expenses = $project->expenses->where('exercise_id', $proposal->exercise_id)->sortBy('id')->map(fn (Expense $expense): array => [
            'expense_id' => $expense->id, 'description' => $expense->description,
            'supplier' => ['id' => $expense->supplier_id, 'label' => $expense->supplier?->legal_name],
            'approved_estimate_total' => $expense->allocation(),
            'active_estimate_lines' => $expense->lines->filter(fn (ExpenseLine $line): bool => ! $line->isAnnulled() && $line->lineType() === ExpenseLineType::Estimate)->sortBy('id')->map(fn (ExpenseLine $line): array => [
                'line_id' => $line->id, 'amount' => (string) $line->amount, 'quantity' => $line->quantity,
                'unit_amount' => $line->unit_amount, 'unit_of_measure' => $line->unit_of_measure, 'note' => $line->note,
            ])->values()->all(),
        ])->values()->all();
        $transitionActions = $item->actions->where('action_type', ProposalActionType::PlanProjectTransition)->map(fn ($action): array => [
            ...$action->payload, 'reason' => $action->reason ?? ($action->payload['reason'] ?? null),
        ])->values()->all();

        return [
            'project_id' => $project->id, 'title' => $project->title, 'description' => $project->description,
            'exercise_id' => $proposal->exercise_id, 'exercise_year' => $proposal->exercise->year,
            'start_state' => self::state($project, $proposal->exercise->year.'-01-01'),
            'end_state' => self::state($project, $proposal->exercise->year.'-12-31'),
            'approved_transitions' => $transitionActions,
            'deferral_mode' => 'none', 'approved_carryover' => '0.00', 'approved_reprogrammed_amount' => '0.00',
            'cost_center' => ['id' => self::costCenterId($project, $proposal->exercise_id), 'label' => self::costCenterLabel(self::costCenterId($project, $proposal->exercise_id))],
            'approved_estimate_total' => self::allocation($project, $proposal->exercise_id), 'expenses' => $expenses,
        ];
    }

    /** @return array<string, mixed> */
    private static function contractDetail(Contract $contract, Proposal $proposal, ProposalItem $item): array
    {
        $contract->loadMissing(['supplier', 'conditions', 'lifecycleFacts', 'renewalConfigurations', 'classifications.costCenter']);
        $stateAt = fn (string $date) => ContractStateTimeline::stateAtDate(
            $contract->contractualStartDate()->toDateString(), $contract->lifecycleFacts, $date, $contract->renewalConfigurations,
        );
        $annual = ContractAnnualAllocation::forYear($contract->conditions, $proposal->exercise->year, $stateAt);
        $compositionIds = collect($annual->composition)->pluck('condition_id')->map(fn (mixed $id): int => (int) $id);
        $actionConditionIds = $item->actions->flatMap(fn ($action): array => isset($action->payload['condition_id']) ? [(int) $action->payload['condition_id']] : [])->values();
        $actedConditionIds = $contract->conditions->filter(function ($condition) use ($item): bool {
            return $item->actions->contains(function ($action) use ($condition): bool {
                if ($action->action_type === ProposalActionType::AddContractCondition) {
                    return (string) $condition->cycle === (string) $action->payload['cycle']
                        && (string) $condition->attribution_mode === (string) $action->payload['attribution_mode']
                        && Decimal::compare((string) $condition->amount, (string) $action->payload['amount']) === 0
                        && $condition->validFrom()->toDateString() === (string) $action->payload['valid_from'];
                }
                if ($action->action_type === ProposalActionType::ChangeContractEconomics) {
                    return (string) $condition->cycle === (string) $action->payload['cycle']
                        && (string) $condition->attribution_mode === (string) $action->payload['attribution_mode']
                        && Decimal::compare((string) $condition->amount, (string) $action->payload['amount']) === 0
                        && $condition->validFrom()->toDateString() === (string) $action->payload['effective_date'];
                }

                return false;
            });
        })->pluck('id');
        $conditionIds = $compositionIds->concat($actionConditionIds)->concat($actedConditionIds)->unique();
        $conditions = $contract->conditions->filter(fn ($condition): bool => $conditionIds->contains($condition->id))->sortBy('valid_from')->map(fn ($condition): array => [
            'condition_id' => $condition->id, 'cycle' => (string) $condition->cycle, 'attribution_mode' => (string) $condition->attribution_mode,
            'amount' => (string) $condition->amount, 'valid_from' => $condition->validFrom()->toDateString(),
            'valid_to' => $condition->validTo()?->toDateString(), 'annulled' => $condition->isAnnulled(), 'reason' => $condition->reason,
            'annual_composition' => collect($annual->composition)->where('condition_id', $condition->id)->values()->all(),
        ])->values()->all();
        $deadline = ContractDeadline::fromContract($contract, $proposal->exercise, CarbonImmutable::parse($proposal->approved_at ?? now(), $proposal->company->timezone));
        $lifecycle = $item->actions->where('action_type', ProposalActionType::PlanContractLifecycle)->map(fn ($action): array => [
            ...$action->payload, 'reason' => $action->reason ?? ($action->payload['reason'] ?? null),
        ])->values()->all();

        return [
            'contract_id' => $contract->id, 'title' => $contract->title,
            'supplier' => ['id' => $contract->supplier_id, 'label' => $contract->supplier?->legal_name],
            'exercise_id' => $proposal->exercise_id, 'exercise_year' => $proposal->exercise->year,
            'start_state' => self::state($contract, $proposal->exercise->year.'-01-01'),
            'end_state' => self::state($contract, $proposal->exercise->year.'-12-31'),
            'contractual_start_date' => $contract->contractualStartDate()->toDateString(),
            'next_expiry_date' => $contract->nextExpiryDate()?->toDateString(), 'automatic_renewal' => (bool) $contract->automatic_renewal,
            'renewal_duration_months' => $contract->renewal_duration_months, 'notice_days' => $contract->notice_days,
            'cancellation_deadline' => $deadline->noticeLimitDate,
            'cost_center' => ['id' => self::costCenterId($contract, $proposal->exercise_id), 'label' => self::costCenterLabel(self::costCenterId($contract, $proposal->exercise_id))],
            'approved_estimate_total' => $annual->amount, 'conditions' => $conditions,
            'annual_composition' => $annual->composition, 'approved_lifecycle' => $lifecycle,
        ];
    }

    /** @return array<int, array<string, mixed>> */
    private static function relations(Expense|Project|Contract $live): array
    {
        if ($live instanceof Expense) {
            return [];
        }
        $links = ProjectContractLink::query()->active()->with(['project', 'contract'])->when(
            $live instanceof Project, fn ($query) => $query->where('project_id', $live->id), fn ($query) => $query->where('contract_id', $live->id),
        )->orderBy('id')->get();

        return $links->map(fn (ProjectContractLink $link): array => [
            'type' => 'linked_to', 'project' => ['origin_key' => $link->project->originKey(), 'label' => $link->project->title],
            'contract' => ['origin_key' => $link->contract->originKey(), 'label' => $link->contract->title], 'note' => $link->note,
        ])->all();
    }

    private static function allocation(Expense|Project|Contract $live, int $exerciseId): string
    {
        return match (true) {
            $live instanceof Expense => $live->allocation(),
            $live instanceof Project => $live->annualTotals()[$exerciseId]['allocation'] ?? '0.00',
            $live instanceof Contract => $live->annualTotals()[$exerciseId]['allocation'] ?? '0.00',
        };
    }

    private static function costCenterId(Expense|Project|Contract $live, int $exerciseId): ?int
    {
        if ($live instanceof Expense) {
            if ($live->project_id !== null) {
                return $live->project?->classifications()->where('exercise_id', $exerciseId)->value('cost_center_id');
            }
            if ($live->contract_id !== null) {
                return $live->contract?->classifications()->where('exercise_id', $exerciseId)->value('cost_center_id');
            }

            return $live->direct_cost_center_id;
        }

        return $live->classifications()->where('exercise_id', $exerciseId)->value('cost_center_id');
    }

    private static function costCenterLabel(?int $id): string
    {
        return $id === null ? 'Non classificato' : CostCenter::query()->findOrFail($id)->name;
    }

    private static function state(Expense|Project|Contract $live, string $date): string
    {
        if ($live instanceof Expense) {
            return $live->isReversed() ? 'reversed' : 'active';
        }

        if ($live instanceof Project) {
            if ($date < $live->initialEffectiveDate()->toDateString()) {
                return 'absent';
            }

            return $live->stateAtDate($date)->value;
        }

        return $live->stateAtDate($date)->value;
    }

    /**
     * @param  array<string, Expense|Project|Contract>  $identities
     */
    private static function liveIdentity(ProposalItem $item, array $identities): Expense|Project|Contract
    {
        $live = $identities[$item->proposal_item_id] ?? match ($item->source_type) {
            ProposalSourceType::Expense => $item->expense,
            ProposalSourceType::Project => $item->project,
            ProposalSourceType::Contract => $item->contract,
        };
        if (! $live instanceof Expense && ! $live instanceof Project && ! $live instanceof Contract) {
            throw ValidationException::withMessages(['item' => 'Identità viva non risolta.']);
        }

        return $live;
    }

    /** @param array<int, array<string, mixed>> $rows */
    private static function assertConsistent(array $rows): void
    {
        foreach ($rows as $row) {
            $detailTotal = data_get($row, 'detail.'.$row['source_type'].'.approved_estimate_total');
            if ($detailTotal === null || Decimal::compare((string) $detailTotal, (string) $row['approved_estimates']) !== 0
                || Decimal::compare(Decimal::sum([(string) $row['approved_estimates'], (string) $row['approved_carryover']]), (string) $row['approved_allocation']) !== 0) {
                throw ValidationException::withMessages(['budget' => 'Totali della Snapshot Budget non coerenti.']);
            }
        }
    }
}
