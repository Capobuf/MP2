<?php

namespace App\Actions\Operations;

use App\Domain\Company\AuditEventType;
use App\Domain\Contracts\ContractAnnualAllocation;
use App\Domain\Contracts\ContractStateTimeline;
use App\Domain\Expenses\Decimal;
use App\Domain\Expenses\ExpenseLineType;
use App\Models\AuditEvent;
use App\Models\Contract;
use App\Models\Exercise;
use App\Models\Expense;
use App\Models\ExpenseLine;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

class RecalculateContractEstimates
{
    /** @param iterable<int, Exercise> $exercises */
    public function execute(User $actor, Contract $contract, iterable $exercises, string $operationId): Contract
    {
        return DB::transaction(function () use ($actor, $contract, $exercises, $operationId): Contract {
            $locked = Contract::query()->lockForUpdate()->findOrFail($contract->id);
            Gate::forUser($actor)->authorize('update', $locked);

            $existing = AuditEvent::query()->where('operation_id', $operationId)->where('event_sequence', 0)->first();
            if ($existing !== null) {
                if ($existing->eventType() !== AuditEventType::ContractEstimateRecalculated
                    || $existing->subject_type !== Contract::class
                    || $existing->subject_id !== $locked->id) {
                    throw ValidationException::withMessages(['operation_id' => 'Identificativo operazione già utilizzato.']);
                }

                return $locked;
            }

            $sequence = 0;
            $this->recalculateWithinTransaction($actor, $locked, $exercises, $operationId, $sequence);
            $locked->increment('revision');

            return $locked->refresh();
        });
    }

    /**
     * @param  iterable<int, Exercise>  $exercises
     * @return array<int, array{before: string, after: string, composition: list<array<string, mixed>>}>
     */
    public function recalculateWithinTransaction(
        User $actor,
        Contract $contract,
        iterable $exercises,
        string $operationId,
        int &$sequence,
    ): array {
        $contract->load(['conditions', 'lifecycleFacts', 'renewalConfigurations']);
        $impacts = [];

        foreach ($exercises as $exercise) {
            if ($exercise->company_id !== $contract->company_id || ! $exercise->isOpen()) {
                throw ValidationException::withMessages(['exercises' => 'Il ricalcolo richiede Esercizi Aperti della stessa Azienda.']);
            }

            $allocation = ContractAnnualAllocation::forYear(
                conditions: $contract->conditions,
                year: $exercise->year,
                stateAt: fn (string $date) => ContractStateTimeline::stateAtDate(
                    $contract->contractualStartDate()->toDateString(),
                    $contract->lifecycleFacts,
                    $date,
                    $contract->renewalConfigurations,
                ),
            );
            $expense = Expense::query()
                ->where('contract_id', $contract->id)
                ->where('exercise_id', $exercise->id)
                ->where('origin', 'system')
                ->lockForUpdate()
                ->first();
            $before = $expense?->allocation() ?? '0.00';

            if ($expense === null && Decimal::compare($allocation->amount, '0.00') !== 0) {
                $expense = Expense::query()->create([
                    'company_id' => $contract->company_id,
                    'exercise_id' => $exercise->id,
                    'project_id' => null,
                    'contract_id' => $contract->id,
                    'origin' => 'system',
                    'supplier_id' => $contract->supplier_id,
                    'direct_cost_center_id' => null,
                    'description' => 'Stima di sistema · '.$contract->title,
                    'notes' => null,
                    'revision' => 0,
                ]);
                ExpenseLine::query()->create([
                    'expense_id' => $expense->id,
                    'type' => ExpenseLineType::Estimate,
                    'amount' => $allocation->amount,
                ]);
            } elseif ($expense !== null) {
                $line = $expense->lines()->where('type', ExpenseLineType::Estimate->value)->lockForUpdate()->sole();
                if (Decimal::compare((string) $line->amount, $allocation->amount) !== 0) {
                    $line->update(['amount' => $allocation->amount]);
                    $expense->increment('revision');
                }
            }

            $impacts[$exercise->id] = [
                'before' => Decimal::money($before),
                'after' => $allocation->amount,
                'composition' => $allocation->composition,
            ];

            if (Decimal::compare($before, $allocation->amount) !== 0) {
                $exercise->increment('revision');
            }

            AuditEvent::query()->create([
                'operation_id' => $operationId,
                'event_sequence' => $sequence++,
                'company_id' => $contract->company_id,
                'actor_id' => $actor->id,
                'event_type' => AuditEventType::ContractEstimateRecalculated,
                'subject_type' => Contract::class,
                'subject_id' => $contract->id,
                'affected_exercise_ids' => [$exercise->id],
                'effective_from' => $exercise->year.'-01-01',
                'effective_to' => $exercise->year.'-12-31',
                'previous_value' => ['allocation' => Decimal::money($before)],
                'new_value' => [
                    'allocation' => $allocation->amount,
                    'composition' => $allocation->composition,
                    'generated_expense_id' => $expense?->id,
                ],
                'allocated_impact_by_exercise' => [
                    (string) $exercise->id => Decimal::subtract($allocation->amount, $before),
                ],
                'actual_impact_by_exercise' => [(string) $exercise->id => '0.00'],
            ]);
        }

        return $impacts;
    }
}
