<?php

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;

uses(RefreshDatabase::class);

it('provisions an ordinary user through hidden password prompts', function () {
    $this->artisan('mp2:provision-user', [
        'name' => 'Operatore MP2',
        'email' => 'operatore@mp2.local',
    ])
        ->expectsQuestion('Password', 'password-operatore')
        ->expectsQuestion('Conferma password', 'password-operatore')
        ->expectsOutputToContain('operatore@mp2.local')
        ->assertSuccessful();

    $user = User::query()->where('email', 'operatore@mp2.local')->sole();

    expect($user->name)->toBe('Operatore MP2')
        ->and($user->is_platform_admin)->toBeFalse()
        ->and(Hash::check('password-operatore', $user->password))->toBeTrue();
});

it('rejects duplicate email without changing the existing user', function () {
    $existing = User::factory()->create([
        'email' => 'esistente@mp2.local',
        'name' => 'Esistente',
    ]);

    $this->artisan('mp2:provision-user', [
        'name' => 'Duplicato',
        'email' => 'esistente@mp2.local',
    ])
        ->expectsQuestion('Password', 'password-duplicato')
        ->expectsQuestion('Conferma password', 'password-duplicato')
        ->assertFailed();

    expect(User::query()->count())->toBe(1)
        ->and($existing->refresh()->name)->toBe('Esistente');
});

it('rejects mismatched password confirmation', function () {
    $this->artisan('mp2:provision-user', [
        'name' => 'Operatore',
        'email' => 'operatore@mp2.local',
    ])
        ->expectsQuestion('Password', 'password-operatore')
        ->expectsQuestion('Conferma password', 'password-diversa')
        ->assertFailed();

    expect(User::query()->count())->toBe(0);
});
