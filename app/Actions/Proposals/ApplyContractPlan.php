<?php

namespace App\Actions\Proposals;

use App\Domain\Contracts\ContractAnnualAllocation;
use App\Domain\Contracts\ContractStateTimeline;
use App\Domain\Expenses\ExpenseLineType;
use App\Models\Contract;
use App\Models\ContractCondition;
use App\Models\ContractExerciseClassification;
use App\Models\ContractLifecycleFact;
use App\Models\ContractRenewalConfiguration;
use App\Models\Exercise;
use App\Models\Expense;
use App\Models\ProposalItem;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Validation\ValidationException;

final class ApplyContractPlan
{
    public function execute(ProposalItem $item, User $actor): Contract
    {
        $result = $item->result;
        $contract = $item->contract;
        if ($contract === null) {
            $contract = Contract::query()->create(['company_id' => $item->company_id, 'supplier_id' => $result['supplier_id'], 'title' => $result['title'], 'notes' => $result['notes'] ?? null, 'contractual_start_date' => $result['contractual_start_date'], 'next_expiry_date' => $result['next_expiry_date'] ?? null, 'renewal_anchor_date' => $result['next_expiry_date'] ?? null, 'automatic_renewal' => $result['automatic_renewal'] ?? false, 'renewal_duration_months' => $result['renewal_duration_months'] ?? null, 'notice_days' => $result['notice_days'] ?? null]);
        } elseif ($contract->company_id !== $item->company_id) {
            throw ValidationException::withMessages(['contract' => 'Contratto esterno all’Azienda.']);
        }
        if (array_key_exists('cost_center_id', $result) || $item->contract_id === null) {
            ContractExerciseClassification::query()->updateOrCreate(['contract_id' => $contract->id, 'exercise_id' => $result['exercise_id'] ?? $item->proposal->exercise_id], ['company_id' => $item->company_id, 'cost_center_id' => $result['cost_center_id'] ?? null]);
        }
        foreach ($result['planned_conditions'] ?? [] as $condition) {
            ContractCondition::query()->create(['company_id' => $item->company_id, 'contract_id' => $contract->id, 'cycle' => $condition['cycle'], 'attribution_mode' => $condition['attribution_mode'], 'amount' => $condition['amount'], 'valid_from' => $condition['valid_from'], 'valid_to' => $condition['valid_to'] ?? null, 'reason' => $condition['reason'] ?? null, 'created_by_id' => $actor->id]);
        }
        foreach ($result['planned_condition_changes'] ?? [] as $change) {
            $condition = ContractCondition::query()->where('contract_id', $contract->id)->find($change['condition_id']);
            if ($condition === null) {
                throw ValidationException::withMessages(['condition_id' => 'Condizione non disponibile.']);
            }
            $originalValidTo = $condition->validTo()?->toDateString();
            if ($change['future_replacement'] ?? false) {
                $condition->forceFill(['annulled_at' => now(), 'annulled_by_id' => $actor->id, 'reason' => $change['reason'] ?? $condition->reason])->save();
            } else {
                $condition->update(['valid_to' => CarbonImmutable::parse($change['effective_date'])->subDay()->toDateString()]);
            }
            ContractCondition::query()->create(['company_id' => $item->company_id, 'contract_id' => $contract->id, 'cycle' => $change['cycle'], 'attribution_mode' => $change['attribution_mode'], 'amount' => $change['amount'], 'valid_from' => $change['effective_date'], 'valid_to' => $originalValidTo, 'reason' => $change['reason'] ?? null, 'created_by_id' => $actor->id]);
        }
        foreach ($result['renewal_configurations'] ?? [] as $renewal) {
            if (array_key_exists('id', $renewal)) {
                continue;
            }
            ContractRenewalConfiguration::query()->create(['company_id' => $item->company_id, 'contract_id' => $contract->id, 'effective_from' => $renewal['effective_from'], 'expiry_anchor_date' => $renewal['expiry_anchor_date'] ?? null, 'automatic_renewal' => $renewal['automatic_renewal'], 'renewal_duration_months' => $renewal['renewal_duration_months'] ?? null, 'notice_days' => $renewal['notice_days'] ?? null, 'created_by_id' => $actor->id]);
            $contract->update(['automatic_renewal' => $renewal['automatic_renewal'], 'next_expiry_date' => $renewal['expiry_anchor_date'] ?? null, 'renewal_anchor_date' => $renewal['expiry_anchor_date'] ?? null, 'renewal_duration_months' => $renewal['renewal_duration_months'] ?? null, 'notice_days' => $renewal['notice_days'] ?? null]);
        }
        foreach ($result['planned_lifecycle'] ?? [] as $fact) {
            ContractLifecycleFact::query()->create(['company_id' => $item->company_id, 'contract_id' => $contract->id, 'type' => $fact['type'], 'declared_contractual_date' => $fact['declared_contractual_date'], 'state_change_date' => $fact['effective_date'], 'reason' => $fact['reason'] ?? null, 'created_by_id' => $actor->id]);
            if ($fact['type'] === 'cessation') {
                $contract->conditions()->active()->whereNull('valid_to')->whereDate('valid_from', '<=', $fact['declared_contractual_date'])->update(['valid_to' => $fact['declared_contractual_date'], 'reason' => $fact['reason'] ?? null]);
            }
            if ($fact['type'] === 'reactivation') {
                $contract->update(['archived_at' => null, 'next_expiry_date' => $fact['next_expiry_date'] ?? $contract->next_expiry_date, 'renewal_anchor_date' => $fact['next_expiry_date'] ?? $contract->renewal_anchor_date]);
            }
        }
        $contract->load(['conditions', 'lifecycleFacts', 'renewalConfigurations']);
        $exerciseIds = collect([$result['exercise_id'] ?? $item->proposal->exercise_id, $item->proposal->exercise_id]);
        foreach ($result['planned_condition_changes'] ?? [] as $change) {
            $exerciseIds->push(...array_map('intval', array_keys($change['exercise_impacts'] ?? [])));
        }
        if (($result['planned_conditions'] ?? []) !== [] || ($result['planned_lifecycle'] ?? []) !== [] || ($result['renewal_configurations'] ?? []) !== []) {
            $exerciseIds->push(...Exercise::query()->where('company_id', $item->company_id)->open()->pluck('id')->map(fn (mixed $id): int => (int) $id)->all());
        }
        $exercises = Exercise::query()->where('company_id', $item->company_id)->whereIn('id', $exerciseIds->unique())->open()->orderBy('id')->get();
        foreach ($exercises as $exercise) {
            $allocation = ContractAnnualAllocation::forYear($contract->conditions, $exercise->year, fn (string $date) => ContractStateTimeline::stateAtDate($contract->contractualStartDate()->toDateString(), $contract->lifecycleFacts, $date, $contract->renewalConfigurations));
            $systemExpense = Expense::query()->where('contract_id', $contract->id)->where('exercise_id', $exercise->id)->where('origin', 'system')->first();
            if ($systemExpense === null && $allocation->amount !== '0.00') {
                $systemExpense = Expense::query()->create(['company_id' => $item->company_id, 'exercise_id' => $exercise->id, 'project_id' => null, 'contract_id' => $contract->id, 'origin' => 'system', 'supplier_id' => $contract->supplier_id, 'direct_cost_center_id' => null, 'description' => 'Stima di sistema · '.$contract->title, 'notes' => null]);
                $systemExpense->lines()->create(['type' => ExpenseLineType::Estimate, 'amount' => $allocation->amount]);
            } elseif ($systemExpense !== null) {
                $line = $systemExpense->lines()->where('type', ExpenseLineType::Estimate->value)->first();
                if ($line === null) {
                    $systemExpense->lines()->create(['type' => ExpenseLineType::Estimate, 'amount' => $allocation->amount]);
                } else {
                    $line->update(['amount' => $allocation->amount]);
                }
                $systemExpense->increment('revision');
            }
            $exercise->increment('revision');
        }
        $contract->increment('revision');

        return $contract->refresh();
    }
}
