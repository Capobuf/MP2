<?php

it('defines a production-safe environment template', function () {
    $environment = file_get_contents(base_path('.env.production.example'));

    expect($environment)
        ->toContain('APP_NAME="Master Plan IT"')
        ->toContain('APP_ENV=production')
        ->toContain('APP_KEY=')
        ->toContain('APP_DEBUG=false')
        ->toContain('APP_LOCALE=it')
        ->toContain('DB_CONNECTION=mysql')
        ->toContain('SESSION_DRIVER=file')
        ->toContain('CACHE_STORE=file')
        ->toContain('QUEUE_CONNECTION=sync')
        ->toContain('FILESYSTEM_DISK=local')
        ->not->toContain('DEV_ADMIN_')
        ->not->toContain('DB_HOST=mysql')
        ->not->toContain('DB_USERNAME=sail')
        ->not->toContain('DB_PASSWORD=password');
});

it('keeps the installer in runtime dependencies and Tinker in development dependencies', function () {
    $lock = json_decode(file_get_contents(base_path('composer.lock')), true, flags: JSON_THROW_ON_ERROR);
    $runtimePackages = array_column($lock['packages'], 'name');
    $developmentPackages = array_column($lock['packages-dev'], 'name');

    expect($runtimePackages)
        ->toContain('relayercore/laravel-installer')
        ->not->toContain('laravel/tinker')
        ->not->toContain('psy/psysh')
        ->and($developmentPackages)
        ->toContain('laravel/tinker')
        ->toContain('psy/psysh');
});

it('codifies staging validation and smoke testing of the extracted ZIP', function () {
    $workflow = file_get_contents(base_path('.github/workflows/ci.yml'));

    foreach ([
        'public/.htaccess',
        'public/build/manifest.json',
        'public/installer/installer.css',
        'public/vendor/livewire/manifest.json',
        'public/vendor/livewire/livewire.min.js',
        'vendor/autoload.php',
        'storage/installed',
        'storage/framework/installer-progress.json',
        'bootstrap/cache',
        'public/hot',
        'vendor/laravel/tinker',
        'testing_installer_smoke',
        'unzip',
        'REVISION',
    ] as $contractEntry) {
        expect($workflow)->toContain($contractEntry);
    }

    expect($workflow)
        ->toContain('name: CI')
        ->toContain("branches: ['**']")
        ->toContain('hosting-release:')
        ->toContain('needs: quality')
        ->toContain("if: \${{ github.event_name == 'push' && needs.quality.result == 'success' }}")
        ->toContain('ref: ${{ github.sha }}')
        ->not->toContain('workflow_run')
        ->toContain('composer install --no-dev')
        ->toContain('php artisan livewire:publish --assets --no-interaction')
        ->toContain('-u DB_DATABASE')
        ->toContain('if [ "$status" = 200 ]')
        ->toContain("grep -Fq '/vendor/livewire/livewire.min.js'")
        ->toContain("grep -Eq '/livewire-")
        ->toContain('text/html*|application/xhtml+xml*')
        ->toContain('actions/upload-artifact@v7')
        ->toContain('archive: false');

    expect(file_exists(base_path('.github/workflows/quality.yml')))->toBeFalse()
        ->and(file_exists(base_path('.github/workflows/hosting-release.yml')))->toBeFalse();
});

it('contains no upstream branding or external confetti dependency in installer overrides', function () {
    $views = collect([
        resource_path('views/vendor/installer/installer.blade.php'),
        resource_path('views/vendor/installer/layouts/installer.blade.php'),
    ])->map(fn (string $path): string => file_get_contents($path))->implode("\n");

    expect($views)
        ->toContain('wire:key="installer-step-')
        ->not->toContain('BookFlow')
        ->not->toContain('canvas-confetti')
        ->not->toContain('cdn.jsdelivr.net');
});
