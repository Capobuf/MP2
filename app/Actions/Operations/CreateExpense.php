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
use App\Models\CostCenter;
use App\Models\Exercise;
use App\Models\Expense;
use App\Models\Project;
use App\Models\Supplier;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class CreateExpense
{
    /** @param array<string, mixed> $input */
    public function execute(User $actor, Company $company, array $input, string $operationId): Expense
    {
        if (($input['origin'] ?? 'manual') !== 'manual') {
            throw ValidationException::withMessages(['origin' => 'Le Stime di sistema possono essere create soltanto dal motore Contratti.']);
        }

        $normalized = [
            ...$input,
            'supplier_id' => $input['supplier_id'] ?? null,
            'direct_cost_center_id' => $input['direct_cost_center_id'] ?? null,
            'project_id' => $input['project_id'] ?? null,
            'contract_id' => $input['contract_id'] ?? null,
            'actual_kind' => $input['actual_kind'] ?? null,
            'activity_note' => $input['activity_note'] ?? null,
            'open_project' => $input['open_project'] ?? false,
            'overspend_note' => $input['overspend_note'] ?? null,
            'change_reason' => $this->nullableTrim($input['change_reason'] ?? null),
            'description' => $this->trim($input['description'] ?? null),
            'notes' => $this->nullableTrim($input['notes'] ?? null),
            'operation_id' => $operationId,
        ];
        /** @var array{exercise_id: int, supplier_id: ?int, direct_cost_center_id: ?int, project_id: ?int, contract_id: ?int, actual_kind: ?string, activity_note: ?string, open_project: bool, overspend_note: ?string, change_reason: ?string, description: string, notes: ?string, lines: array<int, array<string, mixed>>, operation_id: string} $validated */
        $validated = Validator::make($normalized, [
            'exercise_id' => ['required', 'integer'],
            'supplier_id' => ['nullable', 'integer'],
            'direct_cost_center_id' => ['nullable', 'integer'],
            'project_id' => ['nullable', 'integer'],
            'contract_id' => ['nullable', 'integer'],
            'actual_kind' => ['nullable', 'string'],
            'activity_note' => ['nullable', 'string'],
            'open_project' => ['boolean'],
            'overspend_note' => ['nullable', 'string'],
            'change_reason' => ['nullable', 'string'],
            'description' => ['required', 'string', 'max:255'],
            'notes' => ['nullable', 'string'],
            'lines' => ['required', 'array', 'min:1'],
            'lines.*' => ['required', 'array'],
            'operation_id' => ['required', 'uuid'],
        ])->validate();

        return DB::transaction(function () use ($actor, $company, $validated): Expense {
            $lockedCompany = Company::query()->lockForUpdate()->findOrFail($company->id);
            Gate::forUser($actor)->authorize('create', [Expense::class, $lockedCompany]);

            $existing = AuditEvent::query()->where('operation_id', $validated['operation_id'])->first();
            if ($existing !== null) {
                if ($existing->eventType() !== AuditEventType::ExpenseCreated
                    || $existing->subject_type !== Expense::class
                    || $existing->company_id !== $lockedCompany->id) {
                    throw ValidationException::withMessages(['operation_id' => 'Identificativo operazione già utilizzato.']);
                }

                return Expense::query()->findOrFail($existing->subject_id);
            }

            $exercise = Exercise::query()->lockForUpdate()->find($validated['exercise_id']);
            if ($exercise === null || $exercise->company_id !== $lockedCompany->id) {
                throw ValidationException::withMessages(['exercise_id' => 'Esercizio non disponibile per questa Azienda.']);
            }
            if (! $exercise->isOpen()) {
                throw ValidationException::withMessages(['exercise_id' => 'L’Esercizio deve essere Aperto.']);
            }

            $project = null;
            $contract = null;
            $projectContext = null;
            $contractContext = null;
            $transitions = collect();
            $varianceBefore = null;
            if ($validated['project_id'] !== null) {
                $project = Project::query()->lockForUpdate()->find($validated['project_id']);
                if ($project === null || $project->company_id !== $lockedCompany->id) {
                    throw ValidationException::withMessages(['project_id' => 'Progetto non disponibile per questa Azienda.']);
                }
                $transitions = $project->transitions()->orderBy('effective_date')->orderBy('id')->lockForUpdate()->get();
                $project->classifications()->where('exercise_id', $exercise->id)->lockForUpdate()->get();
                if ($validated['direct_cost_center_id'] !== null) {
                    throw ValidationException::withMessages(['direct_cost_center_id' => 'Una Spesa di Progetto eredita il Centro di Costo annuale e non può averne uno diretto.']);
                }
            }
            if ($validated['contract_id'] !== null) {
                if ($project !== null) {
                    throw ValidationException::withMessages(['contract_id' => 'Una Spesa non può appartenere contemporaneamente a un Progetto e a un Contratto.']);
                }
                $contract = Contract::query()->lockForUpdate()->find($validated['contract_id']);
                if ($contract === null || $contract->company_id !== $lockedCompany->id) {
                    throw ValidationException::withMessages(['contract_id' => 'Contratto non disponibile per questa Azienda.']);
                }
                $contract->setRelation('lifecycleFacts', $contract->lifecycleFacts()->orderBy('id')->lockForUpdate()->get());
                $contract->setRelation('renewalConfigurations', $contract->renewalConfigurations()->orderBy('id')->lockForUpdate()->get());
                $contract->classifications()->where('exercise_id', $exercise->id)->lockForUpdate()->get();
                if ($validated['direct_cost_center_id'] !== null) {
                    throw ValidationException::withMessages(['direct_cost_center_id' => 'Una Spesa di Contratto eredita il Centro di Costo annuale.']);
                }
            }

            $supplier = $contract === null
                ? $this->activeReference(Supplier::class, $validated['supplier_id'], $lockedCompany, 'supplier_id')
                : $contract->supplier;
            $costCenter = ($project === null && $contract === null)
                ? $this->activeReference(CostCenter::class, $validated['direct_cost_center_id'], $lockedCompany, 'direct_cost_center_id')
                : null;
            $lines = array_map(
                fn (array $line): array => ManualExpenseLine::validate($line, $lockedCompany, $exercise),
                $validated['lines'],
            );
            $allocation = Decimal::sum(array_map(
                fn (array $line): string => $line['type'] === ExpenseLineType::Estimate->value ? (string) $line['amount'] : '0.00',
                $lines,
            ));
            $actual = Decimal::sum(array_map(
                fn (array $line): string => $line['type'] === ExpenseLineType::Actual->value ? (string) $line['amount'] : '0.00',
                $lines,
            ));
            if ($exercise->hasApprovedBudget()
                && (Decimal::compare($allocation, '0.00') !== 0 || Decimal::compare($actual, '0.00') !== 0)
                && $validated['change_reason'] === null) {
                throw ValidationException::withMessages([
                    'change_reason' => 'Il motivo è obbligatorio perché l’Esercizio ha già un Budget approvato.',
                ]);
            }

            $openingTransition = null;
            if ($project !== null) {
                $projectContext = ProjectExpenseActivity::validate($project, $exercise, $lockedCompany, $lines, $validated);
                $varianceBefore = ProjectExpenseActivity::annualVariance($project, $exercise);
                $openingTransition = app(ProjectExpenseOpening::class)->create($project, $lockedCompany, $actor, $projectContext, $transitions);
            }
            if ($contract !== null) {
                $contractContext = ContractExpenseActivity::validate($contract, $exercise, $lockedCompany, $lines, $validated);
            }

            $expense = Expense::query()->create([
                'company_id' => $lockedCompany->id,
                'exercise_id' => $exercise->id,
                'project_id' => $project?->id,
                'contract_id' => $contract?->id,
                'origin' => 'manual',
                'supplier_id' => $supplier?->id,
                'direct_cost_center_id' => $costCenter?->id,
                'description' => $validated['description'],
                'notes' => $validated['notes'],
            ]);
            foreach ($lines as $line) {
                $expense->lines()->create($line);
            }
            if ($project !== null) {
                $varianceAfter = ProjectExpenseActivity::annualVariance($project, $exercise);
                ProjectExpenseActivity::assertOverspendNote($lockedCompany, $projectContext, $varianceBefore, $varianceAfter);
                $project->increment('revision', $openingTransition === null ? 1 : 2);
            }
            $contract?->increment('revision');
            $exercise->increment('revision');
            $expense->refresh();

            $newValue = ExpenseAuditSnapshot::expense($expense, true);
            if ($project !== null) {
                $newValue['project_activity'] = [
                    'actual_kind' => $projectContext['actual_kind']?->value,
                    'activity_note' => $projectContext['activity_note'],
                    'opening_transition' => $openingTransition === null ? null : ProjectAuditSnapshot::transition($openingTransition),
                    'overspend' => ProjectAuditSnapshot::overspend(
                        $varianceBefore,
                        ProjectExpenseActivity::annualVariance($project, $exercise),
                    ),
                    'overspend_note' => $projectContext['overspend_note'],
                ];
            }
            if ($contract !== null) {
                $newValue['contract_activity'] = [
                    'actual_kind' => $contractContext['actual_kind']->value,
                    'activity_note' => $contractContext['activity_note'],
                    'cycle_matching' => null,
                ];
            }

            AuditEvent::query()->create([
                'operation_id' => $validated['operation_id'],
                'company_id' => $lockedCompany->id,
                'actor_id' => $actor->id,
                'event_type' => AuditEventType::ExpenseCreated,
                'subject_type' => Expense::class,
                'subject_id' => $expense->id,
                'affected_exercise_ids' => [$exercise->id],
                'effective_from' => now($lockedCompany->timezone)->toDateString(),
                'previous_value' => null,
                'new_value' => $newValue,
                'allocated_impact_by_exercise' => ExpenseAuditSnapshot::impact($exercise->id, $expense->allocation()),
                'actual_impact_by_exercise' => ExpenseAuditSnapshot::impact($exercise->id, $expense->actual()),
                'reason' => $validated['change_reason']
                    ?? ($contractContext !== null ? $contractContext['activity_note'] : ($projectContext === null ? null : ($projectContext['activity_note'] ?? $projectContext['overspend_note']))),
                'reference_type' => $contract !== null ? Contract::class : ($project === null ? null : Project::class),
                'reference_id' => $contract !== null ? $contract->id : $project?->id,
            ]);

            return $expense;
        });
    }

    /** @template TModel of Supplier|CostCenter
     * @param  class-string<TModel>  $model
     * @return TModel|null
     */
    private function activeReference(string $model, ?int $id, Company $company, string $field): Supplier|CostCenter|null
    {
        if ($id === null) {
            return null;
        }

        $record = $model::query()->lockForUpdate()->find($id);
        if ($record === null || $record->company_id !== $company->id || $record->isArchived()) {
            throw ValidationException::withMessages([$field => 'Il riferimento selezionato non è attivo in questa Azienda.']);
        }

        return $record;
    }

    private function trim(mixed $value): mixed
    {
        return is_string($value) ? trim($value) : $value;
    }

    private function nullableTrim(mixed $value): mixed
    {
        $value = $this->trim($value);

        return $value === '' ? null : $value;
    }
}
