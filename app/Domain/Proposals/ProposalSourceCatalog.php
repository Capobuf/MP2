<?php

namespace App\Domain\Proposals;

use App\Domain\Contracts\ContractState;
use App\Domain\Expenses\Decimal;
use App\Domain\Projects\ProjectState;
use App\Models\BudgetSourceRow;
use App\Models\Contract;
use App\Models\Exercise;
use App\Models\Expense;
use App\Models\Project;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

final class ProposalSourceCatalog
{
    /** @return Collection<int, array{source_type: ProposalSourceType, origin_key: string, model: Expense|Project|Contract, read_only: bool}> */
    public function forExercise(Exercise $exercise): Collection
    {
        $start = Carbon::create($exercise->year, 1, 1)->toDateString();
        $end = Carbon::create($exercise->year, 12, 31)->toDateString();
        $sources = collect();

        Expense::query()->where('company_id', $exercise->company_id)->where('exercise_id', $exercise->id)
            ->whereNull('project_id')->whereNull('contract_id')->where(function ($query) use ($exercise): void {
                $query->whereNull('reversed_at')->orWhereHas('lines', fn ($lines) => $lines->whereNull('annulled_at'))
                    ->orWhereExists(fn ($budgetRows) => $budgetRows->selectRaw('1')->from((new BudgetSourceRow)->getTable().' as budget_rows')
                        ->join('budget_snapshots', 'budget_snapshots.id', '=', 'budget_rows.budget_snapshot_id')
                        ->whereColumn('budget_rows.origin_id', 'expenses.id')->where('budget_rows.source_type', ProposalSourceType::Expense->value)
                        ->where('budget_snapshots.exercise_id', $exercise->id));
            })->with('lines')->orderBy('id')->get()
            ->each(fn (Expense $expense) => $sources->push(['source_type' => ProposalSourceType::Expense, 'origin_key' => $expense->originKey(), 'model' => $expense, 'read_only' => $expense->isReversed()]));

        Project::query()->where('company_id', $exercise->company_id)->with(['transitions', 'expenses.lines', 'deferrals'])->orderBy('id')->get()
            ->filter(fn (Project $project): bool => $this->projectIncluded($project, $exercise->id, $start, $end))
            ->each(fn (Project $project) => $sources->push(['source_type' => ProposalSourceType::Project, 'origin_key' => $project->originKey(), 'model' => $project, 'read_only' => $project->isArchived()]));

        Contract::query()->where('company_id', $exercise->company_id)->with(['lifecycleFacts', 'renewalConfigurations', 'conditions', 'expenses.lines'])->orderBy('id')->get()
            ->filter(fn (Contract $contract): bool => $this->contractIncluded($contract, $exercise->id, $start, $end))
            ->each(fn (Contract $contract) => $sources->push(['source_type' => ProposalSourceType::Contract, 'origin_key' => $contract->originKey(), 'model' => $contract, 'read_only' => $contract->isArchived()]));

        return $sources;
    }

    private function projectIncluded(Project $project, int $exerciseId, string $start, string $end): bool
    {
        $dates = collect([$start, $end, $project->initialEffectiveDate()->toDateString()])
            ->merge($project->transitions->map(fn ($transition): string => $transition->effectiveDate()->toDateString()))
            ->filter(fn (string $date): bool => $date >= $start && $date <= $end)->unique();
        $activeInYear = $dates->contains(fn (string $date): bool => in_array($project->stateAtDate($date), [ProjectState::Planned, ProjectState::Open], true));
        $hasValues = $project->expenses->where('exercise_id', $exerciseId)->contains(fn (Expense $expense): bool => $expense->lines->isNotEmpty());
        $hasCarryover = $project->deferrals->contains(fn ($deferral): bool => $deferral->destination_exercise_id === $exerciseId
            && $deferral->mode->value === 'carryover'
            && Decimal::compare((string) $deferral->carryover_amount, '0.00') > 0);
        $hasTransition = $project->transitions->contains(fn ($transition): bool => $transition->annulledAt() === null && $transition->effectiveDate()->toDateString() >= $start && $transition->effectiveDate()->toDateString() <= $end);

        return $activeInYear || $hasValues || $hasCarryover || $hasTransition;
    }

    private function contractIncluded(Contract $contract, int $exerciseId, string $start, string $end): bool
    {
        $dates = collect([$start, $end, $contract->contractualStartDate()->toDateString()])
            ->merge($contract->lifecycleFacts->flatMap(fn ($fact): array => array_filter([$fact->stateChangeDate()?->toDateString(), $fact->renewedExpiryDate()?->toDateString()])))
            ->filter(fn (string $date): bool => $date >= $start && $date <= $end)->unique();
        $activeInYear = $dates->contains(fn (string $date): bool => in_array($contract->stateAtDate($date), [ContractState::Planned, ContractState::Active], true));
        $hasValues = $contract->expenses->where('exercise_id', $exerciseId)->contains(fn (Expense $expense): bool => $expense->lines->isNotEmpty());
        $hasCondition = $contract->conditions->contains(fn ($condition): bool => $condition->annulledAt() === null && $condition->validFrom()->toDateString() <= $end && ($condition->validTo() === null || $condition->validTo()->toDateString() >= $start));
        $hasEvent = $contract->lifecycleFacts->contains(fn ($fact): bool => $fact->annulledAt() === null && collect([$fact->stateChangeDate()?->toDateString(), $fact->renewedExpiryDate()?->toDateString()])->filter()->contains(fn (string $date): bool => $date >= $start && $date <= $end));
        $hasDeadline = $contract->nextExpiryDate() !== null && $contract->nextExpiryDate()->toDateString() >= $start && $contract->nextExpiryDate()->toDateString() <= $end;

        return $activeInYear || $hasValues || $hasCondition || $hasEvent || $hasDeadline;
    }
}
