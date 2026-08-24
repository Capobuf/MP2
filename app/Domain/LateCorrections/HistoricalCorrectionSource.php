<?php

namespace App\Domain\LateCorrections;

use App\Models\Contract;
use App\Models\Exercise;
use App\Models\Expense;
use App\Models\Project;
use Illuminate\Database\Eloquent\Builder;

final class HistoricalCorrectionSource
{
    /** @return Builder<Project> */
    public function projects(Exercise $exercise): Builder
    {
        $endOfYear = $exercise->year.'-12-31';

        return Project::query()
            ->where('company_id', $exercise->company_id)
            ->where(function (Builder $query) use ($exercise, $endOfYear): void {
                $query->whereHas('expenses', fn (Builder $expenses) => $expenses->where('exercise_id', $exercise->id))
                    ->orWhereHas('classifications', fn (Builder $classifications) => $classifications->where('exercise_id', $exercise->id))
                    ->orWhereHas('transitions', fn (Builder $transitions) => $transitions->whereDate('effective_date', '<=', $endOfYear))
                    ->orWhereDate('initial_effective_date', '<=', $endOfYear);
            });
    }

    /** @return Builder<Contract> */
    public function contracts(Exercise $exercise): Builder
    {
        $endOfYear = $exercise->year.'-12-31';

        return Contract::query()
            ->where('company_id', $exercise->company_id)
            ->where(function (Builder $query) use ($exercise, $endOfYear): void {
                $query->whereHas('expenses', fn (Builder $expenses) => $expenses->where('exercise_id', $exercise->id))
                    ->orWhereHas('classifications', fn (Builder $classifications) => $classifications->where('exercise_id', $exercise->id))
                    ->orWhereHas('lifecycleFacts', fn (Builder $facts) => $facts->whereDate('declared_contractual_date', '<=', $endOfYear))
                    ->orWhereHas('conditions', fn (Builder $conditions) => $conditions->whereDate('valid_from', '<=', $endOfYear))
                    ->orWhereDate('contractual_start_date', '<=', $endOfYear);
            });
    }

    public function contains(Expense|Project|Contract $source, Exercise $exercise): bool
    {
        if ((int) $source->company_id !== (int) $exercise->company_id) {
            return false;
        }
        if ($source instanceof Expense) {
            return (int) $source->exercise_id === (int) $exercise->id
                && $source->project_id === null
                && $source->contract_id === null;
        }

        return $source instanceof Project
            ? $this->projects($exercise)->whereKey($source->id)->exists()
            : $this->contracts($exercise)->whereKey($source->id)->exists();
    }
}
