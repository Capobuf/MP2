<?php

use App\Models\Company;
use App\Models\User;
use Filament\Auth\MultiFactor\App\AppAuthentication;
use Filament\Auth\MultiFactor\App\Contracts\HasAppAuthentication;
use Filament\Auth\MultiFactor\App\Contracts\HasAppAuthenticationRecovery;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;

uses(RefreshDatabase::class);

it('adds the native app authentication columns to users', function (): void {
    expect(Schema::hasColumns('users', [
        'app_authentication_secret',
        'app_authentication_recovery_codes',
    ]))->toBeTrue();
});

it('supports native app authentication and recovery on the user model', function (): void {
    $user = User::factory()->create();

    expect($user)
        ->toBeInstanceOf(HasAppAuthentication::class)
        ->toBeInstanceOf(HasAppAuthenticationRecovery::class);
});

it('configures optional recoverable app authentication on both panels', function (string $panelId): void {
    $panel = Filament::getPanel($panelId);
    $providers = $panel->getMultiFactorAuthenticationProviders();

    expect($panel->isMultiFactorAuthenticationRequired())->toBeFalse()
        ->and($providers)->toHaveKey('app')
        ->and($providers['app'])->toBeInstanceOf(AppAuthentication::class)
        ->and($providers['app']->isRecoverable())->toBeTrue();
})->with(['admin', 'platform']);

it('registers the admin profile only inside the tenant navigation', function (): void {
    $panel = Filament::getPanel('admin');

    expect($panel->hasProfile())->toBeFalse()
        ->and(Route::has('filament.admin.auth.profile'))->toBeFalse()
        ->and(Route::has('filament.admin.profile'))->toBeTrue();
});

it('does not expose a tenantless admin profile fallback', function (): void {
    $user = User::factory()->platformAdmin()->create();

    $this->actingAs($user)
        ->get('/admin/profile')
        ->assertNotFound();
});

it('exposes the profile, navigation, home link and MFA inside an accessible tenant', function (): void {
    $company = Company::factory()->create();
    $user = s11ReportingViewer($company);
    $profileUrl = route('filament.admin.profile', ['tenant' => $company->tenantCompany]);

    $this->actingAs($user)
        ->get($profileUrl)
        ->assertOk()
        ->assertSee('Vai alla Pagina Principale')
        ->assertSee('Autenticazione a due fattori (2FA)');
});

it('exposes the native profile and MFA management UI in the platform panel', function (): void {
    $user = User::factory()->platformAdmin()->create();
    $panel = Filament::getPanel('platform');

    expect($panel->hasProfile())->toBeTrue()
        ->and($panel->isProfilePageSimple())->toBeFalse()
        ->and(Route::has('filament.platform.auth.profile'))->toBeTrue();

    $this->actingAs($user)
        ->get('/platform/profile')
        ->assertOk()
        ->assertSee('Autenticazione a due fattori (2FA)');
});

it('does not require MFA setup from a user who has not enabled it', function (string $panelId): void {
    $user = User::factory()->platformAdmin()->create();
    $panel = Filament::getPanel($panelId);
    $provider = $panel->getMultiFactorAuthenticationProviders()['app'];

    expect($user->getAppAuthenticationSecret())->toBeNull()
        ->and($provider->isEnabled($user))->toBeFalse()
        ->and($panel->isMultiFactorAuthenticationRequired())->toBeFalse()
        ->and($user->canAccessPanel($panel))->toBeTrue();
})->with(['admin', 'platform']);

it('shares the user MFA state between both panels', function (): void {
    $user = User::factory()->platformAdmin()->create();
    $user->saveAppAuthenticationSecret('shared-totp-secret');

    expect(Filament::getPanel('admin')->getMultiFactorAuthenticationProviders()['app']->isEnabled($user))->toBeTrue()
        ->and(Filament::getPanel('platform')->getMultiFactorAuthenticationProviders()['app']->isEnabled($user))->toBeTrue();
});
