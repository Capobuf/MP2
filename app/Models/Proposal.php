<?php

namespace App\Models;

use App\Domain\Expenses\Decimal;
use App\Domain\Proposals\ProposalPurpose;
use App\Domain\Proposals\ProposalStatus;
use Database\Factories\ProposalFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * @property ProposalPurpose $purpose
 * @property ProposalStatus $status
 * @property int $company_id
 * @property int $exercise_id
 */
#[Fillable(['company_id', 'exercise_id', 'reference_budget_id', 'purpose', 'status', 'created_by_id', 'approved_by_id', 'discarded_by_id', 'approved_at', 'discarded_at', 'discard_reason', 'approval_operation_id', 'discard_operation_id', 'revision'])]
class Proposal extends Model
{
    /** @use HasFactory<ProposalFactory> */
    use HasFactory;

    protected static function booted(): void
    {
        static::updating(function (self $proposal): void {
            if ($proposal->getRawOriginal('status') !== ProposalStatus::Draft->value) {
                throw new \LogicException('Terminal proposals are immutable.');
            }
        });
        static::deleting(fn (): never => throw new \LogicException('Proposals cannot be deleted.'));
    }

    /** @return BelongsTo<Company, $this> */
    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    /** @return BelongsTo<TenantCompany, $this> */
    public function tenantCompany(): BelongsTo
    {
        return $this->belongsTo(TenantCompany::class, 'company_id', 'company_id');
    }

    /** @return BelongsTo<Exercise, $this> */
    public function exercise(): BelongsTo
    {
        return $this->belongsTo(Exercise::class);
    }

    /** @return BelongsTo<User, $this> */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_id');
    }

    /** @return BelongsTo<User, $this> */
    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by_id');
    }

    /** @return BelongsTo<User, $this> */
    public function discarder(): BelongsTo
    {
        return $this->belongsTo(User::class, 'discarded_by_id');
    }

    /** @return BelongsTo<BudgetSnapshot, $this> */
    public function referenceBudget(): BelongsTo
    {
        return $this->belongsTo(BudgetSnapshot::class, 'reference_budget_id');
    }

    /** @return HasMany<ProposalItem, $this> */
    public function items(): HasMany
    {
        return $this->hasMany(ProposalItem::class);
    }

    /** @return HasMany<ProposalAction, $this> */
    public function actions(): HasMany
    {
        return $this->hasMany(ProposalAction::class)->where('status', 'active')->orderBy('sequence');
    }

    /** @return HasMany<ProposalAction, $this> */
    public function actionHistory(): HasMany
    {
        return $this->hasMany(ProposalAction::class)->orderBy('sequence');
    }

    /** @return HasOne<BudgetSnapshot, $this> */
    public function budget(): HasOne
    {
        return $this->hasOne(BudgetSnapshot::class);
    }

    /** @return HasMany<Attachment, $this> */
    public function attachments(): HasMany
    {
        return $this->hasMany(Attachment::class);
    }

    public function plannedAllocation(): string
    {
        $items = $this->relationLoaded('items') ? $this->items : $this->items()->get();

        return Decimal::sum($items->map(function (ProposalItem $item): string {
            $result = $item->result;
            if (isset($result['approved_allocation'])) {
                return (string) $result['approved_allocation'];
            }
            $lines = is_array($result['estimate_lines'] ?? null) ? $result['estimate_lines'] : [];

            return Decimal::sum(collect($lines)->map(fn (mixed $line): string => is_array($line) ? (string) ($line['amount'] ?? '0') : '0'));
        }));
    }

    protected function casts(): array
    {
        return ['purpose' => ProposalPurpose::class, 'status' => ProposalStatus::class, 'approved_at' => 'datetime', 'discarded_at' => 'datetime', 'revision' => 'integer'];
    }
}
