<?php

namespace App\Models;

use App\Domain\Contracts\ContractClosedHistoryGuard;
use Database\Factories\ContractConditionFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;

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
        static::creating(function (self $condition): void {
            self::assertClosedHistoryUnchangedOnCreate($condition);
        });
        static::updating(function (self $condition): void {
            self::assertClosedHistoryUnchangedOnUpdate($condition);
        });
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

    private static function assertClosedHistoryUnchangedOnCreate(self $condition): void
    {
        if (ContractClosedHistoryGuard::historicalRegistrationAllowed() || (int) $condition->company_id < 1) {
            return;
        }
        $from = self::dateString($condition->getAttribute('valid_from'));
        if ($from === null) {
            return;
        }
        $to = self::dateString($condition->getAttribute('valid_to'));
        foreach (ContractClosedHistoryGuard::closedYears((int) $condition->company_id) as $year) {
            if (ContractClosedHistoryGuard::periodOverlapsYear($from, $to, $year)) {
                throw ValidationException::withMessages([
                    'contract' => 'Una nuova condizione ordinaria non può modificare un Esercizio Chiuso.',
                ]);
            }
        }
    }

    private static function assertClosedHistoryUnchangedOnUpdate(self $condition): void
    {
        if (ContractClosedHistoryGuard::historicalRegistrationAllowed()) {
            return;
        }
        $beforeFrom = self::dateString($condition->getRawOriginal('valid_from'));
        $afterFrom = self::dateString($condition->getAttribute('valid_from'));
        if ($beforeFrom === null || $afterFrom === null) {
            return;
        }
        $beforeTo = self::dateString($condition->getRawOriginal('valid_to'));
        $afterTo = self::dateString($condition->getAttribute('valid_to'));
        $beforeAnnulled = $condition->getRawOriginal('annulled_at') !== null;
        $afterAnnulled = $condition->getAttribute('annulled_at') !== null;
        $termsChanged = $condition->isDirty(['amount', 'cycle', 'attribution_mode', 'valid_from', 'valid_to']);

        foreach (ContractClosedHistoryGuard::closedYears((int) $condition->company_id) as $year) {
            $beforeApplies = ! $beforeAnnulled && ContractClosedHistoryGuard::periodOverlapsYear($beforeFrom, $beforeTo, $year);
            $afterApplies = ! $afterAnnulled && ContractClosedHistoryGuard::periodOverlapsYear($afterFrom, $afterTo, $year);
            if ($beforeApplies !== $afterApplies || ($termsChanged && ($beforeApplies || $afterApplies))) {
                throw ValidationException::withMessages([
                    'contract' => 'La modifica ordinaria riscriverebbe condizioni economiche di un Esercizio Chiuso.',
                ]);
            }
        }
    }

    private static function dateString(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        return $value instanceof \DateTimeInterface ? $value->format('Y-m-d') : (string) $value;
    }
}
