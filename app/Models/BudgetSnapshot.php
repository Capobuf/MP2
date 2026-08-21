<?php

namespace App\Models;

use App\Domain\Proposals\ProposalPurpose;
use Database\Factories\BudgetSnapshotFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property int $company_id
 * @property int $exercise_id
 * @property ProposalPurpose $purpose
 * @property array<int, array<string, mixed>> $affected_exercises
 */
#[Fillable(['company_id', 'exercise_id', 'proposal_id', 'version', 'purpose', 'approved_at', 'approved_by_id', 'previous_budget_id', 'total_approved_allocation', 'affected_exercises', 'operation_id'])]
class BudgetSnapshot extends Model
{
    /** @use HasFactory<BudgetSnapshotFactory> */
    use HasFactory;

    protected static function booted(): void
    {
        static::updating(fn (): never => throw new \LogicException('Budget snapshots are immutable.'));
        static::deleting(fn (): never => throw new \LogicException('Budget snapshots cannot be deleted.'));
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

    /** @return BelongsTo<Proposal, $this> */
    public function proposal(): BelongsTo
    {
        return $this->belongsTo(Proposal::class);
    }

    /** @return BelongsTo<User, $this> */
    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by_id');
    }

    /** @return BelongsTo<BudgetSnapshot, $this> */
    public function previousBudget(): BelongsTo
    {
        return $this->belongsTo(self::class, 'previous_budget_id');
    }

    /** @return HasMany<BudgetSourceRow, $this> */
    public function rows(): HasMany
    {
        return $this->hasMany(BudgetSourceRow::class);
    }

    /** @return HasMany<BudgetEvidence, $this> */
    public function evidence(): HasMany
    {
        return $this->hasMany(BudgetEvidence::class);
    }

    protected function casts(): array
    {
        return ['purpose' => ProposalPurpose::class, 'approved_at' => 'datetime', 'total_approved_allocation' => 'decimal:2', 'affected_exercises' => 'array', 'version' => 'integer'];
    }
}
