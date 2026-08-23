<?php

namespace App\Models;

use App\Domain\Expenses\Decimal;
use App\Domain\Expenses\ExerciseStatus;
use Database\Factories\ExerciseFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

#[Fillable(['company_id', 'year', 'status', 'revision'])]
/** @property ExerciseStatus $status */
class Exercise extends Model
{
    /** @use HasFactory<ExerciseFactory> */
    use HasFactory;

    protected static function booted(): void
    {
        static::deleting(function (): never {
            throw new \LogicException('Exercises cannot be deleted.');
        });
    }

    /** @return BelongsTo<Company, $this> */
    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    /** @return HasMany<Expense, $this> */
    public function expenses(): HasMany
    {
        return $this->hasMany(Expense::class);
    }

    /** @return HasMany<ProjectExerciseClassification, $this> */
    public function projectClassifications(): HasMany
    {
        return $this->hasMany(ProjectExerciseClassification::class);
    }

    /** @return HasMany<ContractExerciseClassification, $this> */
    public function contractClassifications(): HasMany
    {
        return $this->hasMany(ContractExerciseClassification::class);
    }

    /** @return HasMany<Proposal, $this> */
    public function proposals(): HasMany
    {
        return $this->hasMany(Proposal::class);
    }

    /** @return HasMany<BudgetSnapshot, $this> */
    public function budgets(): HasMany
    {
        return $this->hasMany(BudgetSnapshot::class);
    }

    /** @return HasOne<BudgetSnapshot, $this> */
    public function latestBudget(): HasOne
    {
        return $this->hasOne(BudgetSnapshot::class)->ofMany('version', 'max');
    }

    /** @param Builder<self> $query */
    public function scopeOpen(Builder $query): void
    {
        $query->where('status', ExerciseStatus::Open->value);
    }

    public function isOpen(): bool
    {
        return $this->status() === ExerciseStatus::Open;
    }

    public function status(): ExerciseStatus
    {
        $status = $this->getAttribute('status');

        if (! $status instanceof ExerciseStatus) {
            throw new \UnexpectedValueException('Invalid persisted Exercise status.');
        }

        return $status;
    }

    public function allocation(): string
    {
        return $this->lineTotal('estimate');
    }

    public function actual(): string
    {
        return $this->lineTotal('actual');
    }

    public function operationalVariance(): string
    {
        return Decimal::subtract($this->actual(), $this->allocation());
    }

    private function lineTotal(string $lineType): string
    {
        if ($this->relationLoaded('expenses')) {
            $values = $this->expenses
                ->reject(fn (Expense $expense): bool => $expense->isReversed())
                ->map(fn (Expense $expense): string => $lineType === 'estimate'
                    ? $expense->allocation()
                    : $expense->actual());

            return Decimal::sum($values);
        }

        $values = ExpenseLine::query()
            ->join('expenses', 'expenses.id', '=', 'expense_lines.expense_id')
            ->where('expenses.exercise_id', $this->id)
            ->whereNull('expenses.reversed_at')
            ->whereNull('expense_lines.annulled_at')
            ->where('expense_lines.type', $lineType)
            ->pluck('expense_lines.amount');

        return Decimal::sum($values->map(fn (mixed $value): string => (string) $value));
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'year' => 'integer',
            'status' => ExerciseStatus::class,
            'revision' => 'integer',
        ];
    }
}
