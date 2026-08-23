<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'company_id',
    'company_name',
    'exercise_id',
    'exercise_year',
    'closed_at',
    'closed_by_id',
    'initial_budget_id',
    'current_budget_id',
    'total_final_allocation',
    'total_closing_actual',
    'total_operational_variance',
    'total_consolidated_carryover',
    'accepted_warnings',
    'applied_settings',
    'next_exercise_disposition',
    'next_exercise_id',
    'operation_id',
])]
class ClosingSnapshot extends Model
{
    protected static function booted(): void
    {
        static::updating(fn (): never => throw new \LogicException('Closing snapshots are immutable.'));
        static::deleting(fn (): never => throw new \LogicException('Closing snapshots cannot be deleted.'));
    }

    /** @return BelongsTo<Company, $this> */
    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    /** @return BelongsTo<Exercise, $this> */
    public function exercise(): BelongsTo
    {
        return $this->belongsTo(Exercise::class);
    }

    /** @return BelongsTo<User, $this> */
    public function closer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'closed_by_id');
    }

    /** @return BelongsTo<BudgetSnapshot, $this> */
    public function initialBudget(): BelongsTo
    {
        return $this->belongsTo(BudgetSnapshot::class, 'initial_budget_id');
    }

    /** @return BelongsTo<BudgetSnapshot, $this> */
    public function currentBudget(): BelongsTo
    {
        return $this->belongsTo(BudgetSnapshot::class, 'current_budget_id');
    }

    /** @return BelongsTo<Exercise, $this> */
    public function nextExercise(): BelongsTo
    {
        return $this->belongsTo(Exercise::class, 'next_exercise_id');
    }

    /** @return HasMany<ClosingSourceRow, $this> */
    public function rows(): HasMany
    {
        return $this->hasMany(ClosingSourceRow::class);
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'exercise_year' => 'integer',
            'closed_at' => 'datetime',
            'total_final_allocation' => 'decimal:2',
            'total_closing_actual' => 'decimal:2',
            'total_operational_variance' => 'decimal:2',
            'total_consolidated_carryover' => 'decimal:2',
            'accepted_warnings' => 'array',
            'applied_settings' => 'array',
        ];
    }
}
