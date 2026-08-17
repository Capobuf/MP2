<?php

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('redirects unauthenticated visitors to the Filament login', function () {
    $this->get('/admin')->assertRedirect('/admin/login');
});

it('allows an authenticated user to open the Filament dashboard', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->get('/admin')
        ->assertOk()
        ->assertSee('Dashboard')
        ->assertSee('Vai al contenuto');
});
