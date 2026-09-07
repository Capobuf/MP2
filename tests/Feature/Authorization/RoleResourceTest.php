<?php

use App\Domain\Company\AuditEventType;
use App\Filament\Platform\Resources\Roles\Pages\CreateRole;
use App\Filament\Platform\Resources\Roles\Pages\EditRole;
use App\Filament\Resources\Expenses\ExpenseResource;
use App\Models\AuditEvent;
use App\Models\Company;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\Models\Permission;
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

it('audits the effective authorization changes caused by a global role', function () {
    $superAdmin = User::factory()->platformAdmin()->create();
    $firstCompany = Company::factory()->create();
    $secondCompany = Company::factory()->create();
    $firstUser = User::factory()->create(['company_id' => $firstCompany->id]);
    $secondUser = User::factory()->create(['company_id' => $secondCompany->id]);
    $role = Role::query()->create(['name' => 'Ruolo condiviso', 'guard_name' => 'web']);
    $view = Permission::query()->firstOrCreate(['name' => 'View:Expense', 'guard_name' => 'web']);
    $update = Permission::query()->firstOrCreate(['name' => 'Update:Expense', 'guard_name' => 'web']);
    $role->givePermissionTo($view);
    $firstUser->assignRole($role);
    $secondUser->assignRole($role);

    $this->actingAs($superAdmin);
    Filament::setCurrentPanel('platform');
    Filament::setTenant(null);

    Livewire::test(EditRole::class, ['record' => $role->getRouteKey()])
        ->fillForm([
            ExpenseResource::class => [$view->name, $update->name],
        ])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($role->refresh()->permissions()->pluck('name')->sort()->values()->all())
        ->toBe([$update->name, $view->name]);

    $events = AuditEvent::query()->orderBy('event_sequence')->get();
    expect($events)->toHaveCount(2)
        ->and($events->pluck('event_type')->unique()->all())->toBe([AuditEventType::AuthorizationChanged])
        ->and($events->pluck('actor_id')->unique()->all())->toBe([$superAdmin->id])
        ->and($events->pluck('beneficiary_id')->sort()->values()->all())->toBe([$firstUser->id, $secondUser->id])
        ->and($events->pluck('company_id')->sort()->values()->all())->toBe([$firstCompany->id, $secondCompany->id]);

    foreach ($events as $event) {
        expect($event->previous_value['permissions'])->toBe([$view->name])
            ->and($event->new_value['assigned_permissions'])->toBe([$update->name]);
    }

    Livewire::test(EditRole::class, ['record' => $role->getRouteKey()])
        ->call('save')
        ->assertHasNoFormErrors();

    expect(AuditEvent::query()->count())->toBe(2);
});
