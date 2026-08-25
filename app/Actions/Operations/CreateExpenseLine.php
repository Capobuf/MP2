<?php

namespace App\Actions\Operations;

use App\Domain\Company\AuditEventType;
use App\Domain\Contracts\ContractExpenseActivity;
use App\Domain\Expenses\Decimal;
use App\Domain\Expenses\ExpenseAuditSnapshot;
use App\Domain\Expenses\ExpenseLineType;
use App\Domain\Expenses\ManualExpenseLine;
use App\Domain\Projects\ProjectAuditSnapshot;
use App\Domain\Projects\ProjectExpenseActivity;
use App\Models\AuditEvent;
use App\Models\Company;
use App\Models\Contract;
use App\Models\Exercise;
use App\Models\Expense;
use App\Models\ExpenseLine;
use App\Models\Project;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class CreateExpenseLine
{
    /** @param array<string, mixed> $input */
    public function execute(User $actor, Expense $expense, array $input, string $operationId): ExpenseLine
    {
        Validator::make(['operation_id' => $operationId], ['operation_id' => ['required', 'uuid']])->validate();

        return DB::transaction(function () use ($actor, $expense, $input, $operationId): ExpenseLine {
            $company = Company::query()->lockForUpdate()->findOrFail($expense->company_id);
            $exercise = Exercise::query()->lockForUpdate()->findOrFail($expense->exercise_id);
            $project = $expense->project_id === null
                ? null
                : Project::query()->lockForUpdate()->findOrFail($expense->project_id);
            $contract = $expense->contract_id === null
                ? null
                : Contract::query()->lockForUpdate()->findOrFail($expense->contract_id);
            $transitions = collect();
            if ($project !== null) {
                $transitions = $project->transitions()->orderBy('effective_date')->orderBy('id')->lockForUpdate()->get();
                $project->classifications()->where('exercise_id', $exercise->id)->lockForUpdate()->get();
            }
            $lockedExpense = Expense::query()->lockForUpdate()->findOrFail($expense->id);
            Gate::forUser($actor)->authorize('create', [ExpenseLine::class, $lockedExpense]);
            if ($lockedExpense->origin === 'system') {
                throw ValidationException::withMessages(['expense' => 'Le Righe della Stima di sistema non sono modificabili manualmente.']);
            }

            $existing = AuditEvent::query()->where('operation_id', $operationId)->first();
            if ($existing !== null) {
                if ($existing->eventType() !== AuditEventType::ExpenseLineCreated
                    || $existing->subject_type !== ExpenseLine::class
                    || $existing->company_id !== $company->id) {
                    throw ValidationException::withMessages(['operation_id' => 'Identificativo operazione già utilizzato.']);
                }

                return ExpenseLine::query()->findOrFail($existing->subject_id);
            }

            $this->assertMutable($lockedExpense, $exercise);
            $allocationBefore = $lockedExpense->allocation();
            $actualBefore = $lockedExpense->actual();
            $validated = ManualExpenseLine::validate($input, $company, $exercise);
            $changeReason = $this->nullableTrim($input['change_reason'] ?? null);
            if ($exercise->hasApprovedBudget()
                && $validated['type'] === ExpenseLineType::Estimate->value
                && $changeReason === null) {
                throw ValidationException::withMessages([
                    'change_reason' => 'Il motivo è obbligatorio per modificare una Stima dopo un Budget approvato.',
                ]);
            }
            $projectContext = null;
            $contractContext = null;
            $varianceBefore = null;
            $openingTransition = null;
            if ($project !== null) {
                $projectContext = ProjectExpenseActivity::validate($project, $exercise, $company, [$validated], $input);
                $varianceBefore = ProjectExpenseActivity::annualVariance($project, $exercise);
                $openingTransition = app(ProjectExpenseOpening::class)->create($project, $company, $actor, $projectContext, $transitions);
            }
            if ($contract !== null) {
                $contract->setRelation('lifecycleFacts', $contract->lifecycleFacts()->orderBy('id')->lockForUpdate()->get());
                $contract->setRelation('renewalConfigurations', $contract->renewalConfigurations()->orderBy('id')->lockForUpdate()->get());
                $contractContext = ContractExpenseActivity::validate($contract, $exercise, $company, [$validated], $input);
            }
            $line = $lockedExpense->lines()->create($validated);
            if ($project !== null) {
                $varianceAfter = ProjectExpenseActivity::annualVariance($project, $exercise);
                ProjectExpenseActivity::assertOverspendNote($company, $projectContext, $varianceBefore, $varianceAfter);
                $project->increment('revision', $openingTransition === null ? 1 : 2);
            }
            $contract?->increment('revision');
            $lockedExpense->increment('revision');
            $exercise->increment('revision');
            $lockedExpense->refresh();

            $newValue = ExpenseAuditSnapshot::line($line);
            if ($project !== null) {
                $newValue['project_activity'] = [
                    'actual_kind' => $projectContext['actual_kind']?->value,
                    'activity_note' => $projectContext['activity_note'],
                    'opening_transition' => $openingTransition === null ? null : ProjectAuditSnapshot::transition($openingTransition),
                    'overspend' => ProjectAuditSnapshot::overspend($varianceBefore, ProjectExpenseActivity::annualVariance($project, $exercise)),
                    'overspend_note' => $projectContext['overspend_note'],
                ];
            }
            if ($contractContext !== null) {
                $newValue['contract_activity'] = [
                    'actual_kind' => $contractContext['actual_kind']->value,
                    'activity_note' => $contractContext['activity_note'],
                    'cycle_matching' => null,
                ];
            }

            AuditEvent::query()->create([
                'operation_id' => $operationId,
                'company_id' => $company->id,
                'actor_id' => $actor->id,
                'event_type' => AuditEventType::ExpenseLineCreated,
                'subject_type' => ExpenseLine::class,
                'subject_id' => $line->id,
                'affected_exercise_ids' => [$exercise->id],
                'effective_from' => now($company->timezone)->toDateString(),
                'previous_value' => null,
                'new_value' => $newValue,
                'allocated_impact_by_exercise' => ExpenseAuditSnapshot::impact($exercise->id, Decimal::subtract($lockedExpense->allocation(), $allocationBefore)),
                'actual_impact_by_exercise' => ExpenseAuditSnapshot::impact($exercise->id, Decimal::subtract($lockedExpense->actual(), $actualBefore)),
                'reason' => $changeReason
                    ?? ($contractContext !== null ? $contractContext['activity_note'] : ($projectContext === null ? null : ($projectContext['activity_note'] ?? $projectContext['overspend_note']))),
                'reference_type' => $contract !== null ? Contract::class : ($project === null ? Expense::class : Project::class),
                'reference_id' => $contract !== null ? $contract->id : ($project === null ? $lockedExpense->id : $project->id),
            ]);

            return $line;
        });
    }

    private function assertMutable(Expense $expense, Exercise $exercise): void
    {
        if (! $exercise->isOpen()) {
            throw ValidationException::withMessages(['expense' => 'L’Esercizio deve essere Aperto.']);
        }
        if ($expense->isReversed()) {
            throw ValidationException::withMessages(['expense' => 'Ripristinare la Spesa prima di modificare le Righe.']);
        }
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
