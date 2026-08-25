<?php

namespace App\Providers\Filament;

use App\Filament\Pages\Dashboard;
use App\Filament\Pages\Tenancy\RegisterCompany;
use App\Models\Company;
use Filament\Enums\ThemeMode;
use Filament\Http\Middleware\Authenticate;
use Filament\Http\Middleware\AuthenticateSession;
use Filament\Http\Middleware\DisableBladeIconComponents;
use Filament\Http\Middleware\DispatchServingFilamentEvent;
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

class AdminPanelProvider extends PanelProvider
{
    public function panel(Panel $panel): Panel
    {
        return $panel
            ->default()
            ->id('admin')
            ->path('admin')
            ->viteTheme('resources/css/filament/admin/theme.css')
            ->brandName('Master Plan IT')
            ->brandLogo(fn () => view('filament.components.brand-logo'))
            ->darkModeBrandLogo(fn () => view('filament.components.brand-logo'))
            ->brandLogoHeight('2.5rem')
            ->defaultThemeMode(ThemeMode::Dark)
            ->login()
            ->tenant(Company::class)
            ->tenantRegistration(RegisterCompany::class)
            ->tenantMenu(false)
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
                PanelsRenderHook::TOPBAR_START,
                fn () => view('filament.components.exercise-context-hook'),
            )
            ->renderHook(
                PanelsRenderHook::SIDEBAR_START,
                fn () => view('filament.components.sidebar-brand'),
            )
            ->renderHook(
                PanelsRenderHook::SCRIPTS_AFTER,
                fn () => view('filament.components.date-picker-script'),
            )
            ->discoverResources(in: app_path('Filament/Resources'), for: 'App\Filament\Resources')
            ->discoverPages(in: app_path('Filament/Pages'), for: 'App\Filament\Pages')
            ->pages([
                Dashboard::class,
            ])
            ->navigationGroups([
                'Panoramica',
                'Operatività',
                'Anagrafiche',
                'Controllo',
                'Amministrazione',
            ])
            ->sidebarCollapsibleOnDesktop()
            ->discoverWidgets(in: app_path('Filament/Widgets'), for: 'App\Filament\Widgets')
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
