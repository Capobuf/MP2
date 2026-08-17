<?php

namespace App\Models;

use App\Domain\Expenses\Decimal;
use App\Domain\Expenses\ExpenseLineType;
use Database\Factories\ExpenseFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'company_id',
    'exercise_id',
    'supplier_id',
    'direct_cost_center_id',
    'description',
    'notes',
    'reversed_at',
    'revision',
])]
class Expense extends Model
{
    /** @use HasFactory<ExpenseFactory> */
    use HasFactory;

    protected static function booted(): void
    {
        static::deleting(function (): never {
            throw new \LogicException('Expenses cannot be deleted.');
        });
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

    /** @return BelongsTo<Supplier, $this> */
    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class);
    }

    /** @return BelongsTo<CostCenter, $this> */
    public function directCostCenter(): BelongsTo
    {
        return $this->belongsTo(CostCenter::class, 'direct_cost_center_id');
    }

    /** @return HasMany<ExpenseLine, $this> */
    public function lines(): HasMany
    {
        return $this->hasMany(ExpenseLine::class);
    }

    /** @param Builder<self> $query */
    public function scopeActive(Builder $query): void
    {
        $query->whereNull('reversed_at');
    }

    public function isReversed(): bool
    {
        return $this->reversed_at !== null;
    }

    public function originKey(): string
    {
        return 'expense:'.$this->getKey();
    }

    public function allocation(): string
    {
        return $this->lineTotal(ExpenseLineType::Estimate);
    }

    public function actual(): string
    {
        return $this->lineTotal(ExpenseLineType::Actual);
    }

    public function operationalVariance(): string
    {
        return Decimal::subtract($this->actual(), $this->allocation());
    }

    public function hasActuals(): bool
    {
        if ($this->relationLoaded('lines')) {
            return $this->lines->contains(
                fn (ExpenseLine $line): bool => ! $line->isAnnulled()
                    && $line->lineType() === ExpenseLineType::Actual
                    && Decimal::compare((string) $line->amount, '0.00') !== 0,
            );
        }

        return $this->lines()
            ->active()
            ->where('type', ExpenseLineType::Actual->value)
            ->where('amount', '!=', '0.00')
            ->exists();
    }

    private function lineTotal(ExpenseLineType $type): string
    {
        if ($this->isReversed()) {
            return '0.00';
        }

        $values = $this->relationLoaded('lines')
            ? $this->lines
                ->filter(fn (ExpenseLine $line): bool => ! $line->isAnnulled() && $line->lineType() === $type)
                ->pluck('amount')
            : $this->lines()
                ->active()
                ->where('type', $type->value)
                ->pluck('amount');

        return Decimal::sum($values->map(fn (mixed $value): string => (string) $value));
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'reversed_at' => 'datetime',
            'revision' => 'integer',
        ];
    }
}
