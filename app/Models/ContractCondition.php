<?php

namespace App\Models;

use Database\Factories\ContractConditionFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

#[Fillable([
    'company_id', 'contract_id', 'cycle', 'attribution_mode', 'amount',
    'valid_from', 'valid_to', 'reason', 'created_by_id', 'annulled_at', 'annulled_by_id',
])]
/**
 * @property Carbon $valid_from
 * @property Carbon|null $valid_to
 * @property Carbon|null $annulled_at
 */
class ContractCondition extends Model
{
    /** @use HasFactory<ContractConditionFactory> */
    use HasFactory;

    protected static function booted(): void
    {
        static::deleting(function (): never {
            throw new \LogicException('Contract conditions cannot be deleted.');
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

    /** @param Builder<self> $query */
    public function scopeActive(Builder $query): void
    {
        $query->whereNull('annulled_at');
    }

    public function isAnnulled(): bool
    {
        return $this->annulled_at !== null;
    }

    public function validFrom(): Carbon
    {
        $date = $this->getAttribute('valid_from');

        if (! $date instanceof Carbon) {
            throw new \UnexpectedValueException('Invalid persisted Contract Condition start date.');
        }

        return $date;
    }

    public function validTo(): ?Carbon
    {
        $date = $this->getAttribute('valid_to');

        if ($date !== null && ! $date instanceof Carbon) {
            throw new \UnexpectedValueException('Invalid persisted Contract Condition end date.');
        }

        return $date;
    }

    public function annulledAt(): ?Carbon
    {
        $date = $this->getAttribute('annulled_at');

        if ($date !== null && ! $date instanceof Carbon) {
            throw new \UnexpectedValueException('Invalid persisted Contract Condition annulment timestamp.');
        }

        return $date;
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'valid_from' => 'date',
            'valid_to' => 'date',
            'annulled_at' => 'datetime',
        ];
    }
}
