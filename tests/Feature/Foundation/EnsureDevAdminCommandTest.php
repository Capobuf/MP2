<?php

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;

uses(RefreshDatabase::class);

beforeEach(function () {
    config()->set('mp2.dev_admin', [
        'name' => 'Administrator',
        'email' => 'admin@mp2.local',
        'password' => 'stable-development-password',
    ]);
});

it('creates the configured development administrator', function () {
    $this->artisan('mp2:ensure-dev-admin')->assertSuccessful();

    $administrator = User::query()->where('email', 'admin@mp2.local')->sole();

    expect($administrator->name)->toBe('Administrator')
        ->and(Hash::check('stable-development-password', $administrator->password))->toBeTrue();
});

it('is idempotent and keeps one user for the configured email', function () {
    $this->artisan('mp2:ensure-dev-admin')->assertSuccessful();
    $this->artisan('mp2:ensure-dev-admin')->assertSuccessful();

    expect(User::query()->where('email', 'admin@mp2.local')->count())->toBe(1)
        ->and(Hash::check(
            'stable-development-password',
            User::query()->where('email', 'admin@mp2.local')->sole()->password,
        ))->toBeTrue();
});

it('refuses to run in production', function () {
    $originalEnvironment = app()->environment();
    app()->detectEnvironment(fn (): string => 'production');

    try {
        $this->artisan('mp2:ensure-dev-admin')->assertFailed();
        expect(User::query()->count())->toBe(0);
    } finally {
        app()->detectEnvironment(fn (): string => $originalEnvironment);
    }
});
