<?php

namespace App\Models;

use App\Domain\Contracts\ContractEconomicUse;
use App\Domain\Contracts\ContractState;
use App\Domain\Contracts\ContractStateTimeline;
use App\Domain\Expenses\Decimal;
use App\Domain\Expenses\ExpenseLineType;
use Database\Factories\ContractFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

#[Fillable([
    'company_id', 'supplier_id', 'title', 'notes', 'contractual_start_date',
    'next_expiry_date', 'renewal_anchor_date', 'automatic_renewal',
    'renewal_duration_months', 'notice_days', 'archived_at', 'revision',
])]
/**
 * @property Carbon $contractual_start_date
 * @property Carbon|null $next_expiry_date
 * @property Carbon|null $renewal_anchor_date
 * @property Carbon|null $archived_at
 */
class Contract extends Model
{
    /** @use HasFactory<ContractFactory> */
    use HasFactory;

    protected static function booted(): void
    {
        static::deleting(function (): never {
            throw new \LogicException('Contracts cannot be deleted.');
        });
    }

    /** @return BelongsTo<Company, $this> */
    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    /** @return BelongsTo<Supplier, $this> */
    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class);
    }

    /** @return HasMany<ContractRenewalConfiguration, $this> */
    public function renewalConfigurations(): HasMany
    {
        return $this->hasMany(ContractRenewalConfiguration::class)->orderBy('effective_from')->orderBy('id');
    }

    /** @return HasMany<ContractLifecycleFact, $this> */
    public function lifecycleFacts(): HasMany
    {
        return $this->hasMany(ContractLifecycleFact::class)->orderBy('declared_contractual_date')->orderBy('id');
    }

    /** @return HasMany<ContractCondition, $this> */
    public function conditions(): HasMany
    {
        return $this->hasMany(ContractCondition::class)->orderBy('valid_from')->orderBy('id');
    }

    /** @return HasMany<ContractExerciseClassification, $this> */
    public function classifications(): HasMany
    {
        return $this->hasMany(ContractExerciseClassification::class);
    }

    /** @return BelongsToMany<Exercise, $this> */
    public function exercises(): BelongsToMany
    {
        return $this->belongsToMany(Exercise::class, 'contract_exercise_classifications')
            ->withPivot('cost_center_id');
    }

    /** @return HasMany<Expense, $this> */
    public function expenses(): HasMany
    {
        return $this->hasMany(Expense::class);
    }

    /** @return HasMany<ProjectContractLink, $this> */
    public function projectLinks(): HasMany
    {
        return $this->hasMany(ProjectContractLink::class);
    }

    /** @return HasMany<Attachment, $this> */
    public function attachments(): HasMany
    {
        return $this->hasMany(Attachment::class);
    }

    /** @return HasMany<ProposalItem, $this> */
    public function proposalItems(): HasMany
    {
        return $this->hasMany(ProposalItem::class);
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
        return 'contract:'.$this->getKey();
    }

    public function isArchived(): bool
    {
        return $this->archivedAt() !== null;
    }

    public function archivedAt(): ?Carbon
    {
        $date = $this->getAttribute('archived_at');
        if ($date !== null && ! $date instanceof Carbon) {
            throw new \UnexpectedValueException('Invalid persisted Contract archive timestamp.');
        }

        return $date;
    }

    public function hasEconomicUse(): bool
    {
        return ContractEconomicUse::exists($this);
    }

    /** @return array<int, array{allocation: string, actual: string, has_actuals: bool}> */
    public function annualTotals(): array
    {
        $rows = ExpenseLine::query()
            ->selectRaw('expenses.exercise_id, expense_lines.type, SUM(expense_lines.amount) AS total_amount, MAX(CASE WHEN expense_lines.type = ? AND expense_lines.amount <> 0 THEN 1 ELSE 0 END) AS has_actuals', [ExpenseLineType::Actual->value])
            ->join('expenses', 'expenses.id', '=', 'expense_lines.expense_id')
            ->where('expenses.contract_id', $this->id)
            ->whereNull('expenses.reversed_at')
            ->whereNull('expense_lines.annulled_at')
            ->groupBy('expenses.exercise_id', 'expense_lines.type')
            ->get();
        $totals = [];

        foreach ($rows as $row) {
            $exerciseId = (int) $row->getAttribute('exercise_id');
            $totals[$exerciseId] ??= ['allocation' => '0.00', 'actual' => '0.00', 'has_actuals' => false];
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

        return $totals;
    }

    public function contractualStartDate(): Carbon
    {
        $date = $this->getAttribute('contractual_start_date');

        if (! $date instanceof Carbon) {
            throw new \UnexpectedValueException('Invalid persisted Contract start date.');
        }

        return $date;
    }

    public function nextExpiryDate(): ?Carbon
    {
        $date = $this->getAttribute('next_expiry_date');

        if ($date !== null && ! $date instanceof Carbon) {
            throw new \UnexpectedValueException('Invalid persisted Contract expiry date.');
        }

        return $date;
    }

    public function renewalAnchorDate(): ?Carbon
    {
        $date = $this->getAttribute('renewal_anchor_date');

        if ($date !== null && ! $date instanceof Carbon) {
            throw new \UnexpectedValueException('Invalid persisted Contract renewal anchor date.');
        }

        return $date;
    }

    public function stateAtDate(string $date): ContractState
    {
        $facts = $this->relationLoaded('lifecycleFacts')
            ? $this->lifecycleFacts
            : $this->lifecycleFacts()->get();
        $renewalConfigurations = $this->relationLoaded('renewalConfigurations')
            ? $this->renewalConfigurations
            : $this->renewalConfigurations()->get();

        return ContractStateTimeline::stateAtDate(
            $this->contractualStartDate()->toDateString(),
            $facts,
            $date,
            $renewalConfigurations,
        );
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'contractual_start_date' => 'date',
            'next_expiry_date' => 'date',
            'renewal_anchor_date' => 'date',
            'automatic_renewal' => 'boolean',
            'renewal_duration_months' => 'integer',
            'notice_days' => 'integer',
            'archived_at' => 'datetime',
            'revision' => 'integer',
        ];
    }
}
