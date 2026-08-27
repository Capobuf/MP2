<?php

namespace App\Models;

use App\Domain\Expenses\Decimal;
use App\Domain\Expenses\ExpenseLineType;
use App\Domain\Projects\ProjectDeferralMode;
use App\Domain\Projects\ProjectState;
use App\Domain\Projects\ProjectStateTimeline;
use Database\Factories\ProjectFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

#[Fillable([
    'company_id',
    'title',
    'description',
    'notes',
    'initial_state',
    'initial_effective_date',
    'archived_at',
    'revision',
])]
/**
 * @property ProjectState $initial_state
 * @property Carbon $initial_effective_date
 * @property Carbon|null $archived_at
 */
class Project extends Model
{
    /** @use HasFactory<ProjectFactory> */
    use HasFactory;

    protected static function booted(): void
    {
        static::deleting(function (): never {
            throw new \LogicException('Projects cannot be deleted.');
        });
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

    /** @return HasMany<ProjectTransition, $this> */
    public function transitions(): HasMany
    {
        return $this->hasMany(ProjectTransition::class)->orderBy('effective_date')->orderBy('id');
    }

    /** @return HasMany<ProjectExerciseClassification, $this> */
    public function classifications(): HasMany
    {
        return $this->hasMany(ProjectExerciseClassification::class);
    }

    /** @return HasMany<Expense, $this> */
    public function expenses(): HasMany
    {
        return $this->hasMany(Expense::class);
    }

    /** @return HasMany<ProjectContractLink, $this> */
    public function contractLinks(): HasMany
    {
        return $this->hasMany(ProjectContractLink::class);
    }

    /** @return HasMany<ProposalItem, $this> */
    public function proposalItems(): HasMany
    {
        return $this->hasMany(ProposalItem::class);
    }

    /** @return HasMany<ProjectDeferral, $this> */
    public function deferrals(): HasMany
    {
        return $this->hasMany(ProjectDeferral::class);
    }

    /** @param Builder<self> $query */
    public function scopeActive(Builder $query): void
    {
        $query->whereNull('archived_at');
    }

    /** @param Builder<self> $query */
    public function scopeArchived(Builder $query): void
    {
        $query->whereNotNull('archived_at');
    }

    public function originKey(): string
    {
        return 'project:'.$this->getKey();
    }

    public function initialState(): ProjectState
    {
        $state = $this->getAttribute('initial_state');

        if (! $state instanceof ProjectState) {
            throw new \UnexpectedValueException('Invalid persisted Project state.');
        }

        return $state;
    }

    public function stateAtDate(string $referenceDate): ?ProjectState
    {
        $transitions = $this->relationLoaded('transitions')
            ? $this->transitions
            : $this->transitions()->get();

        return ProjectStateTimeline::stateAtDate(
            $this->initialState(),
            $this->initialEffectiveDate()->toDateString(),
            $transitions,
            $referenceDate,
        );
    }

    public function initialEffectiveDate(): Carbon
    {
        $date = $this->getAttribute('initial_effective_date');

        if (! $date instanceof Carbon) {
            throw new \UnexpectedValueException('Invalid persisted Project initial effective date.');
        }

        return $date;
    }

    public function archivedAt(): ?Carbon
    {
        $date = $this->getAttribute('archived_at');

        if ($date !== null && ! $date instanceof Carbon) {
            throw new \UnexpectedValueException('Invalid persisted Project archive timestamp.');
        }

        return $date;
    }

    public function isArchived(): bool
    {
        return $this->archivedAt() !== null;
    }

    /** @return array<int, array{allocation: string, actual: string, has_actuals: bool}> */
    public function annualTotals(): array
    {
        $rows = ExpenseLine::query()
            ->selectRaw('expenses.exercise_id, expense_lines.type, SUM(expense_lines.amount) AS total_amount, MAX(CASE WHEN expense_lines.type = ? AND expense_lines.amount <> 0 THEN 1 ELSE 0 END) AS has_actuals', [ExpenseLineType::Actual->value])
            ->join('expenses', 'expenses.id', '=', 'expense_lines.expense_id')
            ->where('expenses.project_id', $this->id)
            ->whereNull('expenses.reversed_at')
            ->whereNull('expense_lines.annulled_at')
            ->groupBy('expenses.exercise_id', 'expense_lines.type')
            ->get();
        $totals = [];

        foreach ($rows as $row) {
            $exerciseId = (int) $row->getAttribute('exercise_id');
            $totals[$exerciseId] ??= [
                'allocation' => '0.00',
                'actual' => '0.00',
                'has_actuals' => false,
            ];
            $typeAttribute = $row->getAttribute('type');
            $type = $typeAttribute instanceof ExpenseLineType ? $typeAttribute->value : (string) $typeAttribute;
            $amount = Decimal::money((string) $row->getAttribute('total_amount'));

            if ($type === ExpenseLineType::Estimate->value) {
                $totals[$exerciseId]['allocation'] = $amount;
            } elseif ($type === ExpenseLineType::Actual->value) {
                $totals[$exerciseId]['actual'] = $amount;
                $totals[$exerciseId]['has_actuals'] = (bool) $row->getAttribute('has_actuals');
            }
        }

        $carryovers = $this->relationLoaded('deferrals')
            ? $this->deferrals->where('mode', ProjectDeferralMode::Carryover)
                ->groupBy('destination_exercise_id')
                ->map(fn ($rows): string => Decimal::sum($rows->pluck('carryover_amount')))
            : ProjectDeferral::query()
                ->where('project_id', $this->id)
                ->where('mode', ProjectDeferralMode::Carryover->value)
                ->selectRaw('destination_exercise_id, SUM(carryover_amount) AS total_amount')
                ->groupBy('destination_exercise_id')
                ->pluck('total_amount', 'destination_exercise_id')
                ->map(fn (mixed $amount): string => Decimal::money((string) $amount));

        foreach ($carryovers as $exerciseId => $amount) {
            $exerciseId = (int) $exerciseId;
            $totals[$exerciseId] ??= ['allocation' => '0.00', 'actual' => '0.00', 'has_actuals' => false];
            $totals[$exerciseId]['allocation'] = Decimal::add($totals[$exerciseId]['allocation'], $amount);
        }

        return $totals;
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'initial_state' => ProjectState::class,
            'initial_effective_date' => 'date',
            'archived_at' => 'datetime',
            'revision' => 'integer',
        ];
    }
}
