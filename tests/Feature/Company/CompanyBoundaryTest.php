<?php

use App\Models\Company;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\Support\TestPermissions;

uses(RefreshDatabase::class);

it('has the final single-company Shield schema', function () {
    expect(Schema::hasColumn('users', 'company_id'))->toBeTrue()
        ->and(Schema::hasColumn('users', 'is_platform_admin'))->toBeFalse()
        ->and(Schema::hasTable('company_capabilities'))->toBeFalse()
        ->and(Schema::hasTable('roles'))->toBeTrue()
        ->and(Schema::hasTable('permissions'))->toBeTrue()
        ->and(Schema::hasColumn('roles', 'team_id'))->toBeFalse()
        ->and(Schema::hasColumn('permissions', 'team_id'))->toBeFalse();
});

it('keeps global permissions separate from the users single company boundary', function () {
    $user = User::factory()->create();
    $companyA = Company::factory()->create(['name' => 'Azienda A']);
    $companyB = Company::factory()->create(['name' => 'Azienda B']);

    grantTestPermissions([
        'company_id' => $companyA->id,
        'user' => $user,
        'permissions' => TestPermissions::VIEW,
    ]);

    expect($user->can(TestPermissions::VIEW[0]))->toBeTrue()
        ->and($user->canAccessTenant($companyA->tenantCompany))->toBeTrue()
        ->and($user->canAccessTenant($companyB->tenantCompany))->toBeFalse()
        ->and($user->getTenants(Filament::getPanel('admin'))->modelKeys())
        ->toBe([$companyA->id]);
});

it('allows super administrators to access every active tenant only', function () {
    $superAdmin = User::factory()->platformAdmin()->create();
    $active = Company::factory()->create();
    $archived = Company::factory()->create();
    $archived->tenantCompany->update(['status' => 'archived']);

    expect($superAdmin->company_id)->toBeNull()
        ->and($superAdmin->canAccessTenant($active->tenantCompany))->toBeTrue()
        ->and($superAdmin->canAccessTenant($archived->tenantCompany->refresh()))->toBeFalse()
        ->and($superAdmin->getTenants(Filament::getPanel('admin'))->modelKeys())
        ->toBe([$active->id]);
});
