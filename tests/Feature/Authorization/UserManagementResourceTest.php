<?php

use App\Filament\Platform\Resources\SuperAdmins\Pages\CreateSuperAdmin;
use App\Filament\Platform\Resources\SuperAdmins\Pages\ListSuperAdmins;
use App\Filament\Resources\Users\Pages\CreateUser;
use App\Filament\Resources\Users\Pages\ListUsers;
use App\Filament\Resources\Users\UserResource;
use App\Models\Company;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\Support\TestPermissions;

uses(RefreshDatabase::class);

it('lists only ordinary users belonging to the current tenant', function () {
    $company = Company::factory()->create();
    $otherCompany = Company::factory()->create();
    $manager = User::factory()->create();
    grantTestPermissions([
        'company_id' => $company->id,
        'user' => $manager,
        'permissions' => TestPermissions::MANAGE_PERMISSIONS,
    ]);
    $colleague = User::factory()->create(['company_id' => $company->id]);
    $outsider = User::factory()->create(['company_id' => $otherCompany->id]);
    $superAdmin = User::factory()->platformAdmin()->create();

    $this->actingAs($manager);
    $panel = Filament::getPanel('admin');
    Filament::setCurrentPanel($panel);
    $panel->boot();
    Filament::setTenant($company->tenantCompany);

    Livewire::test(ListUsers::class)
        ->assertCanSeeTableRecords([$manager, $colleague])
        ->assertCanNotSeeTableRecords([$outsider, $superAdmin]);

    $this->get(UserResource::getUrl('edit', ['record' => $outsider], tenant: $company->tenantCompany))
        ->assertNotFound();
});

it('creates a tenant user with a global Spatie role and rejects super_admin assignment', function () {
    $company = Company::factory()->create();
    $manager = User::factory()->create();
    grantTestPermissions([
        'company_id' => $company->id,
        'user' => $manager,
        'permissions' => TestPermissions::MANAGE_PERMISSIONS,
    ]);
    $ordinaryRole = Role::query()->create(['name' => 'Operatore', 'guard_name' => 'web']);
    $superRole = Role::query()->firstOrCreate(['name' => 'super_admin', 'guard_name' => 'web']);

    $this->actingAs($manager);
    $panel = Filament::getPanel('admin');
    Filament::setCurrentPanel($panel);
    $panel->boot();
    Filament::setTenant($company->tenantCompany);

    Livewire::test(CreateUser::class)
        ->fillForm([
            'name' => 'Nuovo utente',
            'email' => 'nuovo@example.test',
            'password' => 'password-sicura',
            'roles' => [$ordinaryRole->id],
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    $created = User::query()->where('email', 'nuovo@example.test')->sole();
    expect($created->company_id)->toBe($company->id)
        ->and($created->roles->modelKeys())->toBe([$ordinaryRole->id]);

    Livewire::test(CreateUser::class)
        ->fillForm([
            'name' => 'Tentativo elevazione',
            'email' => 'elevazione@example.test',
            'password' => 'password-sicura',
            'roles' => [$superRole->id],
        ])
        ->call('create')
        ->assertHasFormErrors(['roles.0']);

    expect(User::query()->where('email', 'elevazione@example.test')->exists())->toBeFalse();
});

it('manages only super administrators through the Platform resource', function () {
    $administrator = User::factory()->platformAdmin()->create();
    $company = Company::factory()->create();
    $tenantUser = User::factory()->create(['company_id' => $company->id]);
    grantTestPermissions([
        'company_id' => $company->id,
        'user' => $administrator,
        'permissions' => TestPermissions::MANAGE_PERMISSIONS,
    ]);

    $this->actingAs($administrator);
    Filament::setCurrentPanel('platform');
    Filament::setTenant(null);

    Livewire::test(ListSuperAdmins::class)
        ->assertCanSeeTableRecords([$administrator])
        ->assertCanNotSeeTableRecords([$tenantUser]);

    Livewire::test(CreateSuperAdmin::class)
        ->fillForm([
            'name' => 'Secondo Super Admin',
            'email' => 'secondo-admin@example.test',
            'password' => 'password-sicura',
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    $created = User::query()->where('email', 'secondo-admin@example.test')->sole();
    expect($created->company_id)->toBeNull()
        ->and($created->hasRole('super_admin'))->toBeTrue();
});
