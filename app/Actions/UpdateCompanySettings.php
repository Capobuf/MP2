<?php

namespace App\Actions;

use App\Actions\Tenancy\DeletePendingTenantFiles;
use App\Domain\Company\AuditEventType;
use App\Domain\Company\ClosingUnclassifiedPolicy;
use App\Domain\Company\Setting;
use App\Models\AuditEvent;
use App\Models\Company;
use App\Models\User;
use DateTimeZone;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class UpdateCompanySettings
{
    public function __construct(private readonly DeletePendingTenantFiles $deletePendingTenantFiles) {}

    /** @param array{overspend_note_required?: mixed, unclassified_closing_policy?: mixed, timezone?: mixed, logo?: mixed} $input */
    public function execute(User $actor, Company $company, array $input, bool $timezonePreviewConfirmed = false): int
    {
        Gate::forUser($actor)->authorize('manageSettings', $company);
        $logoRequested = array_key_exists('logo', $input);

        /** @var array{overspend_note_required: bool, unclassified_closing_policy: string, timezone: string, logo?: UploadedFile|null} $validated */
        $validated = Validator::make($input, [
            'overspend_note_required' => ['required', 'boolean'],
            'unclassified_closing_policy' => ['required', Rule::enum(ClosingUnclassifiedPolicy::class)],
            'timezone' => ['required', 'string', 'max:64', Rule::in(DateTimeZone::listIdentifiers())],
            'logo' => ['sometimes', 'nullable', 'file', 'image', 'mimes:png,jpg,jpeg', 'max:2048'],
        ])->validate();

        $newFile = null;
        if ($logoRequested && ($validated['logo'] ?? null) instanceof UploadedFile) {
            $mediaType = $validated['logo']->getMimeType();
            $extension = $mediaType === 'image/png' ? 'png' : 'jpg';
            $path = Storage::disk('local')->putFileAs(
                'company-logos/'.$company->id,
                $validated['logo'],
                Str::uuid().'.'.$extension,
            );
            if (! is_string($path)) {
                throw ValidationException::withMessages(['logo' => 'Il logo non può essere salvato.']);
            }
            $newFile = ['disk' => 'local', 'path' => $path, 'media_type' => $mediaType];
        }

        try {
            $result = DB::transaction(function () use ($actor, $company, $validated, $timezonePreviewConfirmed, $logoRequested, $newFile): array {
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
                $changes = array_filter($requested, fn (bool|string $value, string $key): bool => $current[$key] !== $value, ARRAY_FILTER_USE_BOTH);

                if (array_key_exists(Setting::Timezone->value, $changes) && ! $timezonePreviewConfirmed) {
                    throw ValidationException::withMessages(['timezone' => 'Visualizza e conferma prima l’anteprima del cambio di fuso orario.']);
                }

                $oldFile = null;
                if ($logoRequested) {
                    $oldFile = is_string($lockedCompany->logo_disk) && is_string($lockedCompany->logo_path)
                        ? ['disk' => $lockedCompany->logo_disk, 'path' => $lockedCompany->logo_path]
                        : null;
                    $nextPath = $newFile['path'] ?? null;
                    if ($lockedCompany->logo_path !== $nextPath) {
                        $current[Setting::CompanyLogo->value] = $lockedCompany->logo_path === null ? 'non configurato' : 'configurato';
                        $requested[Setting::CompanyLogo->value] = $nextPath === null ? 'non configurato' : 'configurato';
                        $changes[Setting::CompanyLogo->value] = $requested[Setting::CompanyLogo->value];
                    } else {
                        $oldFile = null;
                    }
                }

                if ($changes === []) {
                    return ['changes' => 0, 'cleanup_operation' => null];
                }

                $settings = array_intersect_key($requested, array_flip([
                    Setting::OverspendNoteRequired->value,
                    Setting::UnclassifiedClosingPolicy->value,
                    Setting::Timezone->value,
                ]));
                if ($logoRequested) {
                    $settings += [
                        'logo_disk' => $newFile['disk'] ?? null,
                        'logo_path' => $newFile['path'] ?? null,
                        'logo_media_type' => $newFile['media_type'] ?? null,
                    ];
                }
                $lockedCompany->forceFill($settings)->save();
                $effectiveDate = now($lockedCompany->timezone)->toDateString();

                foreach ($changes as $key => $newValue) {
                    AuditEvent::query()->create([
                        'company_id' => $lockedCompany->id, 'actor_id' => $actor->id,
                        'event_type' => AuditEventType::SettingChanged, 'subject_type' => Company::class,
                        'subject_id' => $lockedCompany->id, 'setting' => Setting::from($key),
                        'affected_exercise_ids' => [], 'effective_from' => $effectiveDate,
                        'previous_value' => $current[$key], 'new_value' => $newValue,
                        'allocated_impact_by_exercise' => [], 'actual_impact_by_exercise' => [],
                    ]);
                }

                $cleanupOperation = $oldFile !== null && $oldFile['path'] !== ($newFile['path'] ?? null)
                    ? $this->queueDeletion($oldFile)
                    : null;

                return ['changes' => count($changes), 'cleanup_operation' => $cleanupOperation];
            });
        } catch (\Throwable $exception) {
            if ($newFile !== null) {
                $this->deleteOrQueue($newFile);
            }
            throw $exception;
        }

        if ($result['cleanup_operation'] !== null) {
            $this->deletePendingTenantFiles->execute($result['cleanup_operation']);
        }

        return $result['changes'];
    }

    /** @param array{disk: string, path: string} $file */
    private function queueDeletion(array $file): string
    {
        $operationId = (string) Str::uuid();
        DB::table('pending_file_deletions')->upsert([[
            'operation_id' => $operationId, 'storage_disk' => $file['disk'], 'storage_path' => $file['path'],
            'attempts' => 0, 'last_attempted_at' => null, 'last_error' => null,
            'created_at' => now(), 'updated_at' => now(),
        ]], ['storage_disk', 'storage_path'], ['operation_id', 'updated_at']);

        return $operationId;
    }

    /** @param array{disk: string, path: string} $file */
    private function deleteOrQueue(array $file): void
    {
        try {
            $deleted = Storage::disk($file['disk'])->delete($file['path']);
        } catch (\Throwable) {
            $deleted = false;
        }

        if (! $deleted) {
            $this->queueDeletion($file);
        }
    }
}
