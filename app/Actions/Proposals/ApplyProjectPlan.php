<?php

namespace App\Actions\Proposals;

use App\Domain\Expenses\ExpenseLineType;
use App\Domain\Proposals\ProposalPlanData;
use App\Models\Expense;
use App\Models\Project;
use App\Models\ProjectExerciseClassification;
use App\Models\ProjectTransition;
use App\Models\ProposalItem;
use App\Models\User;
use Illuminate\Validation\ValidationException;

final class ApplyProjectPlan
{
    public function execute(ProposalItem $item, User $actor): Project
    {
        $result = $item->result;
        $project = $item->project;
        if ($project === null) {
            $project = Project::query()->create(['company_id' => $item->company_id, 'title' => $result['title'], 'description' => $result['description'] ?? null, 'notes' => $result['notes'] ?? null, 'initial_state' => $result['initial_state'], 'initial_effective_date' => $result['initial_effective_date']]);
        } elseif ($project->company_id !== $item->company_id) {
            throw ValidationException::withMessages(['project' => 'Progetto esterno all’Azienda.']);
        }

        if (array_key_exists('cost_center_id', $result) || $item->project_id === null) {
            ProjectExerciseClassification::query()->updateOrCreate(['project_id' => $project->id, 'exercise_id' => $result['exercise_id'] ?? $item->proposal->exercise_id], ['company_id' => $item->company_id, 'cost_center_id' => $result['cost_center_id'] ?? null]);
        }
        foreach (ProposalPlanData::rows($result['planned_transitions'] ?? null, 'planned_transitions') as $transition) {
            ProjectTransition::query()->create(['company_id' => $item->company_id, 'project_id' => $project->id, 'from_state' => $transition['from_state'], 'to_state' => $transition['to_state'], 'effective_date' => $transition['effective_date'], 'reason' => $transition['reason'] ?? null, 'created_by_id' => $actor->id]);
        }
        foreach (ProposalPlanData::rows($result['expense_plan'] ?? null, 'expense_plan') as $expensePlan) {
            $originKey = (string) ($expensePlan['origin_key'] ?? '');
            if (! str_starts_with($originKey, 'expense:') || ($expensePlan['estimate_lines_changed'] ?? false) !== true) {
                continue;
            }
            $expense = Expense::query()->where('company_id', $item->company_id)->where('project_id', $project->id)->find((int) str($originKey)->after(':')->toString());
            if ($expense === null) {
                throw ValidationException::withMessages(['expense_plan' => 'Spesa figlia non più disponibile nel Progetto.']);
            }
            $plannedIds = [];
            foreach (ProposalPlanData::rows($expensePlan['estimate_lines'] ?? null, 'estimate_lines') as $line) {
                $existing = isset($line['line_id']) ? $expense->lines()->where('type', ExpenseLineType::Estimate->value)->find($line['line_id']) : null;
                if ($existing === null && isset($line['line_id'])) {
                    throw ValidationException::withMessages(['estimate_lines' => 'Riga Stima non appartenente alla Spesa figlia.']);
                }
                if ($existing === null) {
                    if ($line['annulled'] ?? false) {
                        continue;
                    } $existing = $expense->lines()->create(['type' => ExpenseLineType::Estimate, 'amount' => $line['amount'], 'note' => $line['note'] ?? null]);
                } else {
                    $existing->fill(['amount' => $line['amount'], 'note' => $line['note'] ?? null, 'annulled_at' => ($line['annulled'] ?? false) ? now() : null]);
                    if ($existing->isDirty()) {
                        $existing->revision++;
                    }
                    $existing->save();
                }
                $plannedIds[] = $existing->id;
            }
            $omitted = $expense->lines()->where('type', ExpenseLineType::Estimate->value)->whereNotIn('id', $plannedIds)->whereNull('annulled_at')->get();
            foreach ($omitted as $line) {
                $line->annulled_at = now();
                $line->revision++;
                $line->save();
            }
            $expense->increment('revision');
        }
        if ($project->isArchived() && collect(ProposalPlanData::rows($result['planned_transitions'] ?? null, 'planned_transitions'))->contains(fn (array $transition): bool => in_array($transition['to_state'], ['planned', 'open'], true))) {
            $project->update(['archived_at' => null]);
        }
        $project->increment('revision');

        return $project->refresh();
    }
}
