<?php

use App\Domain\Expenses\Decimal;
use App\Domain\Expenses\ExerciseStatus;
use App\Models\BudgetSnapshot;
use App\Models\ClosingSnapshot;
use App\Models\Company;
use App\Models\Exercise;
use App\Models\ProjectDeferral;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\Support\TestPermissions;
use Tests\TestCase;

pest()->extend(TestCase::class)->in('Feature');

function s11ReportingViewer(Company $company): User
{
    $user = User::factory()->create();
    grantTestPermissions([
        'company_id' => $company->id,
        'user' => $user,
        'permissions' => TestPermissions::VIEW,
    ]);

    return $user;
}

/** @param array{company_id: int, user: User, permissions: string|array<int, string>} $assignment */
function grantTestPermissions(array $assignment): void
{
    $user = $assignment['user'];
    $company = Company::query()->findOrFail($assignment['company_id']);
    $permissions = is_array($assignment['permissions'])
        ? $assignment['permissions']
        : [$assignment['permissions']];

    if ($user->hasRole('super_admin')) {
        $role = $user->roles()->where('name', 'super_admin')->sole();
    } else {
        if ($user->company_id !== null && $user->company_id !== $company->id) {
            throw new LogicException('A tenant user cannot receive permissions for another Company.');
        }

        $user->forceFill(['company_id' => $company->id])->save();
        $role = Role::query()->firstOrCreate([
            'name' => 'test_user_'.$user->id,
            'guard_name' => 'web',
        ]);
    }

    $existingNames = Permission::query()
        ->where('guard_name', 'web')
        ->whereIn('name', $permissions)
        ->pluck('name');
    $now = now();

    DB::table('permissions')->insertOrIgnore(
        collect($permissions)
            ->diff($existingNames)
            ->map(fn (string $name): array => [
                'name' => $name,
                'guard_name' => 'web',
                'created_at' => $now,
                'updated_at' => $now,
            ])
            ->all(),
    );
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    $assignedPermissions = $role->permissions()->pluck('name')
        ->merge($permissions)
        ->unique()
        ->values();

    if ($assignedPermissions->isNotEmpty()) {
        $role->syncPermissions($assignedPermissions);
    }

    if (! $user->hasRole($role)) {
        $user->assignRole($role);
    }
}

function revokeTestPermission(User $user, string $permission): void
{
    foreach ($user->roles as $role) {
        $role->revokePermissionTo($permission);
    }
}

function closeExerciseFixture(Exercise $exercise, User $actor): ClosingSnapshot
{
    $exercise->loadMissing('company');
    $budgets = BudgetSnapshot::query()
        ->where('company_id', $exercise->company_id)
        ->where('exercise_id', $exercise->id)
        ->orderBy('version')
        ->get();
    $nextExercise = Exercise::query()
        ->where('company_id', $exercise->company_id)
        ->where('year', $exercise->year + 1)
        ->first();
    $carryover = Decimal::sum(
        ProjectDeferral::query()
            ->where('source_exercise_id', $exercise->id)
            ->where('mode', 'carryover')
            ->where('carryover_state', 'consolidated')
            ->pluck('carryover_amount'),
    );
    $snapshot = ClosingSnapshot::query()->create([
        'company_id' => $exercise->company_id,
        'company_name' => $exercise->company->name,
        'exercise_id' => $exercise->id,
        'exercise_year' => $exercise->year,
        'closed_at' => now(),
        'closed_by_id' => $actor->id,
        'initial_budget_id' => $budgets->first()?->id,
        'current_budget_id' => $budgets->last()?->id,
        'total_final_allocation' => $exercise->allocation(),
        'total_closing_actual' => $exercise->actual(),
        'total_operational_variance' => $exercise->operationalVariance(),
        'total_consolidated_carryover' => $carryover,
        'accepted_warnings' => [],
        'applied_settings' => [
            'timezone' => $exercise->company->timezone,
            'overspend_note_required' => (bool) $exercise->company->overspend_note_required,
            'unclassified_closing_policy' => $exercise->company->closingUnclassifiedPolicy()->value,
        ],
        'next_exercise_disposition' => $nextExercise === null
            ? 'not_created'
            : 'already_existed',
        'next_exercise_id' => $nextExercise?->id,
        'operation_id' => (string) Str::uuid(),
    ]);
    $exercise->update(['status' => ExerciseStatus::Closed]);

    return $snapshot;
}
