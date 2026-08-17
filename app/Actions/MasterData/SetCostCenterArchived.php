<?php

namespace App\Actions\MasterData;

use App\Domain\Company\AuditEventType;
use App\Models\AuditEvent;
use App\Models\CostCenter;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class SetCostCenterArchived
{
    public function execute(User $actor, CostCenter $costCenter, bool $archived, string $operationId): CostCenter
    {
        /** @var array{operation_id: string} $validated */
        $validated = Validator::make(['operation_id' => $operationId], [
            'operation_id' => ['required', 'uuid'],
        ])->validate();

        return DB::transaction(function () use ($actor, $costCenter, $archived, $validated): CostCenter {
            $lockedCostCenter = CostCenter::query()->with('company')->lockForUpdate()->findOrFail($costCenter->id);
            Gate::forUser($actor)->authorize('update', $lockedCostCenter);
            $eventType = $archived ? AuditEventType::CostCenterArchived : AuditEventType::CostCenterRestored;
            $existing = AuditEvent::query()->where('operation_id', $validated['operation_id'])->first();

            if ($existing !== null) {
                if (
                    $existing->eventType() !== $eventType
                    || $existing->subject_type !== CostCenter::class
                    || $existing->subject_id !== $lockedCostCenter->id
                ) {
                    throw ValidationException::withMessages([
                        'operation_id' => 'Identificativo operazione già utilizzato.',
                    ]);
                }

                return $lockedCostCenter;
            }

            if ($lockedCostCenter->isArchived() === $archived) {
                return $lockedCostCenter;
            }

            $previous = $this->snapshot($lockedCostCenter);
            $lockedCostCenter->forceFill(['archived_at' => $archived ? now() : null])->save();

            AuditEvent::query()->create([
                'operation_id' => $validated['operation_id'],
                'company_id' => $lockedCostCenter->company_id,
                'actor_id' => $actor->id,
                'event_type' => $eventType,
                'subject_type' => CostCenter::class,
                'subject_id' => $lockedCostCenter->id,
                'affected_exercise_ids' => [],
                'effective_from' => now($lockedCostCenter->company->timezone)->toDateString(),
                'previous_value' => $previous,
                'new_value' => $this->snapshot($lockedCostCenter),
                'allocated_impact_by_exercise' => [],
                'actual_impact_by_exercise' => [],
            ]);

            return $lockedCostCenter;
        });
    }

    /** @return array{name: string, archived: bool} */
    private function snapshot(CostCenter $costCenter): array
    {
        return [
            'name' => $costCenter->name,
            'archived' => $costCenter->isArchived(),
        ];
    }
}
