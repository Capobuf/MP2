<?php

use App\Installer\Steps\ConfigureScheduler;

it('builds the scheduler strings from the real artisan path', function () {
    $step = new ConfigureScheduler;
    $state = ['php_cli' => '/usr/bin/php83'];

    expect($step->artisanPath())->toBe(realpath(base_path('artisan')))
        ->and($step->commandOnly($state))->toBe("/usr/bin/php83 '".$step->artisanPath()."' schedule:run >> /dev/null 2>&1")
        ->and($step->crontab($state))->toBe('* * * * * '.$step->commandOnly($state));
});

it('allows a provider-specific PHP CLI command and requires scheduler confirmation', function () {
    $step = new ConfigureScheduler;

    expect($step->defaultPhpCommand())->toBe('php'.PHP_MAJOR_VERSION.'.'.PHP_MINOR_VERSION)
        ->and(fn () => $step->validate(['php_cli' => 'php8.4']))
        ->toThrow(RuntimeException::class)
        ->and($step->validate([
            'php_cli' => 'php8.4',
            'scheduler_confirmed' => true,
        ]))->toBeTrue();
});

it('rejects multiline PHP commands', function () {
    expect(fn () => (new ConfigureScheduler)->validate([
        'php_cli' => "php8.3\nmalicious-command",
        'scheduler_confirmed' => true,
    ]))->toThrow(RuntimeException::class);
});
