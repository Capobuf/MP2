<?php

namespace App\Actions\Operations;

use App\Domain\Company\AuditEventType;
use App\Domain\Contracts\ContractExpenseActivity;
use App\Domain\Expenses\Decimal;
use App\Domain\Expenses\ExpenseAuditSnapshot;
use App\Domain\Projects\ProjectAuditSnapshot;
use App\Domain\Projects\ProjectExpenseActivity;
use App\Models\AuditEvent;
use App\Models\Company;
use App\Models\Contract;
use App\Models\Exercise;
use App\Models\Expense;
use App\Models\Project;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class SetExpenseReversed
{
    /** @param array<string, mixed> $context */
    public function execute(User $actor, Expense $expense, bool $reversed, string $reason, string $operationId, array $context = []): Expense
    {
        /** @var array{reason: string, operation_id: string} $validated */
        $validated = Validator::make([
            'reason' => trim($reason),
            'operation_id' => $operationId,
        ], [
            'reason' => ['required', 'string'],
            'operation_id' => ['required', 'uuid'],
        ])->validate();

        return DB::transaction(function () use ($actor, $expense, $reversed, $validated, $context): Expense {
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
            $lines = $lockedExpense->lines()->orderBy('id')->lockForUpdate()->get();
            Gate::forUser($actor)->authorize('update', $lockedExpense);
            if ($lockedExpense->origin === 'system') {
                throw ValidationException::withMessages(['expense' => 'La Stima di sistema non è stornabile o ripristinabile manualmente.']);
            }
            $eventType = $reversed ? AuditEventType::ExpenseReversed : AuditEventType::ExpenseRestored;

            $existing = AuditEvent::query()->where('operation_id', $validated['operation_id'])->first();
            if ($existing !== null) {
                if ($existing->eventType() !== $eventType || $existing->subject_type !== Expense::class || $existing->subject_id !== $lockedExpense->id) {
                    throw ValidationException::withMessages(['operation_id' => 'Identificativo operazione già utilizzato.']);
                }

                return $lockedExpense;
            }

            if ($reversed === $lockedExpense->isReversed()) {
                return $lockedExpense;
            }
            if (! $exercise->isOpen()) {
                throw ValidationException::withMessages(['expense' => 'L’Esercizio deve essere Aperto.']);
            }
            if ($reversed && $lockedExpense->hasActuals()) {
                throw ValidationException::withMessages(['expense' => 'La Spesa contiene Effettivi attivi non nulli e non può essere stornata.']);
            }

            $allocationBefore = $lockedExpense->allocation();
            $actualBefore = $lockedExpense->actual();
            $projectContext = null;
            $contractContext = null;
            $varianceBefore = null;
            $openingTransition = null;
            $overspendNote = $this->nullableTrim($context['overspend_note'] ?? null);
            if ($project !== null) {
                $varianceBefore = ProjectExpenseActivity::annualVariance($project, $exercise);
                if (! $reversed) {
                    $activeLines = $lines->reject->isAnnulled()->map(fn ($line): array => [
                        'type' => $line->lineType(),
                    ]);
                    $hasActualLines = $activeLines->contains(fn (array $line): bool => $line['type']->value === 'actual');
                    $projectContext = ProjectExpenseActivity::validate($project, $exercise, $company, $activeLines, [
                        ...$context,
                        'activity_note' => $hasActualLines ? ($context['activity_note'] ?? $validated['reason']) : null,
                    ]);
                    $openingTransition = app(ProjectExpenseOpening::class)->create($project, $company, $actor, $projectContext, $transitions);
                }
            }
            if ($contract !== null && ! $reversed) {
                $contract->setRelation('lifecycleFacts', $contract->lifecycleFacts()->orderBy('id')->lockForUpdate()->get());
                $contract->setRelation('renewalConfigurations', $contract->renewalConfigurations()->orderBy('id')->lockForUpdate()->get());
                $activeLines = $lines->reject->isAnnulled()->map(fn ($line): array => ['type' => $line->lineType()]);
                $contractContext = ContractExpenseActivity::validate($contract, $exercise, $company, $activeLines, [
                    ...$context,
                    'activity_note' => $context['activity_note'] ?? $validated['reason'],
                ]);
            }
            $before = ExpenseAuditSnapshot::expense($lockedExpense, true);
            $lockedExpense->reversed_at = $reversed ? now() : null;
            $lockedExpense->revision++;
            $lockedExpense->save();
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
            $contract?->increment('revision');
            $exercise->increment('revision');

            $allocationAfter = $lockedExpense->allocation();
            $actualAfter = $lockedExpense->actual();
            $newValue = ExpenseAuditSnapshot::expense($lockedExpense, true);
            if ($project !== null) {
                $newValue['project_activity'] = [
                    'actual_kind' => ($projectContext['actual_kind'] ?? null)?->value,
                    'activity_note' => $projectContext['activity_note'] ?? null,
                    'opening_transition' => $openingTransition === null ? null : ProjectAuditSnapshot::transition($openingTransition),
                    'overspend' => ProjectAuditSnapshot::overspend($varianceBefore, ProjectExpenseActivity::annualVariance($project, $exercise)),
                    'overspend_note' => $projectContext['overspend_note'] ?? $overspendNote,
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
                'operation_id' => $validated['operation_id'],
                'company_id' => $company->id,
                'actor_id' => $actor->id,
                'event_type' => $eventType,
                'subject_type' => Expense::class,
                'subject_id' => $lockedExpense->id,
                'affected_exercise_ids' => [$exercise->id],
                'effective_from' => now($company->timezone)->toDateString(),
                'previous_value' => $before,
                'new_value' => $newValue,
                'allocated_impact_by_exercise' => ExpenseAuditSnapshot::impact($exercise->id, Decimal::subtract($allocationAfter, $allocationBefore)),
                'actual_impact_by_exercise' => ExpenseAuditSnapshot::impact($exercise->id, Decimal::subtract($actualAfter, $actualBefore)),
                'reason' => $validated['reason'],
                'reference_type' => $contract !== null ? Contract::class : ($project === null ? null : Project::class),
                'reference_id' => $contract !== null ? $contract->id : $project?->id,
            ]);

            return $lockedExpense;
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
