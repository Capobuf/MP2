<?php

namespace App\Actions\LateCorrections;

use App\Domain\Company\AuditEventType;
use App\Domain\Expenses\Decimal;
use App\Domain\Expenses\ExpenseLineType;
use App\Domain\Expenses\ManualExpenseLine;
use App\Domain\LateCorrections\HistoricalExpenseCompatibility;
use App\Models\AuditEvent;
use App\Models\ClosingSnapshot;
use App\Models\Company;
use App\Models\Contract;
use App\Models\CostCenter;
use App\Models\Exercise;
use App\Models\Expense;
use App\Models\ExpenseLine;
use App\Models\LateCorrection;
use App\Models\Project;
use App\Models\Supplier;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

final class RecordLateCorrection
{
    private const SOURCE_TYPES = ['expense', 'project', 'contract'];

    public function __construct(private readonly HistoricalExpenseCompatibility $compatibility) {}

    /** @param array<string, mixed> $input */
    public function execute(User $actor, Exercise $exercise, array $input, string $operationId): LateCorrection
    {
        $normalized = [
            'source_type' => $this->trim($input['source_type'] ?? null),
            'source_origin_id' => $input['source_origin_id'] ?? null,
            'supplier_id' => $input['supplier_id'] ?? null,
            'historical_expense_id' => $input['historical_expense_id'] ?? null,
            'original_expense_line_id' => $input['original_expense_line_id'] ?? null,
            'description' => $this->trim($input['description'] ?? null),
            'notes' => $this->nullableTrim($input['notes'] ?? null),
            'amount' => $this->trim($input['amount'] ?? null),
            'reason' => $this->trim($input['reason'] ?? null),
            'belongs_to_closed_exercise' => $input['belongs_to_closed_exercise'] ?? false,
            'expected_exercise_revision' => $input['expected_exercise_revision'] ?? null,
            'expected_source_revision' => $input['expected_source_revision'] ?? null,
            'expected_expense_revision' => $input['expected_expense_revision'] ?? null,
            'operation_id' => $operationId,
        ];
        $validator = Validator::make($normalized, [
            'source_type' => ['required', 'string', 'in:'.implode(',', self::SOURCE_TYPES)],
            'source_origin_id' => ['required', 'integer', 'min:1'],
            'supplier_id' => ['nullable', 'integer', 'min:1'],
            'historical_expense_id' => ['nullable', 'integer', 'min:1'],
            'original_expense_line_id' => ['nullable', 'integer', 'min:1'],
            'description' => ['nullable', 'string', 'max:255'],
            'notes' => ['nullable', 'string'],
            'amount' => ['required', 'regex:/^-?\d{1,17}(\.\d{1,2})?$/'],
            'reason' => ['required', 'string', 'max:65535'],
            'belongs_to_closed_exercise' => ['accepted'],
            'expected_exercise_revision' => ['required', 'integer', 'min:0'],
            'expected_source_revision' => ['required', 'integer', 'min:0'],
            'expected_expense_revision' => [
                'nullable',
                'integer',
                'min:0',
                Rule::requiredIf(filled($normalized['historical_expense_id'])),
            ],
            'operation_id' => ['required', 'uuid'],
        ]);
        $validator->after(function ($validator) use ($normalized): void {
            if (blank($normalized['expected_exercise_revision'])) {
                $validator->errors()->add('source_type', 'Il token di revisione dell’Esercizio è obbligatorio: ricaricare il contesto prima di confermare.');
            }
            if (blank($normalized['expected_source_revision'])) {
                $validator->errors()->add('source_origin_id', 'Il token di revisione della sorgente storica è obbligatorio: ricaricare il contesto prima di confermare.');
            }
            if (filled($normalized['historical_expense_id']) && blank($normalized['expected_expense_revision'])) {
                $validator->errors()->add('historical_expense_id', 'Il token di revisione della Spesa storica è obbligatorio: ricaricare il contesto prima di confermare.');
            }
        });
        /** @var array<string, mixed> $validated */
        $validated = $validator->validate();

        return DB::transaction(function () use ($actor, $exercise, $validated): LateCorrection {
            $company = Company::query()->lockForUpdate()->findOrFail($exercise->company_id);
            $lockedExercise = Exercise::query()->lockForUpdate()->findOrFail($exercise->id);
            if ($lockedExercise->company_id !== $company->id) {
                throw ValidationException::withMessages(['exercise_id' => 'Esercizio non disponibile per questa Azienda.']);
            }

            Gate::forUser($actor)->authorize('correctClosed', $lockedExercise);
            Gate::forUser($actor)->authorize('create', [LateCorrection::class, $company]);
            if ($lockedExercise->isOpen()) {
                throw ValidationException::withMessages(['exercise_id' => 'La correzione tardiva richiede un Esercizio Chiuso.']);
            }

            $snapshot = ClosingSnapshot::query()
                ->where('company_id', $company->id)
                ->where('exercise_id', $lockedExercise->id)
                ->lockForUpdate()
                ->first();
            if ($snapshot === null) {
                throw ValidationException::withMessages(['closing_snapshot' => 'La Snapshot di Chiusura canonica non è disponibile.']);
            }

            $existing = LateCorrection::query()
                ->where('operation_id', $validated['operation_id'])
                ->lockForUpdate()
                ->first();
            if ($existing !== null) {
                $existing->loadMissing('expenseLine');
                if ($existing->company_id !== $company->id
                    || $existing->exercise_id !== $lockedExercise->id
                    || $existing->closing_snapshot_id !== $snapshot->id
                    || $existing->source_type !== $validated['source_type']
                    || (int) $existing->source_origin_id !== (int) $validated['source_origin_id']
                    || (int) ($existing->original_expense_line_id ?? 0) !== (int) ($validated['original_expense_line_id'] ?? 0)
                    || $existing->reason !== $validated['reason']
                    || Decimal::compare((string) $existing->expenseLine->amount, (string) $validated['amount']) !== 0) {
                    throw ValidationException::withMessages(['operation_id' => 'Identificativo operazione già utilizzato per un altro contesto storico.']);
                }

                return $existing->load(['expenseLine', 'expense', 'closingSnapshot', 'originalExpenseLine', 'recordedBy']);
            }
            if ((int) $validated['expected_exercise_revision'] !== (int) $lockedExercise->revision) {
                throw ValidationException::withMessages(['source_type' => 'L’Esercizio è cambiato: ricaricare il contesto prima di confermare.']);
            }
            $operationEvent = AuditEvent::query()
                ->where('operation_id', $validated['operation_id'])
                ->lockForUpdate()
                ->first();
            if ($operationEvent !== null) {
                throw ValidationException::withMessages(['operation_id' => 'Identificativo operazione già utilizzato.']);
            }

            $source = $this->lockSource($validated, $company, $lockedExercise);
            if ((int) $validated['expected_source_revision'] !== (int) $source->revision) {
                throw ValidationException::withMessages(['source_origin_id' => 'La sorgente storica è cambiata: ricaricare il contesto prima di confermare.']);
            }
            $selectedExpense = $this->lockSelectedExpense($validated['historical_expense_id'] ?? null, $company, $lockedExercise);
            $suppliedSupplier = null;
            if ($validated['supplier_id'] !== null) {
                $suppliedSupplier = Supplier::query()->lockForUpdate()->find((int) $validated['supplier_id']);
                if ($suppliedSupplier === null || $suppliedSupplier->company_id !== $company->id) {
                    throw ValidationException::withMessages(['supplier_id' => 'Il Fornitore storico selezionato non appartiene a questa Azienda.']);
                }
            }
            if ($selectedExpense !== null
                && (int) $validated['expected_expense_revision'] !== (int) $selectedExpense->revision) {
                throw ValidationException::withMessages(['historical_expense_id' => 'La Spesa storica è cambiata: ricaricare il contesto prima di confermare.']);
            }
            $originalLine = $this->lockOriginalLine($validated['original_expense_line_id'] ?? null, $company, $lockedExercise);

            $compatible = $selectedExpense !== null
                && $this->compatibility->accepts(
                    $selectedExpense,
                    $lockedExercise,
                    (string) $validated['source_type'],
                    (int) $validated['source_origin_id'],
                );
            if ($compatible && $suppliedSupplier !== null) {
                throw ValidationException::withMessages(['supplier_id' => 'Il Fornitore storico si seleziona solo per una nuova Spesa tardiva.']);
            }

            $expense = $compatible
                ? $selectedExpense
                : $this->createHistoricalExpense($company, $lockedExercise, $validated, $source);
            $expense->loadMissing(['supplier', 'project', 'contract', 'directCostCenter']);

            $line = $expense->lines()->create(ManualExpenseLine::validate([
                'type' => ExpenseLineType::Actual->value,
                'amount' => $validated['amount'],
                'quantity' => null,
                'unit_amount' => null,
                'unit_of_measure' => null,
                'note' => $validated['notes'] ?? $validated['reason'],
                'amount_warning_acknowledged' => true,
            ], $company, $lockedExercise));

            $expense->increment('revision');
            if ($source instanceof Project || $source instanceof Contract) {
                $source->increment('revision');
            }
            $lockedExercise->increment('revision');

            $correction = LateCorrection::query()->create([
                'company_id' => $company->id,
                'exercise_id' => $lockedExercise->id,
                'closing_snapshot_id' => $snapshot->id,
                'expense_id' => $expense->id,
                'expense_line_id' => $line->id,
                'original_expense_line_id' => $originalLine?->id,
                'recorded_by_id' => $actor->id,
                'operation_id' => $validated['operation_id'],
                'reason' => $validated['reason'],
                'belongs_to_closed_exercise' => true,
                'source_type' => $validated['source_type'],
                'source_origin_id' => $source->id,
                'source_origin_key' => $source->originKey(),
                'source_label' => $this->sourceLabel($source),
                'owner_context' => $this->ownerContext($expense),
                'supplier_context' => $this->supplierContext($expense),
            ]);

            $amount = (string) $line->amount;
            $currentKnowledgeBefore = $this->currentKnowledgeActual($snapshot, $lockedExercise->id, $amount, false, (string) $validated['operation_id']);
            $currentKnowledgeAfter = $this->currentKnowledgeActual($snapshot, $lockedExercise->id, $amount, true, (string) $validated['operation_id']);
            AuditEvent::query()->create([
                'operation_id' => $validated['operation_id'],
                'company_id' => $company->id,
                'actor_id' => $actor->id,
                'event_type' => AuditEventType::LateCorrectionRecorded,
                'subject_type' => LateCorrection::class,
                'subject_id' => $correction->id,
                'affected_exercise_ids' => [$lockedExercise->id],
                'effective_from' => now($company->timezone)->toDateString(),
                'previous_value' => [
                    'closing_actual' => (string) $snapshot->total_closing_actual,
                    'current_knowledge_actual' => $currentKnowledgeBefore,
                    'original_expense_line_id' => $originalLine?->id,
                ],
                'new_value' => [
                    'closing_actual' => (string) $snapshot->total_closing_actual,
                    'current_knowledge_actual' => $currentKnowledgeAfter,
                    'source_type' => $validated['source_type'],
                    'source_origin_id' => $source->id,
                    'source_origin_key' => $source->originKey(),
                    'source_label' => $this->sourceLabel($source),
                    'owner_context' => $correction->owner_context,
                    'supplier_context' => $correction->supplier_context,
                    'original_expense_line_id' => $originalLine?->id,
                    'reason' => $validated['reason'],
                    'belongs_to_closed_exercise' => true,
                ],
                'allocated_impact_by_exercise' => [],
                'actual_impact_by_exercise' => [$lockedExercise->id => $amount],
                'reason' => $validated['reason'],
                'reference_type' => $this->sourceClass((string) $validated['source_type']),
                'reference_id' => $source->id,
            ]);

            return $correction->load(['expenseLine', 'expense', 'closingSnapshot', 'originalExpenseLine', 'recordedBy']);
        });
    }

    /** @param array<string, mixed> $validated */
    private function lockSource(array $validated, Company $company, Exercise $exercise): Expense|Project|Contract
    {
        $sourceType = (string) $validated['source_type'];
        $sourceId = (int) $validated['source_origin_id'];
        $source = match ($sourceType) {
            'project' => Project::query()->lockForUpdate()->find($sourceId),
            'contract' => Contract::query()->lockForUpdate()->find($sourceId),
            'expense' => Expense::query()->lockForUpdate()->find($sourceId),
            default => throw ValidationException::withMessages(['source_type' => 'La sorgente storica non è valida.']),
        };

        if ($source === null) {
            throw ValidationException::withMessages(['source_origin_id' => 'La sorgente storica dichiarata non è disponibile.']);
        }
        if ($source->company_id !== $company->id) {
            throw ValidationException::withMessages(['source_origin_id' => 'La sorgente storica non appartiene a questa Azienda.']);
        }
        if ($source instanceof Expense && $source->exercise_id !== $exercise->id) {
            throw ValidationException::withMessages(['source_origin_id' => 'La sorgente Spesa non appartiene all’Esercizio Chiuso corretto.']);
        }
        if ($sourceType === 'expense' && ($source->project_id !== null || $source->contract_id !== null)) {
            throw ValidationException::withMessages(['source_type' => 'La Spesa storica selezionata non è una sorgente autonoma.']);
        }

        return $source;
    }

    private function lockSelectedExpense(?int $expenseId, Company $company, Exercise $exercise): ?Expense
    {
        if ($expenseId === null) {
            return null;
        }
        $expense = Expense::query()->lockForUpdate()->find($expenseId);
        if ($expense === null || $expense->company_id !== $company->id || $expense->exercise_id !== $exercise->id) {
            throw ValidationException::withMessages(['historical_expense_id' => 'La Spesa storica non appartiene a questo contesto Azienda/Esercizio.']);
        }

        return $expense;
    }

    private function lockOriginalLine(?int $lineId, Company $company, Exercise $exercise): ?ExpenseLine
    {
        if ($lineId === null) {
            return null;
        }
        $line = ExpenseLine::query()->lockForUpdate()->find($lineId);
        $expense = $line?->expense;
        if ($line === null || $expense === null || $expense->company_id !== $company->id || $expense->exercise_id !== $exercise->id) {
            throw ValidationException::withMessages(['original_expense_line_id' => 'La Riga originaria non appartiene a questo contesto storico.']);
        }

        return $line;
    }

    /** @param array<string, mixed> $validated */
    private function createHistoricalExpense(Company $company, Exercise $exercise, array $validated, Expense|Project|Contract $source): Expense
    {
        $project = $source instanceof Project ? $source : null;
        $contract = $source instanceof Contract ? $source : null;
        $sourceExpense = $source instanceof Expense ? $source : null;
        $requestedSupplierId = $validated['supplier_id'] === null ? null : (int) $validated['supplier_id'];

        if ($contract !== null && $requestedSupplierId !== null && $requestedSupplierId !== (int) $contract->supplier_id) {
            throw ValidationException::withMessages(['supplier_id' => 'Il Fornitore del Contratto storico è obbligatorio e non può essere sostituito.']);
        }

        $supplierId = $contract === null
            ? ($requestedSupplierId ?? $sourceExpense?->supplier_id)
            : $contract->supplier_id;
        $supplier = $supplierId === null
            ? null
            : Supplier::query()->lockForUpdate()->find($supplierId);
        if ($supplierId !== null && ($supplier === null || $supplier->company_id !== $company->id)) {
            throw ValidationException::withMessages(['supplier_id' => 'Il Fornitore storico selezionato non appartiene a questa Azienda.']);
        }

        $costCenterId = $sourceExpense?->direct_cost_center_id;
        $costCenter = $costCenterId === null
            ? null
            : CostCenter::query()->lockForUpdate()->find($costCenterId);
        if ($costCenterId !== null && ($costCenter === null || $costCenter->company_id !== $company->id)) {
            throw ValidationException::withMessages(['direct_cost_center_id' => 'Il Centro di Costo storico non appartiene a questa Azienda.']);
        }

        if (! is_string($validated['description'] ?? null) || trim($validated['description']) === '') {
            throw ValidationException::withMessages(['description' => 'La Descrizione è obbligatoria per una nuova Spesa tardiva.']);
        }

        return Expense::query()->create([
            'company_id' => $company->id,
            'exercise_id' => $exercise->id,
            'project_id' => $project?->id,
            'contract_id' => $contract?->id,
            'origin' => 'manual',
            'supplier_id' => $supplier?->id,
            'direct_cost_center_id' => $costCenter?->id,
            'description' => $validated['description'],
            'notes' => $validated['notes'],
            'reversed_at' => null,
        ]);
    }

    /** @return array<string, mixed> */
    private function ownerContext(Expense $expense): array
    {
        $container = $expense->contract_id !== null ? 'contract' : ($expense->project_id !== null ? 'project' : 'autonomous');

        return [
            'container' => $container,
            'project_id' => $expense->project_id,
            'project_label' => $expense->project?->title,
            'contract_id' => $expense->contract_id,
            'contract_label' => $expense->contract?->title,
            'direct_cost_center_id' => $expense->direct_cost_center_id,
            'direct_cost_center_label' => $expense->directCostCenter?->name,
            'supplier_id' => $expense->supplier_id,
            'supplier_label' => $expense->supplier?->legal_name,
        ];
    }

    /** @return array<string, mixed>|null */
    private function supplierContext(Expense $expense): ?array
    {
        if ($expense->supplier === null) {
            return null;
        }

        return [
            'id' => $expense->supplier->id,
            'label' => $expense->supplier->legal_name,
            'archived' => $expense->supplier->isArchived(),
        ];
    }

    private function sourceLabel(Expense|Project|Contract $source): string
    {
        return $source instanceof Expense ? $source->description : $source->title;
    }

    private function sourceClass(string $sourceType): string
    {
        return match ($sourceType) {
            'project' => Project::class,
            'contract' => Contract::class,
            default => Expense::class,
        };
    }

    private function currentKnowledgeActual(ClosingSnapshot $snapshot, int $exerciseId, string $amount, bool $includeAmount, ?string $excludedOperationId = null): string
    {
        $query = LateCorrection::query()
            ->where('exercise_id', $exerciseId)
            ->with('expenseLine');
        if ($excludedOperationId !== null) {
            $query->where('operation_id', '<>', $excludedOperationId);
        }
        $net = $query
            ->get()
            ->map(fn (LateCorrection $correction): string => (string) $correction->expenseLine->amount)
            ->all();
        if ($includeAmount) {
            $net[] = $amount;
        }

        return Decimal::add((string) $snapshot->total_closing_actual, Decimal::sum($net));
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
