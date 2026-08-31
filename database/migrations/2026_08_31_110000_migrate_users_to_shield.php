<?php

use App\Exceptions\LegacyUserCompanyConflict;
use App\Models\User;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $conflictingUserIds = DB::table('company_capabilities')
            ->join('users', 'users.id', '=', 'company_capabilities.user_id')
            ->where('users.is_platform_admin', false)
            ->where('company_capabilities.capability', 'view')
            ->groupBy('company_capabilities.user_id')
            ->havingRaw('COUNT(DISTINCT company_capabilities.company_id) > 1')
            ->pluck('company_capabilities.user_id')
            ->all();

        if ($conflictingUserIds !== []) {
            throw new LegacyUserCompanyConflict(
                'Legacy users associated with multiple Companies must be resolved before migration: '
                .implode(', ', $conflictingUserIds),
            );
        }

        Schema::table('users', function (Blueprint $table): void {
            $table->foreignId('company_id')
                ->nullable()
                ->after('id')
                ->constrained('companies')
                ->restrictOnDelete();
        });

        $singleCompanyUsers = DB::table('company_capabilities')
            ->join('users', 'users.id', '=', 'company_capabilities.user_id')
            ->where('users.is_platform_admin', false)
            ->where('company_capabilities.capability', 'view')
            ->groupBy('company_capabilities.user_id')
            ->selectRaw('company_capabilities.user_id, MIN(company_capabilities.company_id) AS company_id')
            ->get();

        foreach ($singleCompanyUsers as $legacyUser) {
            DB::table('users')
                ->where('id', $legacyUser->user_id)
                ->update(['company_id' => $legacyUser->company_id]);
        }

        $now = now();
        DB::table('roles')->insertOrIgnore([
            'name' => 'super_admin',
            'guard_name' => 'web',
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        $roleId = (int) DB::table('roles')
            ->where('name', 'super_admin')
            ->where('guard_name', 'web')
            ->value('id');

        $permissionAssignments = DB::table('permissions')->pluck('id')->map(fn (int $permissionId): array => [
            'permission_id' => $permissionId,
            'role_id' => $roleId,
        ])->all();

        if ($permissionAssignments !== []) {
            DB::table('role_has_permissions')->insert($permissionAssignments);
        }

        $superAdminAssignments = DB::table('users')
            ->where('is_platform_admin', true)
            ->pluck('id')
            ->map(fn (int $userId): array => [
                'role_id' => $roleId,
                'model_type' => User::class,
                'model_id' => $userId,
            ])
            ->all();

        if ($superAdminAssignments !== []) {
            DB::table('model_has_roles')->insert($superAdminAssignments);
        }
    }

    public function down(): void
    {
        $roleId = DB::table('roles')
            ->where('name', 'super_admin')
            ->where('guard_name', 'web')
            ->value('id');

        if ($roleId !== null) {
            DB::table('model_has_roles')->where('role_id', $roleId)->delete();
            DB::table('role_has_permissions')->where('role_id', $roleId)->delete();
            DB::table('roles')->where('id', $roleId)->delete();
        }

        Schema::table('users', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('company_id');
        });
    }
};
