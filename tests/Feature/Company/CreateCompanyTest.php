<?php

use App\Actions\CreateCompany;
use App\Domain\Company\AuditEventType;
use App\Domain\Company\Capability;
use App\Domain\Company\ClosingUnclassifiedPolicy;
use App\Domain\Company\TenantCompanyStatus;
use App\Models\AuditEvent;
use App\Models\Company;
use App\Models\CompanyCapability;
use App\Models\TenantCompany;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;

uses(RefreshDatabase::class);

it('creates a company with canonical defaults capabilities and audit atomically', function () {
    $administrator = User::factory()->platformAdmin()->create();

    $company = app(CreateCompany::class)->execute($administrator, [
        'name' => '  Capobuf S.r.l.  ',
        'timezone' => 'Europe/Rome',
    ]);

    expect($company->name)->toBe('Capobuf S.r.l.')
        ->and($company->timezone)->toBe('Europe/Rome')
        ->and($company->overspend_note_required)->toBeFalse()
        ->and($company->unclassified_closing_policy)->toBe(ClosingUnclassifiedPolicy::Warning)
        ->and($company->tenantCompany)->not->toBeNull()
        ->and($company->tenantCompany->company_id)->toBe($company->id)
        ->and($company->tenantCompany->status)->toBe(TenantCompanyStatus::Active)
        ->and($company->capabilities()->count())->toBe(count(Capability::cases()))
        ->and($company->auditEvents()->count())->toBe(10);

    foreach (Capability::cases() as $capability) {
        expect($administrator->hasCapability($company, $capability))->toBeTrue();
    }

    $companyEvent = $company->auditEvents()
        ->where('event_type', AuditEventType::CompanyCreated->value)
        ->sole();

    expect($companyEvent->subject_type)->toBe(Company::class)
        ->and($companyEvent->subject_id)->toBe($company->id)
        ->and($companyEvent->affected_exercise_ids)->toBe([])
        ->and($companyEvent->allocated_impact_by_exercise)->toBe([])
        ->and($companyEvent->actual_impact_by_exercise)->toBe([])
        ->and($companyEvent->effective_from->toDateString())->toBe(now('Europe/Rome')->toDateString());
});

it('rejects company creation by an ordinary user', function () {
    $user = User::factory()->create();

    expect(fn () => app(CreateCompany::class)->execute($user, [
        'name' => 'Non autorizzata',
        'timezone' => 'Europe/Rome',
    ]))->toThrow(AuthorizationException::class);

    expect(Company::query()->count())->toBe(0);
});

it('requires an explicit valid IANA timezone', function (array $input) {
    $administrator = User::factory()->platformAdmin()->create();

    expect(fn () => app(CreateCompany::class)->execute($administrator, $input))
        ->toThrow(ValidationException::class);

    expect(Company::query()->count())->toBe(0);
})->with([
    'missing' => [['name' => 'Azienda']],
    'blank' => [['name' => 'Azienda', 'timezone' => '']],
    'invalid' => [['name' => 'Azienda', 'timezone' => 'Europe/Nowhere']],
]);

it('rolls back the company initial assignments and audit on failure', function () {
    $administrator = User::factory()->platformAdmin()->create();
    AuditEvent::creating(function (): never {
        throw new RuntimeException('Forced audit failure');
    });

    expect(fn () => app(CreateCompany::class)->execute($administrator, [
        'name' => 'Rollback',
        'timezone' => 'Europe/Rome',
    ]))->toThrow(RuntimeException::class, 'Forced audit failure');

    expect(Company::query()->count())->toBe(0)
        ->and(TenantCompany::query()->count())->toBe(0)
        ->and(CompanyCapability::query()->count())->toBe(0)
        ->and(AuditEvent::query()->count())->toBe(0);

    AuditEvent::flushEventListeners();
});
