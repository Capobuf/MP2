<?php

namespace App\Models;

use App\Domain\Proposals\ProposalSourceType;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'company_id',
    'closing_snapshot_id',
    'source_type',
    'origin_id',
    'origin_key',
    'copied_from_origin_key',
    'label',
    'summary',
    'supplier_id',
    'supplier_label',
    'cost_center_id',
    'cost_center_label',
    'end_state',
    'has_actuals',
    'final_estimates',
    'received_carryover',
    'final_allocation',
    'closing_actual',
    'operational_variance',
    'detail_version',
    'detail',
])]
class ClosingSourceRow extends Model
{
    protected static function booted(): void
    {
        static::updating(fn (): never => throw new \LogicException('Closing source rows are immutable.'));
        static::deleting(fn (): never => throw new \LogicException('Closing source rows cannot be deleted.'));
    }

    /** @return BelongsTo<Company, $this> */
    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    /** @return BelongsTo<ClosingSnapshot, $this> */
    public function snapshot(): BelongsTo
    {
        return $this->belongsTo(ClosingSnapshot::class, 'closing_snapshot_id');
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'source_type' => ProposalSourceType::class,
            'has_actuals' => 'boolean',
            'final_estimates' => 'decimal:2',
            'received_carryover' => 'decimal:2',
            'final_allocation' => 'decimal:2',
            'closing_actual' => 'decimal:2',
            'operational_variance' => 'decimal:2',
            'detail_version' => 'integer',
            'detail' => 'array',
        ];
    }
}
