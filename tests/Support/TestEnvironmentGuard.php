<?php

namespace Tests\Support;

use RuntimeException;

final class TestEnvironmentGuard
{
    public static function assertSafe(string $environment, string $database): void
    {
        if ($environment !== 'testing' || $database !== 'testing') {
            throw new RuntimeException(sprintf(
                'Test database safety check failed: APP_ENV must be testing and DB_DATABASE must be testing; received %s/%s.',
                $environment,
                $database,
            ));
        }
    }
}
