<?php

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;

uses(RefreshDatabase::class);

it('preserves one administrator with the configured credentials across reruns', function () {
    config()->set('mp2.dev_admin', [
        'name' => 'Administrator',
        'email' => 'admin@mp2.local',
        'password' => 'admin@mp2.local',
    ]);

    $this->artisan('mp2:ensure-dev-admin')->assertSuccessful();
    $firstId = User::query()->where('email', 'admin@mp2.local')->sole()->getKey();

    $this->artisan('mp2:ensure-dev-admin')->assertSuccessful();
    $administrator = User::query()->where('email', 'admin@mp2.local')->sole();

    expect($administrator->getKey())->toBe($firstId)
        ->and(User::query()->where('email', 'admin@mp2.local')->count())->toBe(1)
        ->and(Hash::check('admin@mp2.local', $administrator->password))->toBeTrue();
});
