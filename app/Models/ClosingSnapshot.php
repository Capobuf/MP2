<?php

namespace App\Models;

use App\Domain\Closing\ClosingOverspendNotes;
use App\Domain\Expenses\Decimal;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Validation\ValidationException;

#[Fillable([
    'company_id',
    'company_name',
    'exercise_id',
    'exercise_year',
    'closed_at',
    'closed_by_id',
    'initial_budget_id',
    'current_budget_id',
    'total_final_allocation',
    'total_closing_actual',
    'total_operational_variance',
    'total_consolidated_carryover',
    'accepted_warnings',
    'applied_settings',
    'next_exercise_disposition',
    'next_exercise_id',
    'operation_id',
])]
class ClosingSnapshot extends Model
{
    protected static function booted(): void
    {
        static::creating(function (self $snapshot): void {
            $exercise = Exercise::query()->find($snapshot->exercise_id);
            $company = Company::query()->find($snapshot->company_id);
            if ($exercise === null
                || $company === null
                || $exercise->company_id !== $company->id
                || $exercise->year !== (int) $snapshot->exercise_year) {
                throw ValidationException::withMessages([
                    'closing_snapshot' => 'Azienda, Esercizio e anno della Snapshot di Chiusura non sono coerenti.',
                ]);
            }

            self::assertBudgetReferences($snapshot, $exercise);
            self::assertNextExerciseReference($snapshot, $exercise);
            if (Decimal::compare(
                (string) $snapshot->total_operational_variance,
                Decimal::subtract((string) $snapshot->total_closing_actual, (string) $snapshot->total_final_allocation),
            ) !== 0) {
                throw ValidationException::withMessages([
                    'closing_snapshot' => 'I totali della Snapshot di Chiusura non sono coerenti.',
                ]);
            }

            $missing = ClosingOverspendNotes::missingRequired($company, $exercise);
            if ($missing !== []) {
                throw ValidationException::withMessages([
                    'closing' => 'Manca una Nota di sovraspesa che era obbligatoria al momento dell’operazione.',
                ]);
            }
        });
        static::updating(fn (): never => throw new \LogicException('Closing snapshots are immutable.'));
        static::deleting(fn (): never => throw new \LogicException('Closing snapshots cannot be deleted.'));
    }

    /** @return BelongsTo<Company, $this> */
    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    /** @return BelongsTo<TenantCompany, $this> */
    public function tenantCompany(): BelongsTo
    {
        return $this->belongsTo(TenantCompany::class, 'company_id', 'company_id');
    }

    /** @return BelongsTo<Exercise, $this> */
    public function exercise(): BelongsTo
    {
        return $this->belongsTo(Exercise::class);
    }

    /** @return BelongsTo<User, $this> */
    public function closer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'closed_by_id');
    }

    /** @return BelongsTo<BudgetSnapshot, $this> */
    public function initialBudget(): BelongsTo
    {
        return $this->belongsTo(BudgetSnapshot::class, 'initial_budget_id');
    }

    /** @return BelongsTo<BudgetSnapshot, $this> */
    public function currentBudget(): BelongsTo
    {
        return $this->belongsTo(BudgetSnapshot::class, 'current_budget_id');
    }

    /** @return BelongsTo<Exercise, $this> */
    public function nextExercise(): BelongsTo
    {
        return $this->belongsTo(Exercise::class, 'next_exercise_id');
    }

    /** @return HasMany<ClosingSourceRow, $this> */
    public function rows(): HasMany
    {
        return $this->hasMany(ClosingSourceRow::class);
    }

    /** @return HasMany<LateCorrection, $this> */
    public function lateCorrections(): HasMany
    {
        return $this->hasMany(LateCorrection::class)
            ->latest('created_at')
            ->latest('id');
    }

    /** @return HasMany<HistoricalErrorAnnotation, $this> */
    public function historicalErrorAnnotations(): HasMany
    {
        return $this->hasMany(HistoricalErrorAnnotation::class)
            ->latest('created_at')
            ->latest('id');
    }

    private static function assertBudgetReferences(self $snapshot, Exercise $exercise): void
    {
        $budgets = BudgetSnapshot::query()
            ->where('company_id', $exercise->company_id)
            ->where('exercise_id', $exercise->id)
            ->orderBy('version')
            ->get(['id', 'version']);
        $expectedInitialId = $budgets->first()?->id;
        $expectedCurrentId = $budgets->last()?->id;
        if ($snapshot->initial_budget_id !== $expectedInitialId
            || $snapshot->current_budget_id !== $expectedCurrentId) {
            throw ValidationException::withMessages([
                'closing_snapshot' => 'I riferimenti Budget della Snapshot devono essere il Budget v1 e quello corrente dell’Esercizio.',
            ]);
        }
    }

    private static function assertNextExerciseReference(self $snapshot, Exercise $exercise): void
    {
        $disposition = $snapshot->getAttributes()['next_exercise_disposition'] ?? null;
        if (! is_string($disposition)) {
            throw new \UnexpectedValueException('Invalid persisted next Exercise disposition.');
        }
        if ($disposition === 'not_created') {
            if ($snapshot->next_exercise_id !== null) {
                throw ValidationException::withMessages([
                    'closing_snapshot' => 'La non creazione di N+1 non può riferire un Esercizio successivo.',
                ]);
            }

            return;
        }
        if ($snapshot->next_exercise_id === null) {
            throw ValidationException::withMessages([
                'closing_snapshot' => 'La disposizione di N+1 richiede un Esercizio successivo coerente.',
            ]);
        }
        $nextExercise = Exercise::query()->find($snapshot->next_exercise_id);
        if ($nextExercise === null
            || $nextExercise->company_id !== $exercise->company_id
            || $nextExercise->year !== $exercise->year + 1) {
            throw ValidationException::withMessages([
                'closing_snapshot' => 'N+1 deve essere l’Esercizio immediatamente successivo della stessa Azienda.',
            ]);
        }
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'exercise_year' => 'integer',
            'closed_at' => 'datetime',
            'total_final_allocation' => 'decimal:2',
            'total_closing_actual' => 'decimal:2',
            'total_operational_variance' => 'decimal:2',
            'total_consolidated_carryover' => 'decimal:2',
            'accepted_warnings' => 'array',
            'applied_settings' => 'array',
        ];
    }
}
