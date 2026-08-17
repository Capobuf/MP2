<?php

namespace App\Actions;

use App\Domain\Company\AuditEventType;
use App\Domain\Company\Capability;
use App\Models\AuditEvent;
use App\Models\Company;
use App\Models\CompanyCapability;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

class SyncCompanyCapabilities
{
    /**
     * @param  array<int, Capability|string>  $requestedCapabilities
     */
    public function execute(
        User $actor,
        Company $company,
        User $beneficiary,
        array $requestedCapabilities,
        ?string $reason = null,
    ): int {
        $values = array_map(
            fn (Capability|string $capability): string => $capability instanceof Capability
                ? $capability->value
                : $capability,
            $requestedCapabilities,
        );

        /** @var array{capabilities: array<int, string>, reason: string|null} $validated */
        $validated = Validator::make([
            'capabilities' => array_values(array_unique($values)),
            'reason' => filled($reason) ? trim((string) $reason) : null,
        ], [
            'capabilities' => ['array'],
            'capabilities.*' => ['string', Rule::enum(Capability::class)],
            'reason' => ['nullable', 'string', 'max:2000'],
        ])->validate();

        return DB::transaction(function () use ($actor, $company, $beneficiary, $validated): int {
            $lockedCompany = Company::query()->lockForUpdate()->findOrFail($company->id);
            Gate::forUser($actor)->authorize('managePermissions', $lockedCompany);

            $assignments = CompanyCapability::query()
                ->where('company_id', $lockedCompany->id)
                ->where('user_id', $beneficiary->id)
                ->lockForUpdate()
                ->get();

            $current = $assignments->pluck('capability')
                ->map(fn (Capability $capability): string => $capability->value)
                ->all();
            $requested = $validated['capabilities'];
            $additions = array_values(array_diff($requested, $current));
            $removals = array_values(array_diff($current, $requested));

            foreach ($additions as $value) {
                $capability = Capability::from($value);
                CompanyCapability::query()->create([
                    'company_id' => $lockedCompany->id,
                    'user_id' => $beneficiary->id,
                    'capability' => $capability,
                ]);
                $this->recordChange(
                    $lockedCompany,
                    $actor,
                    $beneficiary,
                    $capability,
                    AuditEventType::CapabilityAssigned,
                    false,
                    true,
                    $validated['reason'],
                );
            }

            foreach ($removals as $value) {
                $capability = Capability::from($value);
                $assignments->firstWhere('capability', $capability)?->delete();
                $this->recordChange(
                    $lockedCompany,
                    $actor,
                    $beneficiary,
                    $capability,
                    AuditEventType::CapabilityRevoked,
                    true,
                    false,
                    $validated['reason'],
                );
            }

            return count($additions) + count($removals);
        });
    }

    private function recordChange(
        Company $company,
        User $actor,
        User $beneficiary,
        Capability $capability,
        AuditEventType $eventType,
        bool $previousValue,
        bool $newValue,
        ?string $reason,
    ): void {
        AuditEvent::query()->create([
            'company_id' => $company->id,
            'actor_id' => $actor->id,
            'event_type' => $eventType,
            'subject_type' => User::class,
            'subject_id' => $beneficiary->id,
            'beneficiary_id' => $beneficiary->id,
            'capability' => $capability,
            'affected_exercise_ids' => [],
            'effective_from' => now($company->timezone)->toDateString(),
            'previous_value' => $previousValue,
            'new_value' => $newValue,
            'allocated_impact_by_exercise' => [],
            'actual_impact_by_exercise' => [],
            'reason' => $reason,
        ]);
    }
}
