<?php

namespace App\Models;

use App\Domain\Proposals\ProposalSourceType;
use Database\Factories\BudgetSourceRowFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $company_id
 * @property ProposalSourceType $source_type
 * @property array<string, mixed> $detail
 */
#[Fillable(['company_id', 'budget_snapshot_id', 'source_type', 'origin_id', 'origin_key', 'proposal_item_id', 'copied_from_origin_key', 'label', 'summary', 'supplier_id', 'supplier_label', 'cost_center_id', 'cost_center_label', 'approved_estimates', 'approved_carryover', 'carryover_state', 'approved_allocation', 'start_state', 'end_state', 'detail_version', 'detail'])]
class BudgetSourceRow extends Model
{
    /** @use HasFactory<BudgetSourceRowFactory> */
    use HasFactory;

    protected static function booted(): void
    {
        static::updating(fn (): never => throw new \LogicException('Budget rows are immutable.'));
        static::deleting(fn (): never => throw new \LogicException('Budget rows cannot be deleted.'));
    }

    /** @return BelongsTo<Company, $this> */
    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    /** @return BelongsTo<BudgetSnapshot, $this> */
    public function budget(): BelongsTo
    {
        return $this->belongsTo(BudgetSnapshot::class, 'budget_snapshot_id');
    }

    protected function casts(): array
    {
        return ['source_type' => ProposalSourceType::class, 'approved_estimates' => 'decimal:2', 'approved_carryover' => 'decimal:2', 'approved_allocation' => 'decimal:2', 'detail_version' => 'integer', 'detail' => 'array'];
    }
}
