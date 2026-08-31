<?php

namespace App\Actions\Authorization;

use Illuminate\Support\Collection;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

class SyncDefaultRoles
{
    public const VIEWER = 'Visualizzatore';

    public const EDITOR = 'Editor';

    public const ADMINISTRATOR = 'Amministratore';

    /** @var array<int, string> */
    private const VIEW_PREFIXES = ['View', 'ViewAny'];

    /** @var array<int, string> */
    private const EDIT_PREFIXES = ['View', 'ViewAny', 'Create', 'Update'];

    public function __invoke(): void
    {
        $permissions = Permission::query()->get();

        $this->role(self::VIEWER)->syncPermissions(
            $this->withPrefixes($permissions, self::VIEW_PREFIXES),
        );
        $this->role(self::EDITOR)->syncPermissions(
            $this->withPrefixes($permissions, self::EDIT_PREFIXES),
        );
        $this->role(self::ADMINISTRATOR)->syncPermissions($permissions);
    }

    private function role(string $name): Role
    {
        return Role::query()->firstOrCreate([
            'name' => $name,
            'guard_name' => 'web',
        ]);
    }

    /**
     * @param  Collection<int, Permission>  $permissions
     * @param  array<int, string>  $prefixes
     * @return Collection<int, Permission>
     */
    private function withPrefixes(Collection $permissions, array $prefixes): Collection
    {
        return $permissions
            ->filter(fn (Permission $permission): bool => in_array(
                str($permission->name)->before(':')->toString(),
                $prefixes,
                true,
            ))
            ->values();
    }
}
