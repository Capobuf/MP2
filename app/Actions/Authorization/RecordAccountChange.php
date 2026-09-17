<?php

namespace App\Actions\Authorization;

use App\Domain\Company\AuditEventType;
use App\Models\AuditEvent;
use App\Models\Company;
use App\Models\User;

class RecordAccountChange
{
    public function execute(
        User $actor,
        User $beneficiary,
        string $previousName,
        string $previousEmail,
        bool $passwordChanged,
        string $operationId,
        int $eventSequence,
    ): ?AuditEvent {
        $previous = [];
        $new = [];
        if ($previousName !== $beneficiary->name) {
            $previous['name'] = $previousName;
            $new['name'] = $beneficiary->name;
        }
        if ($previousEmail !== $beneficiary->email) {
            $previous['email'] = $previousEmail;
            $new['email'] = $beneficiary->email;
        }
        if ($passwordChanged) {
            $new['password_changed'] = true;
        }
        if ($new === []) {
            return null;
        }

        $company = $beneficiary->company;
        if (! $company instanceof Company) {
            throw new \LogicException('Account changes can only be audited for a Tenant user.');
        }

        return AuditEvent::query()->create([
            'operation_id' => $operationId,
            'event_sequence' => $eventSequence,
            'company_id' => $company->id,
            'actor_id' => $actor->id,
            'event_type' => AuditEventType::AccountChanged,
            'subject_type' => User::class,
            'subject_id' => $beneficiary->id,
            'beneficiary_id' => $beneficiary->id,
            'affected_exercise_ids' => [],
            'effective_from' => now($company->timezone)->toDateString(),
            'previous_value' => $previous,
            'new_value' => $new,
            'allocated_impact_by_exercise' => [],
            'actual_impact_by_exercise' => [],
        ]);
    }
}
