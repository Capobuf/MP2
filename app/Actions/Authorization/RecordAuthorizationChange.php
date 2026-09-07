<?php

namespace App\Actions\Authorization;

use App\Domain\Company\AuditEventType;
use App\Models\AuditEvent;
use App\Models\Company;
use App\Models\User;
use Illuminate\Support\Facades\Validator;

class RecordAuthorizationChange
{
    /**
     * @param  array<int, string>  $previousRoles
     * @param  array<int, string>  $newRoles
     * @param  array<int, string>  $previousPermissions
     * @param  array<int, string>  $newPermissions
     */
    public function execute(
        User $actor,
        User $beneficiary,
        array $previousRoles,
        array $newRoles,
        array $previousPermissions,
        array $newPermissions,
        string $operationId,
        int $eventSequence = 0,
        ?string $reason = null,
    ): ?AuditEvent {
        Validator::make([
            'operation_id' => $operationId,
            'event_sequence' => $eventSequence,
        ], [
            'operation_id' => ['required', 'uuid'],
            'event_sequence' => ['required', 'integer', 'min:0'],
        ])->validate();

        $previousRoles = $this->normalize($previousRoles);
        $newRoles = $this->normalize($newRoles);
        $previousPermissions = $this->normalize($previousPermissions);
        $newPermissions = $this->normalize($newPermissions);

        if ($previousRoles === $newRoles && $previousPermissions === $newPermissions) {
            return null;
        }

        $company = $beneficiary->company;
        if (! $company instanceof Company) {
            throw new \LogicException('Authorization changes can only be audited for a Tenant user.');
        }

        return AuditEvent::query()->create([
            'operation_id' => $operationId,
            'event_sequence' => $eventSequence,
            'company_id' => $company->id,
            'actor_id' => $actor->id,
            'event_type' => AuditEventType::AuthorizationChanged,
            'subject_type' => User::class,
            'subject_id' => $beneficiary->id,
            'beneficiary_id' => $beneficiary->id,
            'affected_exercise_ids' => [],
            'effective_from' => now($company->timezone)->toDateString(),
            'previous_value' => [
                'roles' => $previousRoles,
                'permissions' => $previousPermissions,
            ],
            'new_value' => [
                'roles' => $newRoles,
                'permissions' => $newPermissions,
                'assigned_roles' => array_values(array_diff($newRoles, $previousRoles)),
                'revoked_roles' => array_values(array_diff($previousRoles, $newRoles)),
                'assigned_permissions' => array_values(array_diff($newPermissions, $previousPermissions)),
                'revoked_permissions' => array_values(array_diff($previousPermissions, $newPermissions)),
            ],
            'allocated_impact_by_exercise' => [],
            'actual_impact_by_exercise' => [],
            'reason' => $reason,
        ]);
    }

    /**
     * @param  array<int, string>  $values
     * @return array<int, string>
     */
    private function normalize(array $values): array
    {
        $values = array_values(array_unique($values));
        sort($values);

        return $values;
    }
}
