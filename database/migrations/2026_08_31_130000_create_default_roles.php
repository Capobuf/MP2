<?php

use App\Actions\Authorization\SyncDefaultRoles;
use Illuminate\Database\Migrations\Migration;
use Spatie\Permission\Models\Role;

return new class extends Migration
{
    public function up(): void
    {
        app(SyncDefaultRoles::class)();
    }

    public function down(): void
    {
        Role::query()
            ->whereIn('name', [
                SyncDefaultRoles::VIEWER,
                SyncDefaultRoles::EDITOR,
                SyncDefaultRoles::ADMINISTRATOR,
            ])
            ->delete();
    }
};
