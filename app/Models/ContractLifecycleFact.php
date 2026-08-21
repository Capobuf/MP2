<?php

namespace App\Models;

use Database\Factories\ContractLifecycleFactFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

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

    public function declaredContractualDate(): Carbon
    {
        return $this->requiredDate('declared_contractual_date');
    }

    public function stateChangeDate(): ?Carbon
    {
        return $this->optionalDate('state_change_date');
    }

    public function renewedExpiryDate(): ?Carbon
    {
        return $this->optionalDate('renewed_expiry_date');
    }

    public function annulledAt(): ?Carbon
    {
        return $this->optionalDate('annulled_at');
    }

    private function requiredDate(string $attribute): Carbon
    {
        $date = $this->getAttribute($attribute);
        if (! $date instanceof Carbon) {
            throw new \UnexpectedValueException("Invalid persisted Contract lifecycle {$attribute}.");
        }

        return $date;
    }

    private function optionalDate(string $attribute): ?Carbon
    {
        $date = $this->getAttribute($attribute);
        if ($date !== null && ! $date instanceof Carbon) {
            throw new \UnexpectedValueException("Invalid persisted Contract lifecycle {$attribute}.");
        }

        return $date;
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
