<?php

use App\Models\User;
use BezhanSalleh\FilamentShield\Resources\Roles\Pages\CreateRole;
use BezhanSalleh\FilamentShield\Resources\Roles\Pages\EditRole;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

it('lets only a super administrator create and modify global roles through Shield', function () {
    $superAdmin = User::factory()->platformAdmin()->create();
    $this->actingAs($superAdmin);
    Filament::setCurrentPanel('platform');
    Filament::setTenant(null);

    Livewire::test(CreateRole::class)
        ->fillForm(['name' => 'Operatore globale', 'guard_name' => 'web'])
        ->call('create')
        ->assertHasNoFormErrors();

    $role = Role::query()->where('name', 'Operatore globale')->sole();

    Livewire::test(EditRole::class, ['record' => $role->getRouteKey()])
        ->fillForm(['name' => 'Operatore aggiornato', 'guard_name' => 'web'])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($role->refresh()->name)->toBe('Operatore aggiornato');

    $ordinary = User::factory()->create();
    $this->actingAs($ordinary);

    Livewire::test(CreateRole::class)->assertForbidden();
});
