<?php

use App\Actions\Operations\CreateExercise;
use App\Actions\Operations\ProcessContractRenewals;
use App\Actions\Tenancy\ArchiveTenantCompany;
use App\Actions\Tenancy\RestoreTenantCompany;
use App\Domain\Company\AuditEventType;
use App\Domain\Company\TenantCompanyStatus;
use App\Models\BudgetSnapshot;
use App\Models\Company;
use App\Models\Contract;
use App\Models\ContractCondition;
use App\Models\ContractLifecycleFact;
use App\Models\ContractRenewalConfiguration;
use App\Models\Exercise;
use App\Models\Project;
use App\Models\Proposal;
use App\Models\Supplier;
use App\Models\TenantCompany;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Tests\Support\TestPermissions;

uses(RefreshDatabase::class);

it('archives and restores only the technical Tenant while preserving the Company domain', function (): void {
    $platformAdmin = User::factory()->platformAdmin()->create();
    $operator = User::factory()->create();
    $company = Company::factory()->create();
    $exercise = Exercise::factory()->for($company)->create(['year' => 2026]);

    foreach (TestPermissions::all() as $capability) {
        grantTestPermissions([
            'company_id' => $company->id,
            'user' => $operator,
            'permissions' => $capability,
        ]);
    }

    $supplier = Supplier::factory()->for($company)->create();
    $project = Project::factory()->for($company)->create();
    $contract = Contract::factory()->for($company)->for($supplier)->create();
    $proposal = Proposal::factory()->for($company)->create([
        'exercise_id' => $exercise->id,
        'created_by_id' => $operator->id,
    ]);
    $budget = BudgetSnapshot::factory()->create([
        'company_id' => $company->id,
        'exercise_id' => $exercise->id,
        'proposal_id' => $proposal->id,
        'approved_by_id' => $operator->id,
    ]);
    $closing = closeExerciseFixture($exercise, $operator);

    $tenant = $company->tenantCompany;
    $companyBefore = $company->refresh()->getAttributes();
    $permissionsBefore = $operator->getAllPermissions()->pluck('name')->sort()->values()->all();
    $domainRecords = collect([$exercise, $proposal, $budget, $closing, $contract, $project]);
    $domainBefore = $domainRecords
        ->map(fn ($record): array => $record->refresh()->getAttributes())
        ->all();

    app(ArchiveTenantCompany::class)->execute($platformAdmin, $tenant);

    expect($tenant->refresh()->status)->toBe(TenantCompanyStatus::Archived)
        ->and($company->refresh()->getAttributes())->toBe($companyBefore)
        ->and($operator->getAllPermissions()->pluck('name')->sort()->values()->all())->toBe($permissionsBefore)
        ->and($domainRecords->map(fn ($record): array => $record->refresh()->getAttributes())->all())->toBe($domainBefore)
        ->and($operator->can(TestPermissions::MANAGE_OPERATIONS[0]))->toBeTrue()
        ->and($operator->canAccessTenant($tenant))->toBeFalse();

    expect(fn () => app(CreateExercise::class)->execute(
        $operator,
        $company,
        ['year' => 2027],
        (string) Str::uuid(),
    ))->toThrow(AuthorizationException::class);

    expect(fn () => app(ArchiveTenantCompany::class)->execute($platformAdmin, $tenant))
        ->toThrow(ValidationException::class);

    app(RestoreTenantCompany::class)->execute($platformAdmin, $tenant);

    expect($tenant->refresh()->status)->toBe(TenantCompanyStatus::Active)
        ->and($operator->can(TestPermissions::MANAGE_OPERATIONS[0]))->toBeTrue()
        ->and($operator->canAccessTenant($tenant))->toBeTrue()
        ->and($company->refresh()->getAttributes())->toBe($companyBefore)
        ->and($domainRecords->map(fn ($record): array => $record->refresh()->getAttributes())->all())->toBe($domainBefore);
});

it('denies lifecycle transitions to a user even when every Company capability is assigned', function (): void {
    $user = User::factory()->create();
    $company = Company::factory()->create();

    foreach (TestPermissions::all() as $capability) {
        grantTestPermissions([
            'company_id' => $company->id,
            'user' => $user,
            'permissions' => $capability,
        ]);
    }

    expect(fn () => app(ArchiveTenantCompany::class)->execute($user, $company->tenantCompany))
        ->toThrow(AuthorizationException::class);

    expect($company->tenantCompany->refresh()->status)->toBe(TenantCompanyStatus::Active);
});

it('rolls an Archive back when persistence fails', function (): void {
    $platformAdmin = User::factory()->platformAdmin()->create();
    $tenant = Company::factory()->create()->tenantCompany;
    TenantCompany::updating(function (): never {
        throw new RuntimeException('Forced Tenant status failure');
    });

    expect(fn () => app(ArchiveTenantCompany::class)->execute($platformAdmin, $tenant))
        ->toThrow(RuntimeException::class, 'Forced Tenant status failure');

    expect($tenant->refresh()->status)->toBe(TenantCompanyStatus::Active);
    TenantCompany::flushEventListeners();
});

it('rejects an automatic renewal selected before Archive when mutation starts afterward', function (): void {
    $platformAdmin = User::factory()->platformAdmin()->create();
    $operator = User::factory()->create();
    $company = Company::factory()->create(['timezone' => 'Europe/Rome']);
    foreach ([TestPermissions::VIEW, TestPermissions::MANAGE_OPERATIONS] as $capability) {
        grantTestPermissions([
            'company_id' => $company->id,
            'user' => $operator,
            'permissions' => $capability,
        ]);
    }
    Exercise::factory()->for($company)->create(['year' => 2026]);
    $supplier = Supplier::factory()->for($company)->create();
    $contract = Contract::factory()->for($company)->for($supplier)->create([
        'contractual_start_date' => '2023-01-01',
        'next_expiry_date' => '2024-12-31',
        'renewal_anchor_date' => '2024-12-31',
        'automatic_renewal' => true,
        'renewal_duration_months' => 12,
    ]);
    ContractLifecycleFact::factory()->forContract($contract)->create([
        'type' => 'activation',
        'declared_contractual_date' => '2023-01-01',
        'state_change_date' => '2023-01-01',
        'created_by_id' => $operator->id,
    ]);
    ContractRenewalConfiguration::factory()->forContract($contract)->create([
        'effective_from' => '2023-01-01',
        'automatic_renewal' => true,
        'expiry_anchor_date' => '2024-12-31',
        'renewal_duration_months' => 12,
        'created_by_id' => $operator->id,
    ]);
    ContractCondition::factory()->forContract($contract)->create([
        'valid_from' => '2023-01-01',
        'valid_to' => null,
        'created_by_id' => $operator->id,
    ]);
    $selectedBeforeArchive = Contract::query()->findOrFail($contract->id);

    app(ArchiveTenantCompany::class)->execute($platformAdmin, $company->tenantCompany);

    expect(fn () => app(ProcessContractRenewals::class)->execute(
        $operator,
        $selectedBeforeArchive,
        (string) Str::uuid(),
    ))->toThrow(AuthorizationException::class);

    expect($contract->refresh()->next_expiry_date->toDateString())->toBe('2024-12-31')
        ->and($contract->lifecycleFacts()->where('type', 'renewal')->count())->toBe(0)
        ->and($company->auditEvents()->where('event_type', AuditEventType::ContractRenewed->value)->count())->toBe(0);
});
