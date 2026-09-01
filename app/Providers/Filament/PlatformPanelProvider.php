<?php

namespace App\Providers\Filament;

use BezhanSalleh\FilamentShield\FilamentShieldPlugin;
use Filament\Auth\MultiFactor\App\AppAuthentication;
use Filament\Enums\ThemeMode;
use Filament\Http\Middleware\Authenticate;
use Filament\Http\Middleware\AuthenticateSession;
use Filament\Http\Middleware\DisableBladeIconComponents;
use Filament\Http\Middleware\DispatchServingFilamentEvent;
use Filament\Navigation\NavigationGroup;
use Filament\Panel;
use Filament\PanelProvider;
use Filament\Support\Colors\Color;
use Filament\Support\Enums\Width;
use Filament\View\PanelsRenderHook;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\View\Middleware\ShareErrorsFromSession;

class PlatformPanelProvider extends PanelProvider
{
    public function panel(Panel $panel): Panel
    {
        return $panel
            ->id('platform')
            ->path('platform')
            ->viteTheme('resources/css/filament/admin/theme.css')
            ->brandName('Master Plan IT · Piattaforma')
            ->brandLogo(fn () => view('filament.components.brand-logo'))
            ->darkModeBrandLogo(fn () => view('filament.components.brand-logo'))
            ->brandLogoHeight('2.5rem')
            ->defaultThemeMode(ThemeMode::Dark)
            ->login()
            ->profile(isSimple: false)
            ->multiFactorAuthentication([
                AppAuthentication::make()
                    ->recoverable(),
            ])
            ->plugin(
                FilamentShieldPlugin::make()
                    ->navigationGroup('Impostazioni')
                    ->navigationLabel('Ruoli')
                    ->navigationSort(20)
                    ->modelLabel('Ruolo')
                    ->pluralModelLabel('Ruoli')
                    ->scopeToTenant(false),
            )
            ->colors([
                'gray' => Color::hex('#91A3A8'),
                'primary' => Color::hex('#39D5C4'),
                'success' => Color::hex('#22C55E'),
                'warning' => Color::hex('#F59E0B'),
                'danger' => Color::hex('#EF4444'),
                'info' => Color::hex('#60A5FA'),
            ])
            ->maxContentWidth(Width::Full)
            ->sidebarWidth('14rem')
            ->collapsedSidebarWidth('3rem')
            ->renderHook(
                PanelsRenderHook::SIDEBAR_START,
                fn () => view('filament.components.sidebar-brand'),
            )
            ->renderHook(
                PanelsRenderHook::SIDEBAR_FOOTER,
                fn () => view('filament.components.sidebar-collapse-control'),
            )
            ->discoverResources(
                in: app_path('Filament/Platform/Resources'),
                for: 'App\Filament\Platform\Resources',
            )
            ->discoverPages(
                in: app_path('Filament/Platform/Pages'),
                for: 'App\Filament\Platform\Pages',
            )
            ->navigationGroups([
                NavigationGroup::make('Impostazioni')
                    ->collapsed(),
            ])
            ->sidebarCollapsibleOnDesktop()
            ->middleware([
                EncryptCookies::class,
                AddQueuedCookiesToResponse::class,
                StartSession::class,
                AuthenticateSession::class,
                ShareErrorsFromSession::class,
                PreventRequestForgery::class,
                SubstituteBindings::class,
                DisableBladeIconComponents::class,
                DispatchServingFilamentEvent::class,
            ])
            ->authMiddleware([
                Authenticate::class,
            ]);
    }
}
