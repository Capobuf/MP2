<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Tests\Support\TestEnvironmentGuard;

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
    }

    private function environmentValue(string $key): string
    {
        $value = $_SERVER[$key] ?? $_ENV[$key] ?? getenv($key);

        return is_string($value) ? $value : '';
    }
}
