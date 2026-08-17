<?php

it('keeps the normal bootstrap path non destructive', function () {
    $script = file_get_contents(base_path('scripts/bootstrap-dev.sh'));

    expect($script)
        ->not->toBeFalse()
        ->not->toContain('migrate:fresh')
        ->not->toContain('db:wipe')
        ->not->toContain('down -v')
        ->not->toContain('docker volume rm')
        ->not->toContain('TRUNCATE ')
        ->not->toContain('npm install')
        ->not->toContain('npm run');
});
