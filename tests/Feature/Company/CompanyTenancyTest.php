<?php

use App\Actions\CreateCompany;
use App\Domain\Company\TenantCompanyStatus;
use App\Filament\Pages\Tenancy\RegisterCompany;
use App\Models\Company;
use App\Models\TenantCompany;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\Support\TestPermissions;

uses(RefreshDatabase::class);

it('allows the platform administrator to register a company through Filament', function () {
    $administrator = User::factory()->platformAdmin()->create();

    $this->actingAs($administrator);

    Livewire::test(RegisterCompany::class)
        ->fillForm([
            'name' => 'Azienda UI',
            'timezone' => 'Europe/Rome',
        ])
        ->call('register')
        ->assertHasNoFormErrors()
        ->assertSet('tenant', fn (mixed $tenant): bool => $tenant instanceof TenantCompany);

    $company = Company::query()->sole();

    expect($administrator->canAccessTenant($company->tenantCompany))->toBeTrue();
});

it('persists nothing when registration is abandoned before submission', function (): void {
    $administrator = User::factory()->platformAdmin()->create();
    $this->actingAs($administrator);

    Livewire::test(RegisterCompany::class)
        ->fillForm([
            'name' => 'Azienda non inviata',
            'timezone' => 'Europe/Rome',
        ])
        ->assertSet('tenant', null);

    expect(Company::query()->count())->toBe(0)
        ->and(TenantCompany::query()->count())->toBe(0);
});

it('hides company registration from ordinary users', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->get('/admin/new')
        ->assertForbidden();
});

it('rejects a guessed tenant URL outside the users view assignments', function () {
    $administrator = User::factory()->platformAdmin()->create();
    $companyA = app(CreateCompany::class)->execute($administrator, [
        'name' => 'Azienda A',
        'timezone' => 'Europe/Rome',
    ]);
    $companyB = app(CreateCompany::class)->execute($administrator, [
        'name' => 'Azienda B',
        'timezone' => 'Europe/Rome',
    ]);
    $user = User::factory()->create();
    grantTestPermissions([
        'company_id' => $companyA->id,
        'user' => $user,
        'permissions' => TestPermissions::VIEW,
    ]);

    $this->actingAs($user)
        ->get(Filament::getUrl($companyA->tenantCompany))
        ->assertOk();

    $this->actingAs($user)
        ->get(Filament::getUrl($companyB->tenantCompany))
        ->assertNotFound();
});

it('removes an archived Tenant from selection and rejects its known operational URL', function (): void {
    $company = Company::factory()->create();
    $user = User::factory()->create();
    grantTestPermissions([
        'company_id' => $company->id,
        'user' => $user,
        'permissions' => TestPermissions::VIEW,
    ]);
    $tenant = $company->tenantCompany;
    $tenant->update(['status' => TenantCompanyStatus::Archived]);

    expect($user->getTenants(Filament::getPanel('admin'))->modelKeys())
        ->toBe([])
        ->and($user->canAccessTenant($tenant))->toBeFalse();

    $this->actingAs($user)
        ->get(Filament::getUrl($tenant))
        ->assertForbidden();
});
