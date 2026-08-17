<?php

namespace App\Actions;

use App\Domain\Company\AuditEventType;
use App\Domain\Company\ClosingUnclassifiedPolicy;
use App\Domain\Company\Setting;
use App\Models\AuditEvent;
use App\Models\Company;
use App\Models\User;
use DateTimeZone;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class UpdateCompanySettings
{
    /**
     * @param  array{overspend_note_required?: mixed, unclassified_closing_policy?: mixed, timezone?: mixed}  $input
     */
    public function execute(
        User $actor,
        Company $company,
        array $input,
        bool $timezonePreviewConfirmed = false,
    ): int {
        /** @var array{overspend_note_required: bool, unclassified_closing_policy: string, timezone: string} $validated */
        $validated = Validator::make($input, [
            'overspend_note_required' => ['required', 'boolean'],
            'unclassified_closing_policy' => [
                'required',
                Rule::enum(ClosingUnclassifiedPolicy::class),
            ],
            'timezone' => [
                'required',
                'string',
                'max:64',
                Rule::in(DateTimeZone::listIdentifiers()),
            ],
        ])->validate();

        return DB::transaction(function () use (
            $actor,
            $company,
            $validated,
            $timezonePreviewConfirmed,
        ): int {
            $lockedCompany = Company::query()->lockForUpdate()->findOrFail($company->id);
            Gate::forUser($actor)->authorize('manageSettings', $lockedCompany);

            $current = [
                Setting::OverspendNoteRequired->value => $lockedCompany->overspend_note_required,
                Setting::UnclassifiedClosingPolicy->value => $lockedCompany->closingUnclassifiedPolicy()->value,
                Setting::Timezone->value => $lockedCompany->timezone,
            ];
            $requested = [
                Setting::OverspendNoteRequired->value => $validated['overspend_note_required'],
                Setting::UnclassifiedClosingPolicy->value => $validated['unclassified_closing_policy'],
                Setting::Timezone->value => $validated['timezone'],
            ];
            $changes = array_filter(
                $requested,
                fn (bool|string $value, string $key): bool => $current[$key] !== $value,
                ARRAY_FILTER_USE_BOTH,
            );

            if (array_key_exists(Setting::Timezone->value, $changes) && ! $timezonePreviewConfirmed) {
                throw ValidationException::withMessages([
                    'timezone' => 'Visualizza e conferma prima l’anteprima del cambio di fuso orario.',
                ]);
            }

            if ($changes === []) {
                return 0;
            }

            $effectiveDate = now($lockedCompany->timezone)->toDateString();
            $lockedCompany->forceFill($requested)->save();

            foreach ($changes as $key => $newValue) {
                AuditEvent::query()->create([
                    'company_id' => $lockedCompany->id,
                    'actor_id' => $actor->id,
                    'event_type' => AuditEventType::SettingChanged,
                    'subject_type' => Company::class,
                    'subject_id' => $lockedCompany->id,
                    'setting' => Setting::from($key),
                    'affected_exercise_ids' => [],
                    'effective_from' => $effectiveDate,
                    'previous_value' => $current[$key],
                    'new_value' => $newValue,
                    'allocated_impact_by_exercise' => [],
                    'actual_impact_by_exercise' => [],
                ]);
            }

            return count($changes);
        });
    }
}
