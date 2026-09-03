<?php

use App\Actions\CreateCompany;
use App\Actions\UpdateCompanySettings;
use App\Domain\Company\AuditEventType;
use App\Domain\Company\ClosingUnclassifiedPolicy;
use App\Domain\Company\Setting;
use App\Models\AuditEvent;
use App\Models\Company;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

uses(RefreshDatabase::class);

it('updates changed settings prospectively with one complete event per field', function () {
    $administrator = User::factory()->platformAdmin()->create();
    $company = app(CreateCompany::class)->execute($administrator, [
        'name' => 'Azienda',
        'timezone' => 'Europe/Rome',
    ]);

    $changes = app(UpdateCompanySettings::class)->execute($administrator, $company, [
        'overspend_note_required' => true,
        'unclassified_closing_policy' => ClosingUnclassifiedPolicy::Blocking->value,
        'timezone' => 'Europe/Paris',
    ], timezonePreviewConfirmed: true);

    $company->refresh();
    expect($changes)->toBe(3)
        ->and($company->overspend_note_required)->toBeTrue()
        ->and($company->unclassified_closing_policy)->toBe(ClosingUnclassifiedPolicy::Blocking)
        ->and($company->timezone)->toBe('Europe/Paris');

    $events = AuditEvent::query()
        ->where('company_id', $company->id)
        ->where('event_type', AuditEventType::SettingChanged->value)
        ->get();

    expect($events)->toHaveCount(3)
        ->and($events->pluck('setting')->map(
            fn (Setting $setting): string => $setting->value,
        )->sort()->values()->all())->toBe([
            Setting::OverspendNoteRequired->value,
            Setting::Timezone->value,
            Setting::UnclassifiedClosingPolicy->value,
        ]);

    foreach ($events as $event) {
        expect($event->subject_type)->toBe(Company::class)
            ->and($event->subject_id)->toBe($company->id)
            ->and($event->affected_exercise_ids)->toBe([])
            ->and($event->allocated_impact_by_exercise)->toBe([])
            ->and($event->actual_impact_by_exercise)->toBe([]);
    }
});

it('requires a matching preview confirmation for timezone changes', function () {
    $administrator = User::factory()->platformAdmin()->create();
    $company = app(CreateCompany::class)->execute($administrator, [
        'name' => 'Azienda',
        'timezone' => 'Europe/Rome',
    ]);

    expect(fn () => app(UpdateCompanySettings::class)->execute($administrator, $company, [
        'overspend_note_required' => false,
        'unclassified_closing_policy' => ClosingUnclassifiedPolicy::Warning->value,
        'timezone' => 'Europe/Paris',
    ]))->toThrow(ValidationException::class);

    expect($company->refresh()->timezone)->toBe('Europe/Rome')
        ->and(AuditEvent::query()->where('event_type', AuditEventType::SettingChanged)->count())->toBe(0);
});

it('treats an identical settings submission as a no-op', function () {
    $administrator = User::factory()->platformAdmin()->create();
    $company = app(CreateCompany::class)->execute($administrator, [
        'name' => 'Azienda',
        'timezone' => 'Europe/Rome',
    ]);
    $eventCount = AuditEvent::query()->count();

    expect(app(UpdateCompanySettings::class)->execute($administrator, $company, [
        'overspend_note_required' => false,
        'unclassified_closing_policy' => ClosingUnclassifiedPolicy::Warning->value,
        'timezone' => 'Europe/Rome',
    ]))->toBe(0)
        ->and(AuditEvent::query()->count())->toBe($eventCount);
});

it('rejects a settings change without exact company authority', function () {
    $administrator = User::factory()->platformAdmin()->create();
    $user = User::factory()->create();
    $company = app(CreateCompany::class)->execute($administrator, [
        'name' => 'Azienda',
        'timezone' => 'Europe/Rome',
    ]);

    expect(fn () => app(UpdateCompanySettings::class)->execute($user, $company, [
        'overspend_note_required' => true,
        'unclassified_closing_policy' => ClosingUnclassifiedPolicy::Warning->value,
        'timezone' => 'Europe/Rome',
    ]))->toThrow(AuthorizationException::class);
});

it('rolls back settings when audit persistence fails', function () {
    $administrator = User::factory()->platformAdmin()->create();
    $company = app(CreateCompany::class)->execute($administrator, [
        'name' => 'Azienda',
        'timezone' => 'Europe/Rome',
    ]);
    $initialEventCount = AuditEvent::query()->count();
    AuditEvent::creating(function (): never {
        throw new RuntimeException('Forced audit failure');
    });

    expect(fn () => app(UpdateCompanySettings::class)->execute($administrator, $company, [
        'overspend_note_required' => true,
        'unclassified_closing_policy' => ClosingUnclassifiedPolicy::Blocking->value,
        'timezone' => 'Europe/Rome',
    ]))->toThrow(RuntimeException::class, 'Forced audit failure');

    $company->refresh();
    expect($company->overspend_note_required)->toBeFalse()
        ->and($company->unclassified_closing_policy)->toBe(ClosingUnclassifiedPolicy::Warning)
        ->and(AuditEvent::query()->count())->toBe($initialEventCount);

    AuditEvent::flushEventListeners();
});

it('stores replaces and removes a private company logo with audited cleanup', function (): void {
    Storage::fake('local');
    $administrator = User::factory()->platformAdmin()->create();
    $company = app(CreateCompany::class)->execute($administrator, ['name' => 'Azienda', 'timezone' => 'Europe/Rome']);
    $base = [
        'overspend_note_required' => false,
        'unclassified_closing_policy' => ClosingUnclassifiedPolicy::Warning->value,
        'timezone' => 'Europe/Rome',
    ];

    expect(app(UpdateCompanySettings::class)->execute($administrator, $company, $base + [
        'logo' => UploadedFile::fake()->image('logo.png', 240, 80),
    ]))->toBe(1);
    $firstPath = $company->refresh()->logo_path;
    expect($company->logo_disk)->toBe('local')->and($company->logo_media_type)->toBe('image/png');
    Storage::disk('local')->assertExists($firstPath);

    app(UpdateCompanySettings::class)->execute($administrator, $company, $base + [
        'logo' => UploadedFile::fake()->image('logo.jpg', 240, 80),
    ]);
    $secondPath = $company->refresh()->logo_path;
    Storage::disk('local')->assertMissing($firstPath)->assertExists($secondPath);

    app(UpdateCompanySettings::class)->execute($administrator, $company, $base + ['logo' => null]);
    expect($company->refresh()->logo_path)->toBeNull()
        ->and(AuditEvent::query()->where('setting', Setting::CompanyLogo)->count())->toBe(3);
    Storage::disk('local')->assertMissing($secondPath);
});

it('rejects unsupported logo content', function (): void {
    Storage::fake('local');
    $administrator = User::factory()->platformAdmin()->create();
    $company = app(CreateCompany::class)->execute($administrator, ['name' => 'Azienda', 'timezone' => 'Europe/Rome']);

    expect(fn () => app(UpdateCompanySettings::class)->execute($administrator, $company, [
        'overspend_note_required' => false,
        'unclassified_closing_policy' => ClosingUnclassifiedPolicy::Warning->value,
        'timezone' => 'Europe/Rome',
        'logo' => UploadedFile::fake()->createWithContent('logo.svg', '<svg><script>alert(1)</script></svg>'),
    ]))->toThrow(ValidationException::class);
});
