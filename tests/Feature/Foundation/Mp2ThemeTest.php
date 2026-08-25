<?php

use Filament\Enums\ThemeMode;

it('centralizes the dark-first MP2 palette without the legacy brand blue', function () {
    $theme = file_get_contents(resource_path('css/filament/admin/theme.css'));
    $provider = file_get_contents(app_path('Providers/Filament/AdminPanelProvider.php'));
    $brandLogo = file_get_contents(resource_path('views/filament/components/brand-logo.blade.php'));

    expect($theme)
        ->toContain(
            '--mp2-canvas-abyss:',
            '--mp2-surface-primary:',
            '--mp2-surface-secondary:',
            '--mp2-graphite:',
            '--mp2-brand:',
            '--mp2-focus:',
            '--mp2-semantic-info:',
            '.fi-modal-window',
            '.fi-dropdown-panel',
            '.fi-pagination',
            '.fi-link:focus-visible',
            '.fi-fo-table-repeater > table',
            '.fi-simple-header .mp2-brand-logo',
            '.fi-simple-main',
            'background: var(--surface) !important;',
            '.fi-simple-main .fi-input-wrp',
            '.fi-sidebar:not(.fi-sidebar-open) .fi-sidebar-item-btn > .fi-icon',
            '.mp2-context-control .mp2-context-copy',
            '.mp2-context-control .mp2-context-label',
            '.mp2-context-control .mp2-context-value',
            '.mp2-impact-preview-changes',
        )
        ->not->toContain('#0057f5', '#004bd6', '#0757d6', '#4f8cff', '#73a4ff');

    expect($theme)->toMatch('/\.mp2-object-header-main\s*\{[^}]*z-index:\s*3;/s');

    expect($provider)
        ->toContain("->brandName('Master Plan IT')")
        ->toContain("Color::hex('#39D5C4')")
        ->toContain('->defaultThemeMode(ThemeMode::Dark)');

    expect($brandLogo)
        ->toContain(
            'aria-label="Master Plan IT"',
            "Vite::asset('resources/images/branding/masterplanit-logo-black.svg')",
            "Vite::asset('resources/images/branding/masterplanit-logo-white.svg')",
        );

    expect(resource_path('images/branding/masterplanit-logo-black.svg'))->toBeFile()
        ->and(resource_path('images/branding/masterplanit-logo-white.svg'))->toBeFile();

    expect(ThemeMode::Dark->value)->toBe('dark');
});
