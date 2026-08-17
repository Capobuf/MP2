<?php

use Tests\Support\TestEnvironmentGuard;

it('accepts only the dedicated testing environment and database', function () {
    expect(fn () => TestEnvironmentGuard::assertSafe('testing', 'testing'))
        ->not->toThrow(RuntimeException::class);
});

it('rejects a development database configuration before reset helpers can run', function () {
    expect(fn () => TestEnvironmentGuard::assertSafe('local', 'mp2'))
        ->toThrow(RuntimeException::class, 'Test database safety check failed');
});
