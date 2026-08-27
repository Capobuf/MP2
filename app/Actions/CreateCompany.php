<?php

namespace App\Actions;

use App\Domain\Company\AuditEventType;
use App\Domain\Company\Capability;
use App\Domain\Company\ClosingUnclassifiedPolicy;
use App\Domain\Company\TenantCompanyStatus;
use App\Models\AuditEvent;
use App\Models\Company;
use App\Models\CompanyCapability;
use App\Models\TenantCompany;
use App\Models\User;
use DateTimeZone;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

class CreateCompany
{
    /**
     * @param  array{name?: mixed, timezone?: mixed}  $input
     */
    public function execute(User $actor, array $input): Company
    {
        Gate::forUser($actor)->authorize('create', Company::class);

        if (is_string($input['name'] ?? null)) {
            $input['name'] = trim($input['name']);
        }

        /** @var array{name: string, timezone: string} $validated */
        $validated = Validator::make($input, [
            'name' => ['required', 'string', 'max:255'],
            'timezone' => ['required', 'string', 'max:64', Rule::in(DateTimeZone::listIdentifiers())],
        ])->validate();

        return DB::transaction(function () use ($actor, $validated): Company {
            $company = Company::query()->create([
                ...$validated,
                'overspend_note_required' => false,
                'unclassified_closing_policy' => ClosingUnclassifiedPolicy::Warning,
            ]);

            TenantCompany::query()->create([
                'company_id' => $company->getKey(),
                'status' => TenantCompanyStatus::Active,
            ]);

            $this->recordCompanyCreated($company, $actor);

            foreach (Capability::cases() as $capability) {
                CompanyCapability::query()->create([
                    'company_id' => $company->id,
                    'user_id' => $actor->id,
                    'capability' => $capability,
                ]);

                $this->recordCapabilityAssigned($company, $actor, $capability);
            }

            return $company->refresh()->load('tenantCompany');
        });
    }

    private function recordCompanyCreated(Company $company, User $actor): void
    {
        AuditEvent::query()->create([
            ...$this->eventEnvelope($company, $actor),
            'event_type' => AuditEventType::CompanyCreated,
            'subject_type' => Company::class,
            'subject_id' => $company->id,
            'new_value' => [
                'name' => $company->name,
                'timezone' => $company->timezone,
                'overspend_note_required' => $company->overspend_note_required,
                'unclassified_closing_policy' => ClosingUnclassifiedPolicy::Warning->value,
            ],
        ]);
    }

    private function recordCapabilityAssigned(Company $company, User $actor, Capability $capability): void
    {
        AuditEvent::query()->create([
            ...$this->eventEnvelope($company, $actor),
            'event_type' => AuditEventType::CapabilityAssigned,
            'subject_type' => User::class,
            'subject_id' => $actor->id,
            'beneficiary_id' => $actor->id,
            'capability' => $capability,
            'previous_value' => false,
            'new_value' => true,
        ]);
    }

    /** @return array<string, mixed> */
    private function eventEnvelope(Company $company, User $actor): array
    {
        return [
            'company_id' => $company->id,
            'actor_id' => $actor->id,
            'affected_exercise_ids' => [],
            'effective_from' => now($company->timezone)->toDateString(),
            'allocated_impact_by_exercise' => [],
            'actual_impact_by_exercise' => [],
        ];
    }
}
