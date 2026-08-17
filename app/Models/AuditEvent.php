<?php

namespace App\Models;

use App\Domain\Company\AuditEventType;
use App\Domain\Company\Capability;
use App\Domain\Company\Setting;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'company_id',
    'operation_id',
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

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'event_type' => AuditEventType::class,
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
