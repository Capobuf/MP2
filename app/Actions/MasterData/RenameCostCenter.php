<?php

namespace App\Actions\MasterData;

use App\Domain\Company\AuditEventType;
use App\Models\AuditEvent;
use App\Models\Company;
use App\Models\CostCenter;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class RenameCostCenter
{
    /** @param array{name?: mixed} $input */
    public function execute(User $actor, CostCenter $costCenter, array $input, string $operationId): CostCenter
    {
        /** @var array{name: string, operation_id: string} $validated */
        $validated = Validator::make([
            'name' => is_string($input['name'] ?? null) ? trim($input['name']) : ($input['name'] ?? null),
            'operation_id' => $operationId,
        ], [
            'name' => ['required', 'string', 'max:255'],
            'operation_id' => ['required', 'uuid'],
        ])->validate();

        return DB::transaction(function () use ($actor, $costCenter, $validated): CostCenter {
            Company::query()->lockForUpdate()->findOrFail($costCenter->company_id);
            $lockedCostCenter = CostCenter::query()->with('company')->lockForUpdate()->findOrFail($costCenter->id);
            Gate::forUser($actor)->authorize('update', $lockedCostCenter);

            $existing = AuditEvent::query()->where('operation_id', $validated['operation_id'])->first();

            if ($existing !== null) {
                if (
                    $existing->eventType() !== AuditEventType::CostCenterRenamed
                    || $existing->subject_type !== CostCenter::class
                    || $existing->subject_id !== $lockedCostCenter->id
                ) {
                    throw ValidationException::withMessages([
                        'operation_id' => 'Identificativo operazione già utilizzato.',
                    ]);
                }

                return $lockedCostCenter;
            }

            if ($lockedCostCenter->name === $validated['name']) {
                return $lockedCostCenter;
            }

            $previous = $this->snapshot($lockedCostCenter);
            $lockedCostCenter->forceFill(['name' => $validated['name']])->save();

            AuditEvent::query()->create([
                'operation_id' => $validated['operation_id'],
                'company_id' => $lockedCostCenter->company_id,
                'actor_id' => $actor->id,
                'event_type' => AuditEventType::CostCenterRenamed,
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
