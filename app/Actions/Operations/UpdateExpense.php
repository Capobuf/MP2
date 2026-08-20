<?php

namespace App\Actions\Operations;

use App\Domain\Company\AuditEventType;
use App\Domain\Expenses\ExpenseAuditSnapshot;
use App\Domain\Expenses\ExpenseImpactPlan;
use App\Domain\Projects\ProjectActualKind;
use App\Domain\Projects\ProjectAuditSnapshot;
use App\Domain\Projects\ProjectExpenseActivity;
use App\Models\AuditEvent;
use App\Models\Company;
use App\Models\CostCenter;
use App\Models\Exercise;
use App\Models\Expense;
use App\Models\Project;
use App\Models\Supplier;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class UpdateExpense
{
    /** @param array<string, mixed> $input */
    public function preview(User $actor, Expense $expense, array $input): ExpenseImpactPlan
    {
        Gate::forUser($actor)->authorize('update', $expense);
        if ($expense->origin === 'system') {
            throw ValidationException::withMessages(['expense' => 'La Stima di sistema non può essere spostata o riclassificata manualmente.']);
        }
        $normalized = $this->normalizeClassification($expense, $input);
        $source = $expense->exercise;
        $target = Exercise::query()->find($normalized['exercise_id']);
        $sourceProject = $expense->project;
        $targetProject = $normalized['project_id'] === null ? null : Project::query()->find($normalized['project_id']);
        $this->validateExercisesAndReferences($expense, $source, $target, $sourceProject, $targetProject, $normalized);

        return ExpenseImpactPlan::build(
            $expense,
            $source,
            $target,
            $normalized['supplier_id'],
            $normalized['direct_cost_center_id'],
            $normalized['reason'],
            $sourceProject,
            $targetProject,
            $normalized['actual_kind'],
            $normalized['activity_note'],
            $normalized['open_project'],
            $normalized['overspend_note'],
        );
    }

    public function confirm(User $actor, Expense $expense, ExpenseImpactPlan $preview, string $operationId): Expense
    {
        Validator::make(['operation_id' => $operationId], ['operation_id' => ['required', 'uuid']])->validate();

        return DB::transaction(function () use ($actor, $expense, $preview, $operationId): Expense {
            $company = Company::query()->lockForUpdate()->findOrFail($expense->company_id);
            $exerciseIds = array_values(array_unique([$preview->sourceExerciseId, $preview->targetExerciseId]));
            sort($exerciseIds);
            /** @var array<int, Exercise> $exercises */
            $exercises = Exercise::query()->whereIn('id', $exerciseIds)->orderBy('id')->lockForUpdate()->get()->keyBy('id')->all();
            $projectIds = array_values(array_unique(array_filter([$preview->sourceProjectId, $preview->targetProjectId])));
            sort($projectIds);
            /** @var array<int, Project> $projects */
            $projects = Project::query()->whereIn('id', $projectIds)->orderBy('id')->lockForUpdate()->get()->keyBy('id')->all();
            $transitionRows = [];
            foreach ($projects as $lockedProject) {
                $transitionRows[$lockedProject->id] = $lockedProject->transitions()->orderBy('effective_date')->orderBy('id')->lockForUpdate()->get();
                $lockedProject->classifications()->whereIn('exercise_id', $exerciseIds)->orderBy('exercise_id')->lockForUpdate()->get();
            }
            $lockedExpense = Expense::query()->lockForUpdate()->findOrFail($expense->id);
            $lines = $lockedExpense->lines()->orderBy('id')->lockForUpdate()->get();
            Gate::forUser($actor)->authorize('update', $lockedExpense);
            if ($lockedExpense->origin === 'system') {
                throw ValidationException::withMessages(['expense' => 'La Stima di sistema non può essere spostata o riclassificata manualmente.']);
            }

            $existing = AuditEvent::query()->where('operation_id', $operationId)->first();
            if ($existing !== null) {
                if ($existing->eventType() !== AuditEventType::ExpenseMovedOrReclassified
                    || $existing->subject_type !== Expense::class
                    || $existing->subject_id !== $lockedExpense->id) {
                    throw ValidationException::withMessages(['operation_id' => 'Identificativo operazione già utilizzato.']);
                }

                return $lockedExpense;
            }

            if ($preview->expenseId !== $lockedExpense->id || $preview->expenseRevision !== $lockedExpense->revision) {
                throw ValidationException::withMessages(['preview' => 'La Spesa è cambiata: calcolare una nuova anteprima.']);
            }
            foreach ($preview->exerciseRevisions as $id => $revision) {
                if (! isset($exercises[(int) $id]) || $exercises[(int) $id]->revision !== $revision) {
                    throw ValidationException::withMessages(['preview' => 'Un Esercizio è cambiato: calcolare una nuova anteprima.']);
                }
            }
            foreach ($preview->projectRevisions as $id => $revision) {
                if (! isset($projects[(int) $id]) || $projects[(int) $id]->revision !== $revision) {
                    throw ValidationException::withMessages(['preview' => 'Un Progetto è cambiato: calcolare una nuova anteprima.']);
                }
            }

            $source = $exercises[$preview->sourceExerciseId] ?? null;
            $target = $exercises[$preview->targetExerciseId] ?? null;
            $sourceProject = $preview->sourceProjectId === null ? null : ($projects[$preview->sourceProjectId] ?? null);
            $targetProject = $preview->targetProjectId === null ? null : ($projects[$preview->targetProjectId] ?? null);
            $normalized = [
                'exercise_id' => $preview->targetExerciseId,
                'supplier_id' => $preview->supplierId,
                'direct_cost_center_id' => $preview->directCostCenterId,
                'reason' => $preview->reason,
                'project_id' => $preview->targetProjectId,
                'actual_kind' => $preview->actualKind,
                'activity_note' => $preview->activityNote,
                'open_project' => $preview->openProject,
                'overspend_note' => $preview->overspendNote,
                'direct_cost_center_supplied' => true,
            ];
            $projectContext = $this->validateExercisesAndReferences($lockedExpense, $source, $target, $sourceProject, $targetProject, $normalized, lockReferences: true);
            $current = ExpenseImpactPlan::build($lockedExpense, $source, $target, $preview->supplierId, $preview->directCostCenterId, $preview->reason, $sourceProject, $targetProject, $preview->actualKind, $preview->activityNote, $preview->openProject, $preview->overspendNote);
            if (! hash_equals($preview->fingerprint(), $current->fingerprint())) {
                throw ValidationException::withMessages(['preview' => 'L’impatto è cambiato: calcolare una nuova anteprima.']);
            }

            $before = ExpenseAuditSnapshot::expense($lockedExpense, true);
            $openingTransition = $targetProject === null || $projectContext === null
                ? null
                : app(ProjectExpenseOpening::class)->create($targetProject, $company, $actor, $projectContext, $transitionRows[$targetProject->id]);
            $overspendContext = $projectContext ?? [
                'actual_kind' => $preview->actualKind === null ? null : ProjectActualKind::from($preview->actualKind),
                'activity_note' => $preview->activityNote,
                'open_project' => false,
                'overspend_note' => $preview->overspendNote,
                'today' => now($company->timezone)->toDateString(),
            ];
            foreach ($preview->projectImpacts as $impact) {
                ProjectExpenseActivity::assertOverspendNote($company, $overspendContext, (string) $impact['variance_before'], (string) $impact['variance_after']);
            }
            $lockedExpense->fill([
                'exercise_id' => $preview->targetExerciseId,
                'project_id' => $preview->targetProjectId,
                'supplier_id' => $preview->supplierId,
                'direct_cost_center_id' => $preview->targetProjectId === null ? $preview->directCostCenterId : null,
            ]);
            if (! $lockedExpense->isDirty()) {
                return $lockedExpense;
            }
            $lockedExpense->revision++;
            $lockedExpense->save();
            foreach ($projects as $affectedProject) {
                $affectedProject->increment('revision', $openingTransition !== null && $affectedProject->is($targetProject) ? 2 : 1);
            }
            foreach ($exerciseIds as $exerciseId) {
                $exercises[$exerciseId]->increment('revision');
            }
            $lockedExpense->refresh();

            $newValue = ExpenseAuditSnapshot::expense($lockedExpense, true);
            $newValue['ownership_impact'] = [
                'source_project_id' => $preview->sourceProjectId,
                'target_project_id' => $preview->targetProjectId,
                'source_cost_center_id' => $preview->sourceCostCenterId,
                'target_cost_center_id' => $preview->targetCostCenterId,
                'preserved_line_ids' => $preview->lineIds,
                'project_impacts' => collect($preview->projectImpacts)
                    ->map(fn (array $impact): array => [
                        ...$impact,
                        'overspend' => ProjectAuditSnapshot::overspend(
                            (string) $impact['variance_before'],
                            (string) $impact['variance_after'],
                        ),
                    ])->all(),
                'actual_kind' => $preview->actualKind,
                'activity_note' => $preview->activityNote,
                'opening_transition' => $openingTransition === null ? null : ProjectAuditSnapshot::transition($openingTransition),
                'overspend_note' => $preview->overspendNote,
            ];
            AuditEvent::query()->create([
                'operation_id' => $operationId,
                'company_id' => $company->id,
                'actor_id' => $actor->id,
                'event_type' => AuditEventType::ExpenseMovedOrReclassified,
                'subject_type' => Expense::class,
                'subject_id' => $lockedExpense->id,
                'affected_exercise_ids' => $exerciseIds,
                'effective_from' => now($company->timezone)->toDateString(),
                'previous_value' => $before,
                'new_value' => $newValue,
                'allocated_impact_by_exercise' => $preview->allocatedImpact(),
                'actual_impact_by_exercise' => $preview->actualImpact(),
                'reason' => $preview->reason,
                'reference_type' => ($targetProject ?? $sourceProject) === null ? null : Project::class,
                'reference_id' => ($targetProject ?? $sourceProject)?->id,
            ]);

            return $lockedExpense;
        });
    }

    /** @param array<string, mixed> $input */
    public function updateDetails(User $actor, Expense $expense, array $input, string $operationId): Expense
    {
        $normalized = [
            'description' => is_string($input['description'] ?? null) ? trim($input['description']) : ($input['description'] ?? null),
            'notes' => $this->nullableTrim($input['notes'] ?? null),
            'operation_id' => $operationId,
        ];
        /** @var array{description: string, notes: ?string, operation_id: string} $validated */
        $validated = Validator::make($normalized, [
            'description' => ['required', 'string', 'max:255'],
            'notes' => ['nullable', 'string'],
            'operation_id' => ['required', 'uuid'],
        ])->validate();

        return DB::transaction(function () use ($actor, $expense, $validated): Expense {
            $company = Company::query()->lockForUpdate()->findOrFail($expense->company_id);
            $exercise = Exercise::query()->lockForUpdate()->findOrFail($expense->exercise_id);
            $project = $expense->project_id === null
                ? null
                : Project::query()->lockForUpdate()->findOrFail($expense->project_id);
            $lockedExpense = Expense::query()->lockForUpdate()->findOrFail($expense->id);
            Gate::forUser($actor)->authorize('update', $lockedExpense);
            if ($lockedExpense->origin === 'system') {
                throw ValidationException::withMessages(['expense' => 'La Stima di sistema non è modificabile manualmente.']);
            }
            $existing = AuditEvent::query()->where('operation_id', $validated['operation_id'])->first();
            if ($existing !== null) {
                if ($existing->eventType() !== AuditEventType::ExpenseUpdated || $existing->subject_id !== $lockedExpense->id) {
                    throw ValidationException::withMessages(['operation_id' => 'Identificativo operazione già utilizzato.']);
                }

                return $lockedExpense;
            }
            if (! $exercise->isOpen()) {
                throw ValidationException::withMessages(['expense' => 'L’Esercizio deve essere Aperto.']);
            }
            $before = ExpenseAuditSnapshot::expense($lockedExpense);
            $lockedExpense->fill(['description' => $validated['description'], 'notes' => $validated['notes']]);
            if (! $lockedExpense->isDirty()) {
                return $lockedExpense;
            }
            $lockedExpense->revision++;
            $lockedExpense->save();
            $project?->increment('revision');

            AuditEvent::query()->create([
                'operation_id' => $validated['operation_id'],
                'company_id' => $company->id,
                'actor_id' => $actor->id,
                'event_type' => AuditEventType::ExpenseUpdated,
                'subject_type' => Expense::class,
                'subject_id' => $lockedExpense->id,
                'affected_exercise_ids' => [$exercise->id],
                'effective_from' => now($company->timezone)->toDateString(),
                'previous_value' => $before,
                'new_value' => ExpenseAuditSnapshot::expense($lockedExpense),
                'allocated_impact_by_exercise' => ExpenseAuditSnapshot::impact($exercise->id, '0'),
                'actual_impact_by_exercise' => ExpenseAuditSnapshot::impact($exercise->id, '0'),
                'reference_type' => $project === null ? null : Project::class,
                'reference_id' => $project?->id,
            ]);

            return $lockedExpense;
        });
    }

    /** @param array<string, mixed> $input
     * @return array{exercise_id: int, supplier_id: ?int, direct_cost_center_id: ?int, reason: ?string, project_id: ?int, actual_kind: ?string, activity_note: ?string, open_project: bool, overspend_note: ?string, direct_cost_center_supplied: bool}
     */
    private function normalizeClassification(Expense $expense, array $input): array
    {
        if (array_key_exists('contract_id', $input) && $input['contract_id'] !== null) {
            throw ValidationException::withMessages(['contract_id' => 'Il contenitore Contratto non è disponibile in questa versione.']);
        }
        /** @var array{exercise_id: int, supplier_id: ?int, direct_cost_center_id: ?int, reason: ?string, project_id: ?int, actual_kind: ?string, activity_note: ?string, open_project: bool, overspend_note: ?string, direct_cost_center_supplied: bool} $validated */
        $validated = Validator::make([
            'exercise_id' => $input['exercise_id'] ?? $expense->exercise_id,
            'supplier_id' => array_key_exists('supplier_id', $input) ? $input['supplier_id'] : $expense->supplier_id,
            'direct_cost_center_id' => array_key_exists('direct_cost_center_id', $input) ? $input['direct_cost_center_id'] : $expense->direct_cost_center_id,
            'reason' => $this->nullableTrim($input['reason'] ?? null),
            'project_id' => array_key_exists('project_id', $input) ? $input['project_id'] : $expense->project_id,
            'actual_kind' => $input['actual_kind'] ?? null,
            'activity_note' => $this->nullableTrim($input['activity_note'] ?? null),
            'open_project' => filter_var($input['open_project'] ?? false, FILTER_VALIDATE_BOOL),
            'overspend_note' => $this->nullableTrim($input['overspend_note'] ?? null),
            'direct_cost_center_supplied' => array_key_exists('direct_cost_center_id', $input),
        ], [
            'exercise_id' => ['required', 'integer'],
            'supplier_id' => ['nullable', 'integer'],
            'direct_cost_center_id' => ['nullable', 'integer'],
            'reason' => ['nullable', 'string'],
            'project_id' => ['nullable', 'integer'],
            'actual_kind' => ['nullable', Rule::enum(ProjectActualKind::class)],
            'activity_note' => ['nullable', 'string'],
            'open_project' => ['boolean'],
            'overspend_note' => ['nullable', 'string'],
            'direct_cost_center_supplied' => ['boolean'],
        ])->validate();

        return $validated;
    }

    /**
     * @param  array{exercise_id: int, supplier_id: ?int, direct_cost_center_id: ?int, reason: ?string, project_id: ?int, actual_kind: ?string, activity_note: ?string, open_project: bool, overspend_note: ?string, direct_cost_center_supplied: bool}  $input
     * @return array{actual_kind: ?ProjectActualKind, activity_note: ?string, open_project: bool, overspend_note: ?string, today: string}|null
     */
    private function validateExercisesAndReferences(Expense $expense, ?Exercise $source, ?Exercise $target, ?Project $sourceProject, ?Project $targetProject, array $input, bool $lockReferences = false): ?array
    {
        if ($source === null || $target === null || $source->company_id !== $expense->company_id || $target->company_id !== $expense->company_id) {
            throw ValidationException::withMessages(['exercise_id' => 'Esercizio non disponibile per questa Azienda.']);
        }
        if (! $source->isOpen() || ! $target->isOpen()) {
            throw ValidationException::withMessages(['exercise_id' => 'Sorgente e destinazione devono essere Esercizi Aperti.']);
        }
        if ($expense->isReversed()) {
            throw ValidationException::withMessages(['expense' => 'Ripristinare la Spesa prima di spostarla o riclassificarla.']);
        }
        if ($targetProject !== null && $targetProject->company_id !== $expense->company_id) {
            throw ValidationException::withMessages(['project_id' => 'Progetto non disponibile per questa Azienda.']);
        }
        if ($input['project_id'] !== null && $targetProject === null) {
            throw ValidationException::withMessages(['project_id' => 'Progetto non disponibile per questa Azienda.']);
        }
        if ($sourceProject !== null && ! $source->is($target)) {
            throw ValidationException::withMessages(['exercise_id' => 'Una Spesa di Progetto non cambia Esercizio tramite lo spostamento generico.']);
        }
        $ownerChanges = $sourceProject?->id !== $targetProject?->id;
        if ($ownerChanges && $expense->hasActuals() && $input['reason'] === null) {
            throw ValidationException::withMessages(['reason' => 'Il motivo è obbligatorio per spostare una Spesa con Effettivi.']);
        }
        if (! $source->is($target) && $expense->hasActuals()) {
            if ($input['reason'] === null) {
                throw ValidationException::withMessages(['reason' => 'Il motivo è obbligatorio per spostare una Spesa con Effettivi.']);
            }
            if ($target->year > now($expense->company->timezone)->year) {
                throw ValidationException::withMessages(['exercise_id' => 'Una Spesa con Effettivi non può essere spostata in un anno futuro.']);
            }
        }

        $this->validateReference(Supplier::class, $input['supplier_id'], $expense->supplier_id, $expense->company_id, 'supplier_id', $lockReferences);
        if ($targetProject === null) {
            if ($sourceProject !== null && ! $input['direct_cost_center_supplied']) {
                throw ValidationException::withMessages(['direct_cost_center_id' => 'Scegliere esplicitamente un Centro di Costo diretto o Non classificata.']);
            }
            $this->validateReference(CostCenter::class, $input['direct_cost_center_id'], $expense->direct_cost_center_id, $expense->company_id, 'direct_cost_center_id', $lockReferences);
        }

        if (! $ownerChanges || $targetProject === null) {
            return null;
        }
        $activeLines = $expense->lines()->active()->orderBy('id')->get()->map(fn ($line): array => ['type' => $line->lineType()]);

        return ProjectExpenseActivity::validate($targetProject, $target, $expense->company, $activeLines, $input);
    }

    /** @param class-string<Supplier|CostCenter> $model */
    private function validateReference(string $model, ?int $id, ?int $currentId, int $companyId, string $field, bool $lock): void
    {
        if ($id === null) {
            return;
        }
        $query = $model::query();
        if ($lock) {
            $query->lockForUpdate();
        }
        $record = $query->find($id);
        if ($record === null || $record->company_id !== $companyId || ($id !== $currentId && $record->isArchived())) {
            throw ValidationException::withMessages([$field => 'Il riferimento selezionato non è attivo in questa Azienda.']);
        }
    }

    private function nullableTrim(mixed $value): mixed
    {
        if (! is_string($value)) {
            return $value;
        }
        $value = trim($value);

        return $value === '' ? null : $value;
    }
}
