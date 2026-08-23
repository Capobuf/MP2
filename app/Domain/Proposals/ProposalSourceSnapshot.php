<?php

namespace App\Domain\Proposals;

use App\Domain\Expenses\ExpenseLineType;
use App\Models\Contract;
use App\Models\Expense;
use App\Models\ExpenseLine;
use App\Models\Project;
use App\Models\ProjectDeferral;

final class ProposalSourceSnapshot
{
    /** @return array<string, mixed> */
    public static function expense(Expense $expense): array
    {
        $expense->loadMissing(['lines', 'supplier', 'directCostCenter']);
        $estimateLines = $expense->lines->filter(fn (ExpenseLine $line): bool => $line->lineType() === ExpenseLineType::Estimate);
        $actualLines = $expense->lines->filter(fn (ExpenseLine $line): bool => $line->lineType() === ExpenseLineType::Actual);

        return self::canonical([
            'plan_baseline' => [
                'origin_key' => $expense->originKey(), 'exercise_id' => $expense->exercise_id,
                'project_id' => $expense->project_id, 'contract_id' => $expense->contract_id,
                'supplier_id' => $expense->supplier_id, 'supplier_label' => $expense->supplier?->legal_name,
                'cost_center_id' => $expense->direct_cost_center_id, 'cost_center_label' => $expense->directCostCenter?->name,
                'description' => $expense->description, 'notes' => $expense->notes,
                'reversed_at' => self::date($expense->reversed_at),
                'estimate_lines' => self::lines($estimateLines->all()),
            ],
            'actual_context' => ['has_actuals' => $expense->hasActuals(), 'actual_total' => $expense->actual(), 'actual_lines' => self::lines($actualLines->all())],
        ]);
    }

    /** @return array<string, mixed> */
    public static function project(Project $project, int $exerciseId): array
    {
        $project->loadMissing(['transitions', 'classifications.costCenter', 'expenses.lines', 'contractLinks', 'deferrals']);
        $expenses = $project->expenses->where('exercise_id', $exerciseId)->sortBy('id');
        $incoming = $project->deferrals->firstWhere('destination_exercise_id', $exerciseId);

        return self::canonical([
            'plan_baseline' => [
                'origin_key' => $project->originKey(), 'title' => $project->title, 'description' => $project->description,
                'notes' => $project->notes, 'initial_state' => $project->initialState()->value,
                'initial_effective_date' => self::date($project->initial_effective_date), 'archived_at' => self::date($project->archived_at),
                'transitions' => $project->transitions->map->only(['id', 'from_state', 'to_state', 'effective_date', 'reason', 'annulled_at'])->values()->all(),
                'classification' => $project->classifications->where('exercise_id', $exerciseId)->map(fn ($row): array => ['id' => $row->id, 'cost_center_id' => $row->cost_center_id, 'cost_center_label' => $row->costCenter?->name])->values()->all(),
                'expense_plan' => $expenses->map(fn (Expense $expense): array => self::expense($expense)['plan_baseline'])->values()->all(),
                'contract_links' => $project->contractLinks->map->only(['id', 'contract_id', 'archived_at'])->values()->all(),
                'incoming_deferral' => self::incomingDeferral($incoming, $exerciseId),
            ],
            'actual_context' => ['has_actuals' => $expenses->contains(fn (Expense $expense): bool => $expense->hasActuals()), 'expenses' => $expenses->map(fn (Expense $expense): array => self::expense($expense)['actual_context'])->values()->all()],
        ]);
    }

    /** @return array<string, mixed> */
    public static function contract(Contract $contract, int $exerciseId): array
    {
        $contract->loadMissing(['supplier', 'conditions', 'lifecycleFacts', 'renewalConfigurations', 'classifications.costCenter', 'expenses.lines', 'projectLinks']);
        $expenses = $contract->expenses->where('exercise_id', $exerciseId)->sortBy('id');

        return self::canonical([
            'plan_baseline' => [
                'origin_key' => $contract->originKey(), 'title' => $contract->title, 'notes' => $contract->notes,
                'supplier_id' => $contract->supplier_id, 'supplier_label' => $contract->supplier?->legal_name,
                'contractual_start_date' => self::date($contract->contractual_start_date), 'next_expiry_date' => self::date($contract->next_expiry_date),
                'renewal_anchor_date' => self::date($contract->renewal_anchor_date), 'automatic_renewal' => $contract->automatic_renewal,
                'renewal_duration_months' => $contract->renewal_duration_months, 'notice_days' => $contract->notice_days,
                'archived_at' => self::date($contract->archived_at),
                'conditions' => $contract->conditions->map->only(['id', 'cycle', 'attribution_mode', 'amount', 'valid_from', 'valid_to', 'reason', 'annulled_at'])->values()->all(),
                'lifecycle_facts' => $contract->lifecycleFacts->map->only(['id', 'type', 'declared_contractual_date', 'state_change_date', 'renewed_expiry_date', 'reason', 'annulled_at'])->values()->all(),
                'renewal_configurations' => $contract->renewalConfigurations->map->only(['id', 'effective_from', 'expiry_anchor_date', 'automatic_renewal', 'renewal_duration_months', 'notice_days'])->values()->all(),
                'classification' => $contract->classifications->where('exercise_id', $exerciseId)->map(fn ($row): array => ['id' => $row->id, 'cost_center_id' => $row->cost_center_id, 'cost_center_label' => $row->costCenter?->name])->values()->all(),
                'expense_plan' => $expenses->map(fn (Expense $expense): array => self::expense($expense)['plan_baseline'])->values()->all(),
                'project_links' => $contract->projectLinks->map->only(['id', 'project_id', 'archived_at'])->values()->all(),
            ],
            'actual_context' => ['has_actuals' => $expenses->contains(fn (Expense $expense): bool => $expense->hasActuals()), 'expenses' => $expenses->map(fn (Expense $expense): array => self::expense($expense)['actual_context'])->values()->all()],
        ]);
    }

    /** @param array<string, mixed> $snapshot */
    public static function fingerprint(array $snapshot): string
    {
        return hash('sha256', json_encode(self::canonical($snapshot), JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION));
    }

    /**
     * @param  array<int, ExpenseLine>  $lines
     * @return array<int, array<string, mixed>>
     */
    private static function lines(array $lines): array
    {
        usort($lines, fn (ExpenseLine $a, ExpenseLine $b): int => $a->id <=> $b->id);

        return array_map(fn (ExpenseLine $line): array => ['id' => $line->id, 'type' => $line->lineType()->value, 'amount' => (string) $line->amount, 'quantity' => $line->quantity, 'unit_amount' => $line->unit_amount, 'unit_of_measure' => $line->unit_of_measure, 'note' => $line->note, 'annulled_at' => self::date($line->annulled_at), 'revision' => (int) $line->revision], $lines);
    }

    /** @return array<string, mixed> */
    private static function incomingDeferral(?ProjectDeferral $deferral, int $destinationExerciseId): array
    {
        return [
            'source_exercise_id' => $deferral?->source_exercise_id,
            'destination_exercise_id' => $destinationExerciseId,
            'mode' => $deferral?->mode->value ?? 'none',
            'carryover_amount' => $deferral === null ? '0.00' : $deferral->carryover_amount,
            'carryover_state' => $deferral?->carryover_state,
            'reprogrammed_amount' => $deferral === null ? '0.00' : $deferral->reprogrammed_amount,
            'reprogramming_operation_id' => $deferral?->reprogramming_operation_id,
        ];
    }

    private static function date(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        return method_exists($value, 'toDateString') ? $value->toDateString() : (string) $value;
    }

    private static function canonical(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }
        if (! array_is_list($value)) {
            ksort($value);
        }
        foreach ($value as $key => $nested) {
            $value[$key] = self::canonical($nested);
        }

        return $value;
    }
}
