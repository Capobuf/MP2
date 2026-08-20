<?php

namespace App\Models;

use Database\Factories\ContractRenewalConfigurationFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'company_id', 'contract_id', 'effective_from', 'automatic_renewal',
    'expiry_anchor_date', 'renewal_duration_months', 'notice_days', 'created_by_id',
])]
class ContractRenewalConfiguration extends Model
{
    /** @use HasFactory<ContractRenewalConfigurationFactory> */
    use HasFactory;

    protected static function booted(): void
    {
        static::updating(function (): never {
            throw new \LogicException('Contract renewal configurations are append-only.');
        });
        static::deleting(function (): never {
            throw new \LogicException('Contract renewal configurations cannot be deleted.');
        });
    }

    /** @return BelongsTo<Company, $this> */
    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    /** @return BelongsTo<Contract, $this> */
    public function contract(): BelongsTo
    {
        return $this->belongsTo(Contract::class);
    }

    /** @return BelongsTo<User, $this> */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_id');
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'effective_from' => 'date',
            'automatic_renewal' => 'boolean',
            'expiry_anchor_date' => 'date',
            'renewal_duration_months' => 'integer',
            'notice_days' => 'integer',
        ];
    }
}
