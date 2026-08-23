<?php

namespace App\Actions\Proposals;

use App\Domain\Expenses\Decimal;
use App\Domain\Expenses\ExpenseLineType;
use App\Domain\Projects\ProjectDeferralMode;
use App\Domain\Projects\ProjectDeferralValues;
use App\Models\Exercise;
use App\Models\Expense;
use App\Models\ExpenseLine;
use App\Models\Project;
use App\Models\ProjectDeferral;
use App\Models\ProposalAction;
use App\Models\ProposalItem;
use Illuminate\Validation\ValidationException;

final class ApplyProjectDeferral
{
    /**
     * @param  (callable(string): void)|null  $checkpoint
     * @return array<string, mixed>
     */
    public function execute(ProposalItem $item, ProposalAction $action, Project $project, ?callable $checkpoint = null): array
    {
        $payload = $action->payload;
        $source = Exercise::query()->where('company_id', $item->company_id)->findOrFail($payload['source_exercise_id']);
        $destination = Exercise::query()->where('company_id', $item->company_id)->findOrFail($payload['destination_exercise_id']);

        return $this->apply(
            $item->company_id,
            $action->operation_id,
            $project,
            $source,
            $destination,
            $payload,
            $checkpoint,
        );
    }

    /**
     * @param  array<string, mixed>  $payload
     * @param  (callable(string): void)|null  $checkpoint
     * @return array<string, mixed>
     */
    public function executeDirect(Project $project, Exercise $source, Exercise $destination, array $payload, string $operationId, ?callable $checkpoint = null): array
    {
        return $this->apply(
            $project->company_id,
            $operationId,
            $project,
            $source,
            $destination,
            $payload,
            $checkpoint,
        );
    }

    /**
     * @param  array<string, mixed>  $payload
     * @param  (callable(string): void)|null  $checkpoint
     * @return array<string, mixed>
     */
    private function apply(int $companyId, string $operationId, Project $project, Exercise $source, Exercise $destination, array $payload, ?callable $checkpoint): array
    {
        $deferral = ProjectDeferral::query()
            ->where('project_id', $project->id)
            ->where('source_exercise_id', $source->id)
            ->where('destination_exercise_id', $destination->id)
            ->lockForUpdate()
            ->first();
        $previous = $this->state($deferral);
        $mode = ProjectDeferralMode::from((string) $payload['mode']);

        if ($deferral?->mode === ProjectDeferralMode::Reprogramming && $mode !== ProjectDeferralMode::Reprogramming) {
            if ($mode === ProjectDeferralMode::Carryover) {
                $totals = $project->annualTotals()[$source->id] ?? ['allocation' => '0.00', 'actual' => '0.00'];
                $restoredAllocation = Decimal::add($totals['allocation'], (string) $deferral->reprogrammed_amount);
                $maximum = ProjectDeferralValues::maximumTransferable($restoredAllocation, $totals['actual']);
                if (Decimal::compare((string) $payload['carryover_amount'], $maximum) > 0) {
                    throw ValidationException::withMessages(['carryover_amount' => 'Il Riporto supera il massimo dopo il ripristino della Riprogrammazione.']);
                }
            }
            $this->reverseReprogramming($project, $source, $destination, $deferral);
        }

        $effects = null;
        if ($mode === ProjectDeferralMode::Reprogramming) {
            if ($deferral?->mode === ProjectDeferralMode::Reprogramming) {
                throw ValidationException::withMessages(['mode' => 'Una Riprogrammazione attiva non può essere modificata in-place.']);
            }
            $effects = $this->applyReprogramming($project, $source, $destination, $payload, $checkpoint);
        }

        $attributes = match ($mode) {
            ProjectDeferralMode::None => [
                'mode' => $mode, 'carryover_amount' => '0.00', 'carryover_state' => null,
                'reprogrammed_amount' => '0.00', 'reprogramming_operation_id' => null, 'reprogramming_effects' => null,
            ],
            ProjectDeferralMode::Carryover => [
                'mode' => $mode, 'carryover_amount' => $payload['carryover_amount'], 'carryover_state' => 'provisional',
                'reprogrammed_amount' => '0.00', 'reprogramming_operation_id' => null, 'reprogramming_effects' => null,
            ],
            ProjectDeferralMode::Reprogramming => [
                'mode' => $mode, 'carryover_amount' => '0.00', 'carryover_state' => null,
                'reprogrammed_amount' => $payload['reprogrammed_amount'], 'reprogramming_operation_id' => $operationId,
                'reprogramming_effects' => $effects,
            ],
        };
        if ($deferral === null) {
            $deferral = ProjectDeferral::query()->create([
                'company_id' => $companyId,
                'project_id' => $project->id,
                'source_exercise_id' => $source->id,
                'destination_exercise_id' => $destination->id,
                ...$attributes,
            ]);
        } else {
            $deferral->update($attributes);
        }

        $project->increment('revision');
        $source->increment('revision');
        $destination->increment('revision');
        $project->unsetRelation('expenses');
        $project->unsetRelation('deferrals');

        return [
            'previous' => $previous,
            'current' => $this->state($deferral->refresh()),
            'source_exercise_id' => $source->id,
            'destination_exercise_id' => $destination->id,
            'reprogramming_effects' => $effects,
        ];
    }

    /**
     * @param  array<string, mixed>  $payload
     * @param  (callable(string): void)|null  $checkpoint
     * @return array<string, mixed>
     */
    private function applyReprogramming(Project $project, Exercise $source, Exercise $destination, array $payload, ?callable $checkpoint): array
    {
        $sourceEffects = [];
        $touchedExpenses = [];
        foreach ($payload['source_estimate_reductions'] as $reduction) {
            $line = ExpenseLine::query()->with('expense')->lockForUpdate()->find($reduction['source_line_id']);
            if ($line === null || $line->expense?->project_id !== $project->id || $line->expense->exercise_id !== $source->id
                || $line->expense->company_id !== $project->company_id || $line->expense->isReversed()
                || $line->lineType() !== ExpenseLineType::Estimate || $line->isAnnulled()
                || (int) $line->revision !== (int) $reduction['source_line_revision']
                || (int) $line->expense->revision !== (int) $reduction['source_expense_revision']
                || Decimal::compare((string) $line->amount, (string) $reduction['source_amount']) !== 0) {
                throw ValidationException::withMessages(['source' => 'Una Riga Stima origine della Riprogrammazione è cambiata.']);
            }
            $beforeAmount = (string) $line->amount;
            $full = Decimal::compare((string) $reduction['reduction_amount'], $beforeAmount) === 0;
            $line->amount = $full ? $beforeAmount : Decimal::subtract($beforeAmount, (string) $reduction['reduction_amount']);
            $line->annulled_at = $full ? now() : null;
            $line->revision++;
            $line->save();
            $touchedExpenses[$line->expense_id] = $line->expense;
            $sourceEffects[] = [
                'company_id' => $project->company_id,
                'project_id' => $project->id,
                'exercise_id' => $source->id,
                'expense_id' => $line->expense_id,
                'source_expense_origin_key' => $line->expense->originKey(),
                'expense_reversed_after' => false,
                'expense_line_id' => $line->id,
                'line_revision_after' => (int) $line->revision,
                'amount_before' => $beforeAmount,
                'amount_after' => (string) $line->amount,
                'quantity' => $line->quantity,
                'unit_amount' => $line->unit_amount,
                'unit_of_measure' => $line->unit_of_measure,
                'note' => $line->note,
                'annulled_before' => false,
                'annulled_after' => $full,
            ];
        }
        foreach ($touchedExpenses as $expense) {
            $expense->increment('revision');
        }
        if ($checkpoint !== null) {
            $checkpoint('after_deferral_source_reduction');
        }

        $destinationEffects = [];
        foreach ($payload['destination_plans'] as $plan) {
            $expense = Expense::query()->create([
                'company_id' => $project->company_id,
                'exercise_id' => $destination->id,
                'project_id' => $project->id,
                'contract_id' => null,
                'origin' => 'manual',
                'copied_from_origin_key' => $plan['copied_from_origin_key'],
                'supplier_id' => $plan['supplier_id'] ?? null,
                'direct_cost_center_id' => null,
                'description' => $plan['description'],
                'notes' => $plan['notes'] ?? null,
            ]);
            $lineEffects = [];
            foreach ($plan['estimate_lines'] as $plannedLine) {
                $line = $expense->lines()->create([
                    'type' => ExpenseLineType::Estimate,
                    'amount' => $plannedLine['amount'],
                    'note' => $plannedLine['note'] ?? null,
                ]);
                $lineEffects[] = [
                    'expense_line_id' => $line->id,
                    'line_revision_after' => (int) $line->revision,
                    'amount' => (string) $line->amount,
                    'quantity' => $line->quantity,
                    'unit_amount' => $line->unit_amount,
                    'unit_of_measure' => $line->unit_of_measure,
                    'note' => $line->note,
                    'annulled' => false,
                ];
            }
            $destinationEffects[] = [
                'expense_id' => $expense->id,
                'company_id' => $project->company_id,
                'exercise_id' => $destination->id,
                'project_id' => $project->id,
                'reversed' => false,
                'copied_from_origin_key' => $expense->copied_from_origin_key,
                'estimate_lines' => $lineEffects,
            ];
        }
        if ($checkpoint !== null) {
            $checkpoint('after_deferral_destination_creation');
        }

        return ['source_lines' => $sourceEffects, 'destination_expenses' => $destinationEffects];
    }

    private function reverseReprogramming(Project $project, Exercise $source, Exercise $destination, ProjectDeferral $deferral): void
    {
        $effects = $deferral->reprogramming_effects;
        if ($effects === null) {
            throw ValidationException::withMessages(['reprogramming' => 'Effetti della Riprogrammazione non disponibili per il ripristino.']);
        }
        $sourceLines = [];
        $destinationLines = [];
        foreach ($effects['source_lines'] ?? [] as $expected) {
            $line = ExpenseLine::query()->with('expense')->lockForUpdate()->find($expected['expense_line_id'] ?? null);
            if (! $this->sourceMatches($line, $expected, $project, $source)) {
                throw ValidationException::withMessages(['reprogramming' => 'Il piano origine è stato modificato indipendentemente; il ripristino è bloccato.']);
            }
            $sourceLines[] = [$line, $expected];
        }
        foreach ($effects['destination_expenses'] ?? [] as $expectedExpense) {
            $expense = Expense::query()->lockForUpdate()->find($expectedExpense['expense_id'] ?? null);
            if ($expense === null || $expense->company_id !== $project->company_id || $expense->project_id !== $project->id
                || $expense->exercise_id !== $destination->id || $expense->isReversed() !== (bool) $expectedExpense['reversed']
                || $expense->copied_from_origin_key !== $expectedExpense['copied_from_origin_key']) {
                throw ValidationException::withMessages(['reprogramming' => 'Il piano destinazione è stato modificato indipendentemente; il ripristino è bloccato.']);
            }
            foreach ($expectedExpense['estimate_lines'] ?? [] as $expectedLine) {
                $line = ExpenseLine::query()->where('expense_id', $expense->id)->lockForUpdate()->find($expectedLine['expense_line_id'] ?? null);
                if (! $this->destinationMatches($line, $expectedLine)) {
                    throw ValidationException::withMessages(['reprogramming' => 'Una Stima destinazione è stata modificata indipendentemente; il ripristino è bloccato.']);
                }
                $destinationLines[] = [$line, $expense];
            }
        }

        $touchedExpenses = [];
        foreach ($sourceLines as [$line, $expected]) {
            $line->amount = $expected['amount_before'];
            $line->annulled_at = $expected['annulled_before'] ? ($line->annulled_at ?? now()) : null;
            $line->revision++;
            $line->save();
            $touchedExpenses[$line->expense_id] = $line->expense;
        }
        foreach ($destinationLines as [$line, $expense]) {
            $line->annulled_at = now();
            $line->revision++;
            $line->save();
            $touchedExpenses[$expense->id] = $expense;
        }
        foreach ($touchedExpenses as $expense) {
            $expense->increment('revision');
        }
    }

    /** @param array<string, mixed> $expected */
    private function sourceMatches(?ExpenseLine $line, array $expected, Project $project, Exercise $source): bool
    {
        return $line !== null && $line->expense !== null
            && $line->expense->company_id === $project->company_id && $line->expense->project_id === $project->id
            && $line->expense->exercise_id === $source->id && $line->expense->isReversed() === (bool) $expected['expense_reversed_after']
            && $line->expense->originKey() === $expected['source_expense_origin_key']
            && (int) $line->revision === (int) $expected['line_revision_after']
            && Decimal::compare((string) $line->amount, (string) $expected['amount_after']) === 0
            && (string) $line->quantity === (string) $expected['quantity']
            && (string) $line->unit_amount === (string) $expected['unit_amount']
            && $line->unit_of_measure === $expected['unit_of_measure'] && $line->note === $expected['note']
            && $line->isAnnulled() === (bool) $expected['annulled_after'];
    }

    /** @param array<string, mixed> $expected */
    private function destinationMatches(?ExpenseLine $line, array $expected): bool
    {
        return $line !== null && (int) $line->revision === (int) $expected['line_revision_after']
            && Decimal::compare((string) $line->amount, (string) $expected['amount']) === 0
            && (string) $line->quantity === (string) $expected['quantity']
            && (string) $line->unit_amount === (string) $expected['unit_amount']
            && $line->unit_of_measure === $expected['unit_of_measure'] && $line->note === $expected['note']
            && $line->isAnnulled() === (bool) $expected['annulled'];
    }

    /** @return array<string, mixed> */
    private function state(?ProjectDeferral $deferral): array
    {
        return [
            'mode' => $deferral?->mode->value ?? 'none',
            'carryover_amount' => $deferral === null ? '0.00' : $deferral->carryover_amount,
            'carryover_state' => $deferral?->carryover_state,
            'reprogrammed_amount' => $deferral === null ? '0.00' : $deferral->reprogrammed_amount,
            'reprogramming_operation_id' => $deferral?->reprogramming_operation_id,
        ];
    }
}
