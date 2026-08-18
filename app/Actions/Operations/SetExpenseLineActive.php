<?php

namespace App\Actions\Operations;

use App\Domain\Company\AuditEventType;
use App\Domain\Expenses\Decimal;
use App\Domain\Expenses\ExpenseAuditSnapshot;
use App\Domain\Expenses\ManualExpenseLine;
use App\Domain\Projects\ProjectAuditSnapshot;
use App\Domain\Projects\ProjectExpenseActivity;
use App\Models\AuditEvent;
use App\Models\Company;
use App\Models\Exercise;
use App\Models\Expense;
use App\Models\ExpenseLine;
use App\Models\Project;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class SetExpenseLineActive
{
    /** @param array<string, mixed> $context */
    public function execute(User $actor, ExpenseLine $line, bool $active, string $operationId, array $context = []): ExpenseLine
    {
        Validator::make(['operation_id' => $operationId], ['operation_id' => ['required', 'uuid']])->validate();

        return DB::transaction(function () use ($actor, $line, $active, $operationId, $context): ExpenseLine {
            $unlockedExpense = Expense::query()->findOrFail($line->expense_id);
            $company = Company::query()->lockForUpdate()->findOrFail($unlockedExpense->company_id);
            $exercise = Exercise::query()->lockForUpdate()->findOrFail($unlockedExpense->exercise_id);
            $project = $unlockedExpense->project_id === null
                ? null
                : Project::query()->lockForUpdate()->findOrFail($unlockedExpense->project_id);
            $transitions = collect();
            if ($project !== null) {
                $transitions = $project->transitions()->orderBy('effective_date')->orderBy('id')->lockForUpdate()->get();
                $project->classifications()->where('exercise_id', $exercise->id)->lockForUpdate()->get();
            }
            $expense = Expense::query()->lockForUpdate()->findOrFail($unlockedExpense->id);
            $lockedLine = ExpenseLine::query()->lockForUpdate()->findOrFail($line->id);
            Gate::forUser($actor)->authorize('update', $lockedLine);
            $eventType = $active ? AuditEventType::ExpenseLineRestored : AuditEventType::ExpenseLineAnnulled;

            $existing = AuditEvent::query()->where('operation_id', $operationId)->first();
            if ($existing !== null) {
                if ($existing->eventType() !== $eventType
                    || $existing->subject_type !== ExpenseLine::class
                    || $existing->subject_id !== $lockedLine->id) {
                    throw ValidationException::withMessages(['operation_id' => 'Identificativo operazione già utilizzato.']);
                }

                return $lockedLine;
            }

            if (! $exercise->isOpen() || $expense->isReversed()) {
                throw ValidationException::withMessages(['expense' => 'La Spesa deve essere Attiva in un Esercizio Aperto.']);
            }
            if ($active === ! $lockedLine->isAnnulled()) {
                return $lockedLine;
            }
            if ($active) {
                $validatedLine = ManualExpenseLine::validate([
                    'type' => $lockedLine->lineType()->value,
                    'amount' => $lockedLine->amount,
                    'quantity' => $lockedLine->quantity,
                    'unit_amount' => $lockedLine->unit_amount,
                    'unit_of_measure' => $lockedLine->unit_of_measure,
                    'note' => $lockedLine->note,
                    'amount_warning_acknowledged' => true,
                ], $company, $exercise, false);
            }

            $allocationBefore = $expense->allocation();
            $actualBefore = $expense->actual();
            $projectContext = null;
            $varianceBefore = null;
            $openingTransition = null;
            $overspendNote = $this->nullableTrim($context['overspend_note'] ?? null);
            if ($project !== null) {
                $varianceBefore = ProjectExpenseActivity::annualVariance($project, $exercise);
                if ($active) {
                    $projectContext = ProjectExpenseActivity::validate($project, $exercise, $company, [$validatedLine], $context);
                    $openingTransition = app(ProjectExpenseOpening::class)->create($project, $company, $actor, $projectContext, $transitions);
                }
            }
            $before = ExpenseAuditSnapshot::line($lockedLine);
            $lockedLine->annulled_at = $active ? null : now();
            $lockedLine->save();
            if ($project !== null) {
                $varianceAfter = ProjectExpenseActivity::annualVariance($project, $exercise);
                $overspendContext = $projectContext ?? [
                    'actual_kind' => null,
                    'activity_note' => null,
                    'open_project' => false,
                    'overspend_note' => $overspendNote,
                    'today' => now($company->timezone)->toDateString(),
                ];
                ProjectExpenseActivity::assertOverspendNote($company, $overspendContext, $varianceBefore, $varianceAfter);
                $project->increment('revision', $openingTransition === null ? 1 : 2);
            }
            $expense->increment('revision');
            $exercise->increment('revision');
            $expense->refresh();

            $newValue = ExpenseAuditSnapshot::line($lockedLine);
            if ($project !== null) {
                $newValue['project_activity'] = [
                    'actual_kind' => ($projectContext['actual_kind'] ?? null)?->value,
                    'activity_note' => $projectContext['activity_note'] ?? null,
                    'opening_transition' => $openingTransition === null ? null : ProjectAuditSnapshot::transition($openingTransition),
                    'overspend' => ProjectAuditSnapshot::overspend($varianceBefore, ProjectExpenseActivity::annualVariance($project, $exercise)),
                    'overspend_note' => $projectContext['overspend_note'] ?? $overspendNote,
                ];
            }

            AuditEvent::query()->create([
                'operation_id' => $operationId,
                'company_id' => $company->id,
                'actor_id' => $actor->id,
                'event_type' => $eventType,
                'subject_type' => ExpenseLine::class,
                'subject_id' => $lockedLine->id,
                'affected_exercise_ids' => [$exercise->id],
                'effective_from' => now($company->timezone)->toDateString(),
                'previous_value' => $before,
                'new_value' => $newValue,
                'allocated_impact_by_exercise' => ExpenseAuditSnapshot::impact($exercise->id, Decimal::subtract($expense->allocation(), $allocationBefore)),
                'actual_impact_by_exercise' => ExpenseAuditSnapshot::impact($exercise->id, Decimal::subtract($expense->actual(), $actualBefore)),
                'reason' => $projectContext === null ? $overspendNote : ($projectContext['activity_note'] ?? $projectContext['overspend_note']),
                'reference_type' => $project === null ? Expense::class : Project::class,
                'reference_id' => $project === null ? $expense->id : $project->id,
            ]);

            return $lockedLine;
        });
    }

    private function nullableTrim(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $value = trim($value);

        return $value === '' ? null : $value;
    }
}
