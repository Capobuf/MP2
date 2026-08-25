<?php

namespace App\Models;

use App\Domain\Expenses\Decimal;
use App\Domain\Expenses\ExerciseStatus;
use App\Domain\Projects\ProjectDeferralMode;
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
        static::updating(function (self $exercise): void {
            if (! $exercise->isDirty('status')) {
                return;
            }
            if ($exercise->getRawOriginal('status') === ExerciseStatus::Closed->value
                && $exercise->status() === ExerciseStatus::Open) {
                throw new \LogicException('Closed Exercises cannot be reopened.');
            }
            if ($exercise->getRawOriginal('status') === ExerciseStatus::Open->value
                && $exercise->status() === ExerciseStatus::Closed
                && ! ClosingSnapshot::query()->where('exercise_id', $exercise->id)->exists()) {
                throw new \LogicException('An Exercise can be closed only after its Closing Snapshot has been materialized.');
            }
        });

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

    /** @return HasMany<ProjectDeferral, $this> */
    public function incomingProjectDeferrals(): HasMany
    {
        return $this->hasMany(ProjectDeferral::class, 'destination_exercise_id');
    }

    /** @return HasOne<BudgetSnapshot, $this> */
    public function latestBudget(): HasOne
    {
        return $this->hasOne(BudgetSnapshot::class)->ofMany('version', 'max');
    }

    public function hasApprovedBudget(): bool
    {
        return $this->budgets()->exists();
    }

    /** @return HasOne<ClosingSnapshot, $this> */
    public function closingSnapshot(): HasOne
    {
        return $this->hasOne(ClosingSnapshot::class);
    }

    /** @return HasMany<LateCorrection, $this> */
    public function lateCorrections(): HasMany
    {
        return $this->hasMany(LateCorrection::class)
            ->latest('created_at')
            ->latest('id');
    }

    /** @return HasMany<HistoricalErrorAnnotation, $this> */
    public function historicalErrorAnnotations(): HasMany
    {
        return $this->hasMany(HistoricalErrorAnnotation::class)
            ->latest('created_at')
            ->latest('id');
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
        $carryover = $this->relationLoaded('incomingProjectDeferrals')
            ? Decimal::sum($this->incomingProjectDeferrals
                ->where('mode', ProjectDeferralMode::Carryover)
                ->pluck('carryover_amount'))
            : Decimal::sum(ProjectDeferral::query()
                ->where('destination_exercise_id', $this->id)
                ->where('mode', ProjectDeferralMode::Carryover->value)
                ->pluck('carryover_amount'));

        return Decimal::add($this->lineTotal('estimate'), $carryover);
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
