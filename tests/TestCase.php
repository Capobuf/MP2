<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Spatie\Permission\PermissionRegistrar;
use Tests\Support\TestEnvironmentGuard;
use Tests\Support\TestPermissions;

abstract class TestCase extends BaseTestCase
{
    public function createApplication()
    {
        $application = parent::createApplication();
        $connection = (string) $application['config']->get('database.default');

        TestEnvironmentGuard::assertSafe(
            $application->environment(),
            (string) $application['config']->get("database.connections.{$connection}.database"),
        );

        return $application;
    }

    protected function setUp(): void
    {
        TestEnvironmentGuard::assertSafe(
            $this->environmentValue('APP_ENV'),
            $this->environmentValue('DB_DATABASE'),
        );

        parent::setUp();

        if (Schema::hasTable('permissions')) {
            $now = now();
            DB::table('permissions')->insertOrIgnore(
                collect(TestPermissions::all())
                    ->map(fn (string $name): array => [
                        'name' => $name,
                        'guard_name' => 'web',
                        'created_at' => $now,
                        'updated_at' => $now,
                    ])
                    ->all(),
            );
            app(PermissionRegistrar::class)->forgetCachedPermissions();
        }
    }

    private function environmentValue(string $key): string
    {
        $value = $_SERVER[$key] ?? $_ENV[$key] ?? getenv($key);

        return is_string($value) ? $value : '';
    }
}
