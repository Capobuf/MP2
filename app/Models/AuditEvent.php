<?php

namespace App\Models;

use App\Domain\Company\AuditEventType;
use App\Domain\Company\Capability;
use App\Domain\Company\Setting;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'company_id',
    'operation_id',
    'event_sequence',
    'actor_id',
    'event_type',
    'subject_type',
    'subject_id',
    'beneficiary_id',
    'capability',
    'setting',
    'affected_exercise_ids',
    'effective_from',
    'effective_to',
    'previous_value',
    'new_value',
    'allocated_impact_by_exercise',
    'actual_impact_by_exercise',
    'reason',
    'reference_type',
    'reference_id',
])]
/** @property AuditEventType $event_type */
class AuditEvent extends Model
{
    public const UPDATED_AT = null;

    protected static function booted(): void
    {
        static::creating(function (AuditEvent $event): void {
            $event->event_sequence ??= 0;
        });

        static::updating(function (): never {
            throw new \LogicException('Audit events are append-only.');
        });

        static::deleting(function (): never {
            throw new \LogicException('Audit events are append-only.');
        });
    }

    /** @return BelongsTo<Company, $this> */
    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    /** @return BelongsTo<User, $this> */
    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id');
    }

    /** @return BelongsTo<User, $this> */
    public function beneficiary(): BelongsTo
    {
        return $this->belongsTo(User::class, 'beneficiary_id');
    }

    public function eventType(): AuditEventType
    {
        $eventType = $this->getAttribute('event_type');

        if (! $eventType instanceof AuditEventType) {
            throw new \UnexpectedValueException('Invalid persisted audit event type.');
        }

        return $eventType;
    }

    /** @param Builder<self> $query */
    public function scopeForProject(Builder $query, Project $project): void
    {
        $query->where(function (Builder $query) use ($project): void {
            $query->where(function (Builder $subject) use ($project): void {
                $subject->where('subject_type', Project::class)
                    ->where('subject_id', $project->id);
            })->orWhere(function (Builder $reference) use ($project): void {
                $reference->where('reference_type', Project::class)
                    ->where('reference_id', $project->id);
            })->orWhere('new_value->ownership_impact->source_project_id', $project->id)
                ->orWhere('new_value->ownership_impact->target_project_id', $project->id);
        });
    }

    /** @param Builder<self> $query */
    public function scopeForContract(Builder $query, Contract $contract): void
    {
        $query->where(function (Builder $query) use ($contract): void {
            $query->where(function (Builder $subject) use ($contract): void {
                $subject->where('subject_type', Contract::class)
                    ->where('subject_id', $contract->id);
            })->orWhere(function (Builder $reference) use ($contract): void {
                $reference->where('reference_type', Contract::class)
                    ->where('reference_id', $contract->id);
            })->orWhere('new_value->ownership_impact->source_contract_id', $contract->id)
                ->orWhere('new_value->ownership_impact->target_contract_id', $contract->id)
                ->orWhere('new_value->owner_contract_id', $contract->id);
        });
    }

    /** @return array<int, array{result: string, variance_before: string, variance_after: string, project_id: int|null}> */
    public function overspendOccurrences(): array
    {
        $newValue = $this->getAttribute('new_value');
        if (! is_array($newValue)) {
            return [];
        }

        $occurrences = [];
        $activity = $newValue['project_activity'] ?? null;
        if (is_array($activity) && is_array($activity['overspend'] ?? null)) {
            $occurrences[] = $this->normalizeOverspend($activity['overspend'], $this->reference_type === Project::class ? $this->reference_id : null);
        }

        $ownership = $newValue['ownership_impact'] ?? null;
        $projectImpacts = is_array($ownership) ? ($ownership['project_impacts'] ?? null) : null;
        if (is_array($projectImpacts)) {
            foreach ($projectImpacts as $projectId => $impact) {
                if (is_array($impact) && is_array($impact['overspend'] ?? null)) {
                    $occurrences[] = $this->normalizeOverspend($impact['overspend'], (int) $projectId);
                }
            }
        }

        return $occurrences;
    }

    /** @param array<string, mixed> $overspend
     * @return array{result: string, variance_before: string, variance_after: string, project_id: int|null}
     */
    private function normalizeOverspend(array $overspend, ?int $projectId): array
    {
        return [
            'result' => (string) $overspend['result'],
            'variance_before' => (string) $overspend['variance_before'],
            'variance_after' => (string) $overspend['variance_after'],
            'project_id' => $projectId,
        ];
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'event_type' => AuditEventType::class,
            'event_sequence' => 'integer',
            'capability' => Capability::class,
            'setting' => Setting::class,
            'affected_exercise_ids' => 'array',
            'effective_from' => 'date',
            'effective_to' => 'date',
            'previous_value' => 'json',
            'new_value' => 'json',
            'allocated_impact_by_exercise' => 'array',
            'actual_impact_by_exercise' => 'array',
        ];
    }
}
