<?php

use App\Domain\Expenses\ExerciseStatus;
use App\Models\Company;
use App\Models\Exercise;
use App\Models\Expense;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schema;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\Support\TestPermissions;

uses(RefreshDatabase::class);

it('restricts panel access to the structural user category', function () {
    $superAdmin = User::factory()->platformAdmin()->create();
    $company = Company::factory()->create();
    $tenantUser = User::factory()->create(['company_id' => $company->id]);
    $orphan = User::factory()->create(['company_id' => null]);

    expect($superAdmin->canAccessPanel(Filament::getPanel('platform')))->toBeTrue()
        ->and($superAdmin->canAccessPanel(Filament::getPanel('admin')))->toBeTrue()
        ->and($tenantUser->canAccessPanel(Filament::getPanel('platform')))->toBeFalse()
        ->and($tenantUser->canAccessPanel(Filament::getPanel('admin')))->toBeTrue()
        ->and($orphan->canAccessPanel(Filament::getPanel('admin')))->toBeFalse();
});

it('uses global roles without Teams or tenant membership pivots', function () {
    expect(config('permission.teams'))->toBeFalse()
        ->and(config('filament-shield.super_admin.enabled'))->toBeTrue()
        ->and(config('filament-shield.super_admin.define_via_gate'))->toBeFalse()
        ->and(config('filament-shield.panel_user.enabled'))->toBeFalse()
        ->and(Schema::hasColumn('roles', 'team_id'))->toBeFalse()
        ->and(Schema::hasColumn('model_has_roles', 'team_id'))->toBeFalse()
        ->and(Schema::hasTable('company_user'))->toBeFalse();
});

it('assigns generated Shield permissions explicitly to super administrators', function () {
    Artisan::call('shield:generate', [
        '--all' => true,
        '--option' => 'permissions',
        '--panel' => 'admin',
    ]);

    $superAdmin = User::factory()->platformAdmin()->create();

    expect(Permission::query()->count())->toBeGreaterThan(0)
        ->and($superAdmin->getAllPermissions()->modelKeys())
        ->toEqualCanonicalizing(Permission::query()->pluck('id')->all());
});

it('reuses one global role across users belonging to different companies', function () {
    $permission = Permission::query()->firstOrCreate([
        'name' => 'ViewAny:Expense',
        'guard_name' => 'web',
    ]);
    $role = Role::query()->create(['name' => 'Lettore globale', 'guard_name' => 'web']);
    $role->givePermissionTo($permission);
    $companyA = Company::factory()->create();
    $companyB = Company::factory()->create();
    $userA = User::factory()->create(['company_id' => $companyA->id]);
    $userB = User::factory()->create(['company_id' => $companyB->id]);
    $userA->assignRole($role);
    $userB->assignRole($role);

    expect($userA->can('ViewAny:Expense'))->toBeTrue()
        ->and($userB->can('ViewAny:Expense'))->toBeTrue()
        ->and($userA->company_id)->not->toBe($userB->company_id);
});

it('does not let a permission or super admin role bypass a closed exercise invariant', function () {
    $company = Company::factory()->create();
    $exercise = Exercise::factory()->for($company)->create(['status' => ExerciseStatus::Closed]);
    $expense = Expense::factory()->forExercise($exercise)->create();
    $operator = User::factory()->create();
    grantTestPermissions([
        'company_id' => $company->id,
        'user' => $operator,
        'permissions' => TestPermissions::MANAGE_OPERATIONS,
    ]);
    $superAdmin = User::factory()->platformAdmin()->create();
    grantTestPermissions([
        'company_id' => $company->id,
        'user' => $superAdmin,
        'permissions' => TestPermissions::MANAGE_OPERATIONS,
    ]);

    expect($operator->can('Update:Expense'))->toBeTrue()
        ->and($operator->can('update', $expense))->toBeFalse()
        ->and($superAdmin->can('Update:Expense'))->toBeTrue()
        ->and($superAdmin->can('update', $expense))->toBeFalse();
});
