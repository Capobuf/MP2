<?php

use App\Actions\Authorization\SyncDefaultRoles;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

it('synchronizes the three default roles with their exact permission levels', function (): void {
    $permissions = [
        'ViewAny:Expense',
        'View:Expense',
        'Create:Expense',
        'Update:Expense',
        'Delete:Expense',
        'Approve:Proposal',
        'Close:Exercise',
    ];

    foreach ($permissions as $permission) {
        Permission::query()->firstOrCreate([
            'name' => $permission,
            'guard_name' => 'web',
        ]);
    }

    app(SyncDefaultRoles::class)();

    $allPermissions = Permission::query()->pluck('name')->sort()->values();
    $viewerPermissions = $allPermissions
        ->filter(fn (string $permission): bool => str_starts_with($permission, 'View:')
            || str_starts_with($permission, 'ViewAny:'))
        ->values()
        ->all();
    $editorPermissions = $allPermissions
        ->filter(fn (string $permission): bool => collect(['View:', 'ViewAny:', 'Create:', 'Update:'])
            ->contains(fn (string $prefix): bool => str_starts_with($permission, $prefix)))
        ->values()
        ->all();

    expect(rolePermissions(SyncDefaultRoles::VIEWER))->toBe($viewerPermissions)
        ->and(rolePermissions(SyncDefaultRoles::EDITOR))->toBe($editorPermissions)
        ->and(rolePermissions(SyncDefaultRoles::EDITOR))->not->toContain(
            'Delete:Expense',
            'Approve:Proposal',
            'Close:Exercise',
        )
        ->and(rolePermissions(SyncDefaultRoles::ADMINISTRATOR))->toBe($allPermissions->all());
});

it('is idempotent and restores the default permission sets', function (): void {
    $view = Permission::query()->firstOrCreate(['name' => 'View:Expense', 'guard_name' => 'web']);
    $delete = Permission::query()->firstOrCreate(['name' => 'Delete:Expense', 'guard_name' => 'web']);

    app(SyncDefaultRoles::class)();

    Role::findByName(SyncDefaultRoles::VIEWER)->givePermissionTo($delete);
    app(SyncDefaultRoles::class)();

    expect(rolePermissions(SyncDefaultRoles::VIEWER))->toContain($view->name)
        ->not->toContain($delete->name)
        ->and(Role::query()->whereIn('name', [
            SyncDefaultRoles::VIEWER,
            SyncDefaultRoles::EDITOR,
            SyncDefaultRoles::ADMINISTRATOR,
        ])->count())->toBe(3);
});

/** @return array<int, string> */
function rolePermissions(string $role): array
{
    return Role::findByName($role)
        ->permissions
        ->pluck('name')
        ->sort()
        ->values()
        ->all();
}
