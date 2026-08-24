<?php

namespace App\Models;

use App\Domain\Expenses\ExpenseLineType;
use Database\Factories\ExpenseLineFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

#[Fillable([
    'expense_id',
    'type',
    'amount',
    'quantity',
    'unit_amount',
    'unit_of_measure',
    'note',
    'annulled_at',
    'revision',
])]
/** @property ExpenseLineType $type */
class ExpenseLine extends Model
{
    /** @use HasFactory<ExpenseLineFactory> */
    use HasFactory;

    protected static function booted(): void
    {
        static::deleting(function (): never {
            throw new \LogicException('Expense lines cannot be deleted.');
        });
    }

    /** @return BelongsTo<Expense, $this> */
    public function expense(): BelongsTo
    {
        return $this->belongsTo(Expense::class);
    }

    /** @return HasMany<Attachment, $this> */
    public function attachments(): HasMany
    {
        return $this->hasMany(Attachment::class);
    }

    /** @return HasOne<LateCorrection, $this> */
    public function lateCorrection(): HasOne
    {
        return $this->hasOne(LateCorrection::class, 'expense_line_id');
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

    public function lineType(): ExpenseLineType
    {
        $type = $this->getAttribute('type');

        if (! $type instanceof ExpenseLineType) {
            throw new \UnexpectedValueException('Invalid persisted Expense Line type.');
        }

        return $type;
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'type' => ExpenseLineType::class,
            'amount' => 'decimal:2',
            'quantity' => 'decimal:6',
            'unit_amount' => 'decimal:6',
            'annulled_at' => 'datetime',
            'revision' => 'integer',
        ];
    }
}
