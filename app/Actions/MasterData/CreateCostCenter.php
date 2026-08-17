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

class CreateCostCenter
{
    /** @param array{name?: mixed} $input */
    public function execute(User $actor, Company $company, array $input, string $operationId): CostCenter
    {
        /** @var array{name: string, operation_id: string} $validated */
        $validated = Validator::make([
            'name' => is_string($input['name'] ?? null) ? trim($input['name']) : ($input['name'] ?? null),
            'operation_id' => $operationId,
        ], [
            'name' => ['required', 'string', 'max:255'],
            'operation_id' => ['required', 'uuid'],
        ])->validate();

        return DB::transaction(function () use ($actor, $company, $validated): CostCenter {
            $lockedCompany = Company::query()->lockForUpdate()->findOrFail($company->id);
            Gate::forUser($actor)->authorize('create', [CostCenter::class, $lockedCompany]);

            $existing = AuditEvent::query()->where('operation_id', $validated['operation_id'])->first();

            if ($existing !== null) {
                if (
                    $existing->eventType() !== AuditEventType::CostCenterCreated
                    || $existing->subject_type !== CostCenter::class
                    || $existing->company_id !== $lockedCompany->id
                ) {
                    throw ValidationException::withMessages([
                        'operation_id' => 'Identificativo operazione già utilizzato.',
                    ]);
                }

                return CostCenter::query()->findOrFail($existing->subject_id);
            }

            $costCenter = CostCenter::query()->create([
                'company_id' => $lockedCompany->id,
                'name' => $validated['name'],
            ]);

            AuditEvent::query()->create([
                'operation_id' => $validated['operation_id'],
                'company_id' => $lockedCompany->id,
                'actor_id' => $actor->id,
                'event_type' => AuditEventType::CostCenterCreated,
                'subject_type' => CostCenter::class,
                'subject_id' => $costCenter->id,
                'affected_exercise_ids' => [],
                'effective_from' => now($lockedCompany->timezone)->toDateString(),
                'previous_value' => null,
                'new_value' => $this->snapshot($costCenter),
                'allocated_impact_by_exercise' => [],
                'actual_impact_by_exercise' => [],
            ]);

            return $costCenter;
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
