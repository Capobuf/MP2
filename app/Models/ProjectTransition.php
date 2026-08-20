<?php

namespace App\Models;

use App\Domain\Projects\ProjectState;
use App\Domain\Projects\ProjectTransitionStatus;
use Carbon\CarbonImmutable;
use Database\Factories\ProjectTransitionFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

#[Fillable([
    'company_id',
    'project_id',
    'from_state',
    'to_state',
    'effective_date',
    'reason',
    'created_by_id',
    'annulled_at',
    'annulled_by_id',
    'annulment_reason',
])]
/**
 * @property ProjectState $from_state
 * @property ProjectState $to_state
 * @property Carbon $effective_date
 * @property Carbon|null $annulled_at
 */
class ProjectTransition extends Model
{
    /** @use HasFactory<ProjectTransitionFactory> */
    use HasFactory;

    protected static function booted(): void
    {
        static::deleting(function (): never {
            throw new \LogicException('Project transitions cannot be deleted.');
        });
    }

    /** @return BelongsTo<Company, $this> */
    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    /** @return BelongsTo<Project, $this> */
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    /** @return BelongsTo<User, $this> */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_id');
    }

    /** @return BelongsTo<User, $this> */
    public function annulledBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'annulled_by_id');
    }

    public function status(CarbonImmutable $today): ProjectTransitionStatus
    {
        return ProjectTransitionStatus::for($this->effectiveDate(), $this->annulledAt(), $today);
    }

    public function effectiveDate(): Carbon
    {
        $date = $this->getAttribute('effective_date');

        if (! $date instanceof Carbon) {
            throw new \UnexpectedValueException('Invalid persisted Project transition effective date.');
        }

        return $date;
    }

    public function annulledAt(): ?Carbon
    {
        $date = $this->getAttribute('annulled_at');

        if ($date !== null && ! $date instanceof Carbon) {
            throw new \UnexpectedValueException('Invalid persisted Project transition annulment timestamp.');
        }

        return $date;
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'from_state' => ProjectState::class,
            'to_state' => ProjectState::class,
            'effective_date' => 'date',
            'active_effective_date' => 'date',
            'annulled_at' => 'datetime',
        ];
    }
}
