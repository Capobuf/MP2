<?php

namespace App\Models;

use Database\Factories\ContractLifecycleFactFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'company_id', 'contract_id', 'type', 'declared_contractual_date',
    'state_change_date', 'renewed_expiry_date', 'renewal_configuration_id',
    'reason', 'created_by_id', 'annulled_at', 'annulled_by_id', 'annulment_reason',
])]
class ContractLifecycleFact extends Model
{
    /** @use HasFactory<ContractLifecycleFactFactory> */
    use HasFactory;

    protected static function booted(): void
    {
        static::deleting(function (): never {
            throw new \LogicException('Contract lifecycle facts cannot be deleted.');
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

    /** @return BelongsTo<ContractRenewalConfiguration, $this> */
    public function renewalConfiguration(): BelongsTo
    {
        return $this->belongsTo(ContractRenewalConfiguration::class);
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
            'declared_contractual_date' => 'date',
            'state_change_date' => 'date',
            'renewed_expiry_date' => 'date',
            'annulled_at' => 'datetime',
        ];
    }
}
