<?php

namespace App\Models;

use App\Domain\Contracts\ContractState;
use App\Domain\Contracts\ContractStateTimeline;
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
        return $this->archived_at !== null;
    }

    public function contractualStartDate(): Carbon
    {
        $date = $this->getAttribute('contractual_start_date');

        if (! $date instanceof Carbon) {
            throw new \UnexpectedValueException('Invalid persisted Contract start date.');
        }

        return $date;
    }

    public function stateAtDate(string $date): ContractState
    {
        $facts = $this->relationLoaded('lifecycleFacts')
            ? $this->lifecycleFacts
            : $this->lifecycleFacts()->get();

        return ContractStateTimeline::stateAtDate(
            $this->contractualStartDate()->toDateString(),
            $facts,
            $date,
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
