<?php

namespace App\Models;

use Database\Factories\LateCorrectionFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Validation\ValidationException;

#[Fillable([
    'company_id',
    'exercise_id',
    'closing_snapshot_id',
    'expense_id',
    'expense_line_id',
    'original_expense_line_id',
    'recorded_by_id',
    'operation_id',
    'reason',
    'belongs_to_closed_exercise',
    'source_type',
    'source_origin_id',
    'source_origin_key',
    'source_label',
    'owner_context',
    'supplier_context',
])]
class LateCorrection extends Model
{
    /** @use HasFactory<LateCorrectionFactory> */
    use HasFactory;

    public const UPDATED_AT = null;

    protected static function booted(): void
    {
        static::creating(function (self $correction): void {
            if ($correction->getAttribute('belongs_to_closed_exercise') !== true
                && $correction->getAttribute('belongs_to_closed_exercise') !== 1
                && $correction->getAttribute('belongs_to_closed_exercise') !== '1') {
                throw ValidationException::withMessages([
                    'belongs_to_closed_exercise' => 'La correzione richiede la dichiarazione sull’appartenenza all’Esercizio Chiuso.',
                ]);
            }

            $company = Company::query()->find($correction->company_id);
            $exercise = Exercise::query()->find($correction->exercise_id);
            $snapshot = ClosingSnapshot::query()->find($correction->closing_snapshot_id);
            $expense = Expense::query()->find($correction->expense_id);
            $line = ExpenseLine::query()->find($correction->expense_line_id);
            if ($company === null
                || $exercise === null
                || $snapshot === null
                || $expense === null
                || $line === null
                || $exercise->isOpen()
                || $exercise->company_id !== $company->id
                || $snapshot->company_id !== $company->id
                || $snapshot->exercise_id !== $exercise->id
                || $expense->company_id !== $company->id
                || $expense->exercise_id !== $exercise->id
                || $line->expense_id !== $expense->id
                || $line->lineType()->value !== 'actual'
                || $line->isAnnulled()) {
                throw ValidationException::withMessages([
                    'late_correction' => 'La correzione deve riferire un Effettivo attivo dello stesso Esercizio Chiuso e della stessa Azienda.',
                ]);
            }

            if ($correction->original_expense_line_id !== null) {
                $original = ExpenseLine::query()->find($correction->original_expense_line_id);
                if ($original === null || $original->expense()->value('exercise_id') !== $exercise->id) {
                    throw ValidationException::withMessages([
                        'original_expense_line_id' => 'La Riga originaria deve appartenere allo stesso Esercizio storico.',
                    ]);
                }
            }
        });

        static::updating(fn (): never => throw new \LogicException('Late corrections are immutable.'));
        static::deleting(fn (): never => throw new \LogicException('Late corrections cannot be deleted.'));
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

    /** @return BelongsTo<ClosingSnapshot, $this> */
    public function closingSnapshot(): BelongsTo
    {
        return $this->belongsTo(ClosingSnapshot::class);
    }

    /** @return BelongsTo<Expense, $this> */
    public function expense(): BelongsTo
    {
        return $this->belongsTo(Expense::class);
    }

    /** @return BelongsTo<ExpenseLine, $this> */
    public function expenseLine(): BelongsTo
    {
        return $this->belongsTo(ExpenseLine::class, 'expense_line_id');
    }

    /** @return BelongsTo<ExpenseLine, $this> */
    public function originalExpenseLine(): BelongsTo
    {
        return $this->belongsTo(ExpenseLine::class, 'original_expense_line_id');
    }

    /** @return BelongsTo<User, $this> */
    public function recordedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recorded_by_id');
    }

    /** @return HasMany<Attachment, $this> */
    public function attachments(): HasMany
    {
        return $this->hasMany(Attachment::class, 'expense_line_id', 'expense_line_id')
            ->attached()
            ->orderBy('id');
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'belongs_to_closed_exercise' => 'boolean',
            'owner_context' => 'array',
            'supplier_context' => 'array',
            'source_origin_id' => 'integer',
            'created_at' => 'datetime',
        ];
    }
}
