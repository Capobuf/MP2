<?php

use App\Exceptions\LegacyUserCompanyConflict;
use App\Models\User;
use Illuminate\Database\Connection;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

const LEGACY_AUTHORIZATION_CONNECTION = 'legacy_authorization';

beforeEach(function (): void {
    $database = (string) env('INSTALLER_TEST_DATABASE');

    expect(app()->environment())->toBe('testing')
        ->and($database)->toStartWith('testing_')
        ->not->toBe('testing')
        ->not->toBe('mp2');

    $connectionConfig = config('database.connections.mysql');
    $connectionConfig['database'] = $database;

    config(['database.connections.'.LEGACY_AUTHORIZATION_CONNECTION => $connectionConfig]);
    DB::purge(LEGACY_AUTHORIZATION_CONNECTION);
    DB::connection(LEGACY_AUTHORIZATION_CONNECTION)->getPdo();

    wipeLegacyAuthorizationDatabase();
    createLegacyAuthorizationSchema();
});

afterEach(function (): void {
    wipeLegacyAuthorizationDatabase();
    DB::purge(LEGACY_AUTHORIZATION_CONNECTION);
    config(['database.connections.'.LEGACY_AUTHORIZATION_CONNECTION => null]);
});

it('maps one legacy view assignment and promotes the legacy platform administrator', function (): void {
    $companyId = legacyCompany('Azienda singola');
    $tenantUserId = legacyUser('tenant@example.test');
    $platformUserId = legacyUser('platform@example.test', true);

    legacyDatabase()->table('company_capabilities')->insert([
        'company_id' => $companyId,
        'user_id' => $tenantUserId,
        'capability' => 'view',
        'created_at' => now(),
    ]);

    migrateShieldAuthorizationSchema();

    expect(legacyDatabase()->table('users')->where('id', $tenantUserId)->value('company_id'))->toBe($companyId)
        ->and(legacyDatabase()->table('model_has_roles')
            ->join('roles', 'roles.id', '=', 'model_has_roles.role_id')
            ->where('roles.name', 'super_admin')
            ->where('model_has_roles.model_type', User::class)
            ->where('model_has_roles.model_id', $platformUserId)
            ->exists())->toBeTrue();
});

it('stops before converting a legacy user assigned to multiple companies', function (): void {
    $firstCompanyId = legacyCompany('Prima azienda');
    $secondCompanyId = legacyCompany('Seconda azienda');
    $userId = legacyUser('conflict@example.test');

    legacyDatabase()->table('company_capabilities')->insert([
        [
            'company_id' => $firstCompanyId,
            'user_id' => $userId,
            'capability' => 'view',
            'created_at' => now(),
        ],
        [
            'company_id' => $secondCompanyId,
            'user_id' => $userId,
            'capability' => 'view',
            'created_at' => now(),
        ],
    ]);

    expect(fn (): int => Artisan::call('migrate', [
        '--database' => LEGACY_AUTHORIZATION_CONNECTION,
        '--path' => shieldAuthorizationMigrationPaths(),
        '--force' => true,
        '--no-interaction' => true,
    ]))->toThrow(LegacyUserCompanyConflict::class)
        ->and(Schema::connection(LEGACY_AUTHORIZATION_CONNECTION)->hasColumn('users', 'company_id'))->toBeFalse()
        ->and(legacyDatabase()->table('company_capabilities')->where('user_id', $userId)->count())->toBe(2);
});

function legacyDatabase(): Connection
{
    return DB::connection(LEGACY_AUTHORIZATION_CONNECTION);
}

function legacyCompany(string $name): int
{
    return legacyDatabase()->table('companies')->insertGetId([
        'name' => $name,
        'timezone' => 'Europe/Rome',
        'overspend_note_required' => false,
        'unclassified_closing_policy' => 'warning',
        'created_at' => now(),
        'updated_at' => now(),
    ]);
}

function legacyUser(string $email, bool $platformAdmin = false): int
{
    return legacyDatabase()->table('users')->insertGetId([
        'name' => $email,
        'email' => $email,
        'password' => 'not-used',
        'is_platform_admin' => $platformAdmin,
        'created_at' => now(),
        'updated_at' => now(),
    ]);
}

function wipeLegacyAuthorizationDatabase(): void
{
    expect(Artisan::call('db:wipe', [
        '--database' => LEGACY_AUTHORIZATION_CONNECTION,
        '--drop-views' => true,
        '--force' => true,
    ]))->toBe(0);
}

function createLegacyAuthorizationSchema(): void
{
    $schema = Schema::connection(LEGACY_AUTHORIZATION_CONNECTION);

    $schema->create('companies', function (Blueprint $table): void {
        $table->id();
        $table->string('name');
        $table->string('timezone', 64);
        $table->boolean('overspend_note_required')->default(false);
        $table->string('unclassified_closing_policy', 16)->default('warning');
        $table->timestamps();
    });
    $schema->create('users', function (Blueprint $table): void {
        $table->id();
        $table->string('name');
        $table->string('email')->unique();
        $table->string('password');
        $table->boolean('is_platform_admin')->default(false);
        $table->timestamps();
    });
    $schema->create('company_capabilities', function (Blueprint $table): void {
        $table->id();
        $table->foreignId('company_id')->constrained()->restrictOnDelete();
        $table->foreignId('user_id')->constrained()->restrictOnDelete();
        $table->string('capability', 64);
        $table->timestamp('created_at')->useCurrent();
        $table->unique(['company_id', 'user_id', 'capability']);
    });
    $schema->create('audit_events', function (Blueprint $table): void {
        $table->id();
        $table->string('capability', 64)->nullable();
    });

    expect(Artisan::call('migrate:install', [
        '--database' => LEGACY_AUTHORIZATION_CONNECTION,
        '--no-interaction' => true,
    ]))->toBe(0);
}

function migrateShieldAuthorizationSchema(): void
{
    expect(Artisan::call('migrate', [
        '--database' => LEGACY_AUTHORIZATION_CONNECTION,
        '--path' => shieldAuthorizationMigrationPaths(),
        '--force' => true,
        '--no-interaction' => true,
    ]))->toBe(0);
}

/** @return array<int, string> */
function shieldAuthorizationMigrationPaths(): array
{
    return [
        'database/migrations/2026_08_31_102226_create_permission_tables.php',
        'database/migrations/2026_08_31_110000_migrate_users_to_shield.php',
        'database/migrations/2026_08_31_120000_remove_legacy_authorization.php',
    ];
}
