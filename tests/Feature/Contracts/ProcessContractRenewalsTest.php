<?php

use App\Actions\Operations\ProcessContractRenewals;
use App\Actions\Tenancy\ArchiveTenantCompany;
use App\Actions\Tenancy\RestoreTenantCompany;
use App\Domain\Company\AuditEventType;
use App\Models\AuditEvent;
use App\Models\Company;
use App\Models\Contract;
use App\Models\ContractCondition;
use App\Models\ContractLifecycleFact;
use App\Models\ContractRenewalConfiguration;
use App\Models\Exercise;
use App\Models\Supplier;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\Support\TestPermissions;

uses(RefreshDatabase::class);

beforeEach(function () {
    CarbonImmutable::setTestNow('2026-08-20 10:00:00 Europe/Rome');
});

afterEach(fn () => CarbonImmutable::setTestNow());

function renewalFixture(array $contractOverrides = []): array
{
    $actor = User::factory()->create();
    $company = Company::factory()->create(['timezone' => 'Europe/Rome']);
    foreach ([TestPermissions::VIEW, TestPermissions::MANAGE_OPERATIONS] as $capability) {
        grantTestPermissions(['company_id' => $company->id, 'user' => $actor, 'permissions' => $capability]);
    }
    Exercise::factory()->for($company)->create(['year' => 2026, 'status' => 'open']);
    $supplier = Supplier::factory()->for($company)->create();
    $contract = Contract::factory()->for($company)->for($supplier)->create(array_merge([
        'contractual_start_date' => '2023-01-01',
        'next_expiry_date' => '2024-12-31',
        'renewal_anchor_date' => '2024-12-31',
        'automatic_renewal' => true,
        'renewal_duration_months' => 12,
    ], $contractOverrides));
    ContractLifecycleFact::factory()->forContract($contract)->create([
        'type' => 'activation', 'declared_contractual_date' => '2023-01-01', 'state_change_date' => '2023-01-01', 'created_by_id' => $actor->id,
    ]);
    $configuration = ContractRenewalConfiguration::factory()->forContract($contract)->create([
        'effective_from' => '2023-01-01',
        'automatic_renewal' => $contract->automatic_renewal,
        'expiry_anchor_date' => $contract->renewal_anchor_date?->toDateString(),
        'renewal_duration_months' => $contract->renewal_duration_months,
        'created_by_id' => $actor->id,
    ]);
    ContractCondition::factory()->forContract($contract)->create([
        'valid_from' => '2023-01-01', 'valid_to' => null, 'created_by_id' => $actor->id,
    ]);

    return compact('actor', 'company', 'contract', 'configuration');
}

it('processes every elapsed renewal chronologically once and retry is a no-op', function () {
    extract(renewalFixture());

    app(ProcessContractRenewals::class)->execute($actor, $contract, (string) Str::uuid());
    app(ProcessContractRenewals::class)->execute($actor, $contract->refresh(), (string) Str::uuid());

    expect($contract->refresh()->next_expiry_date->toDateString())->toBe('2026-12-31')
        ->and($contract->lifecycleFacts()->where('type', 'renewal')->orderBy('renewed_expiry_date')->pluck('renewed_expiry_date')->map->toDateString()->all())
        ->toBe(['2024-12-31', '2025-12-31'])
        ->and(AuditEvent::query()->where('event_type', AuditEventType::ContractRenewed)->count())->toBe(2);
});

it('materializes non-renewed expiry as cessated from the following day', function () {
    extract(renewalFixture([
        'next_expiry_date' => '2025-12-31', 'renewal_anchor_date' => '2025-12-31', 'automatic_renewal' => false, 'renewal_duration_months' => null,
    ]));
    app(ProcessContractRenewals::class)->execute($actor, $contract, (string) Str::uuid());

    expect($contract->refresh()->next_expiry_date)->toBeNull()
        ->and($contract->stateAtDate('2025-12-31')->value)->toBe('active')
        ->and($contract->stateAtDate('2026-01-01')->value)->toBe('cessated');
});

it('runs from the command without opening a page and isolates a broken contract transaction', function () {
    extract(renewalFixture());
    $broken = Contract::factory()->for($company)->create([
        'contractual_start_date' => '2023-01-01',
        'next_expiry_date' => '2025-12-31', 'renewal_anchor_date' => '2025-12-31',
    ]);

    $this->artisan('contracts:process-renewals')->assertSuccessful();
    $this->artisan('contracts:process-renewals')->assertSuccessful();

    expect($contract->refresh()->next_expiry_date->toDateString())->toBe('2026-12-31')
        ->and($broken->refresh()->next_expiry_date->toDateString())->toBe('2025-12-31');
});

it('skips archived Tenants and catches up from real dates after Restore', function (): void {
    $platformAdmin = User::factory()->platformAdmin()->create();
    $archivedFixture = renewalFixture();
    $activeFixture = renewalFixture();
    $archivedContract = $archivedFixture['contract'];
    $activeContract = $activeFixture['contract'];

    app(ArchiveTenantCompany::class)->execute(
        $platformAdmin,
        $archivedFixture['company']->tenantCompany,
    );

    $this->artisan('contracts:process-renewals')->assertSuccessful();

    expect($archivedContract->refresh()->next_expiry_date->toDateString())->toBe('2024-12-31')
        ->and($archivedContract->lifecycleFacts()->where('type', 'renewal')->count())->toBe(0)
        ->and(AuditEvent::query()
            ->where('company_id', $archivedFixture['company']->id)
            ->where('event_type', AuditEventType::ContractRenewed->value)
            ->count())->toBe(0)
        ->and($activeContract->refresh()->next_expiry_date->toDateString())->toBe('2026-12-31');

    app(RestoreTenantCompany::class)->execute(
        $platformAdmin,
        $archivedFixture['company']->tenantCompany,
    );

    $this->artisan('contracts:process-renewals')->assertSuccessful();
    $this->artisan('contracts:process-renewals')->assertSuccessful();

    expect($archivedContract->refresh()->next_expiry_date->toDateString())->toBe('2026-12-31')
        ->and($archivedContract->lifecycleFacts()->where('type', 'renewal')->count())->toBe(2)
        ->and(AuditEvent::query()
            ->where('company_id', $archivedFixture['company']->id)
            ->where('event_type', AuditEventType::ContractRenewed->value)
            ->count())->toBe(2);
});
