<?php

namespace App\Actions\Proposals;

use App\Domain\Expenses\ExpenseLineType;
use App\Models\Contract;
use App\Models\Exercise;
use App\Models\Expense;
use App\Models\ExpenseLine;
use App\Models\Project;
use App\Models\ProposalItem;
use App\Models\User;
use Illuminate\Validation\ValidationException;

final class ApplyExpensePlan
{
    /** @param array<string, Project|Contract|Expense> $identities */
    public function execute(ProposalItem $item, array $identities, User $actor): Expense
    {
        $result = $item->result;
        $exercise = Exercise::query()->where('company_id', $item->company_id)->find($result['exercise_id'] ?? $item->proposal->exercise_id);
        if ($exercise === null || ! $exercise->isOpen()) {
            throw ValidationException::withMessages(['exercise_id' => 'Esercizio interessato non Aperto.']);
        }
        $project = isset($result['project_item_id']) ? ($identities[$result['project_item_id']] ?? null) : (isset($result['project_id']) ? Project::query()->where('company_id', $item->company_id)->find($result['project_id']) : null);
        if ($project !== null && ! $project instanceof Project) {
            throw ValidationException::withMessages(['project_item_id' => 'Riferimento Progetto non risolto.']);
        }
        $contract = isset($result['contract_id']) ? Contract::query()->where('company_id', $item->company_id)->find($result['contract_id']) : null;
        $expense = $item->expense;
        $reversedAt = array_key_exists('reversed', $result)
            ? ($result['reversed'] ? now() : null)
            : $expense?->reversed_at;
        $attributes = ['company_id' => $item->company_id, 'exercise_id' => $exercise->id, 'project_id' => $project?->id, 'contract_id' => $contract?->id, 'origin' => 'manual', 'copied_from_origin_key' => $item->copied_from_origin_key, 'supplier_id' => $result['supplier_id'] ?? ($contract?->supplier_id), 'direct_cost_center_id' => ($project || $contract) ? null : ($result['cost_center_id'] ?? $result['direct_cost_center_id'] ?? null), 'description' => $result['description'] ?? 'Spesa pianificata', 'notes' => $result['notes'] ?? null, 'reversed_at' => $reversedAt];
        if ($expense === null) {
            $expense = Expense::query()->create($attributes);
        } else {
            if ($expense->hasActuals() && ($expense->exercise_id !== $attributes['exercise_id'] || $expense->project_id !== $attributes['project_id'] || $expense->supplier_id !== $attributes['supplier_id'] || $expense->direct_cost_center_id !== $attributes['direct_cost_center_id'] || $attributes['reversed_at'] !== null)) {
                throw ValidationException::withMessages(['expense' => 'Una Spesa con Effettivi non può essere spostata, riclassificata o stornata.']);
            } $expense->update($attributes);
        }
        $plannedIds = [];
        foreach ($result['estimate_lines'] ?? [] as $line) {
            $lineId = $line['line_id'] ?? $line['id'] ?? null;
            $existing = $lineId !== null ? ExpenseLine::query()->where('expense_id', $expense->id)->where('type', ExpenseLineType::Estimate->value)->find($lineId) : null;
            if ($existing === null && $lineId !== null) {
                throw ValidationException::withMessages(['estimate_lines' => 'Riga Stima non appartenente alla Spesa.']);
            }
            $annulled = array_key_exists('annulled', $line)
                ? (bool) $line['annulled']
                : filled($line['annulled_at'] ?? null);
            if ($existing === null) {
                if ($annulled) {
                    continue;
                } $existing = $expense->lines()->create(['type' => ExpenseLineType::Estimate, 'amount' => $line['amount'], 'note' => $line['note'] ?? null]);
            } else {
                $annulledAt = array_key_exists('annulled', $line)
                    ? ($annulled ? ($existing->annulled_at ?? now()) : null)
                    : $existing->annulled_at;
                $existing->update(['amount' => $line['amount'], 'note' => $line['note'] ?? null, 'annulled_at' => $annulledAt]);
            }
            $plannedIds[] = $existing->id;
        }
        if (array_key_exists('estimate_lines', $result)) {
            $expense->lines()->where('type', ExpenseLineType::Estimate->value)->whereNotIn('id', $plannedIds)->whereNull('annulled_at')->update(['annulled_at' => now()]);
        }
        $expense->increment('revision');
        $exercise->increment('revision');
        $project?->increment('revision');
        $contract?->increment('revision');

        return $expense->refresh();
    }
}
