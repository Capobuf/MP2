<?php

use App\Domain\Company\AuditEventType;
use App\Filament\Pages\CompanyAudit;
use App\Filament\Resources\Users\Pages\EditUser;
use App\Models\AuditEvent;
use App\Models\Company;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\Support\TestPermissions;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->company = Company::factory()->create();
    $this->manager = User::factory()->create(['name' => 'Autore storico']);
    grantTestPermissions(['company_id' => $this->company->id, 'user' => $this->manager, 'permissions' => [...TestPermissions::MANAGE_PERMISSIONS, ...TestPermissions::VIEW]]);
    $this->role = Role::query()->create(['name' => 'Operatore', 'guard_name' => 'web']);
    $this->beneficiary = User::factory()->create(['company_id' => $this->company->id, 'name' => 'Nome precedente', 'email' => 'prima@example.test']);
    $this->beneficiary->assignRole($this->role);
    $this->actingAs($this->manager);
    Filament::setCurrentPanel('admin');
    Filament::setTenant($this->company->tenantCompany);
});

it('audits only changed account fields and never persists credentials', function (array $changes, array $previous, array $new) {
    $oldHash = $this->beneficiary->password;
    $page = Livewire::test(EditUser::class, ['record' => $this->beneficiary->getRouteKey()]);
    expect($page->get('data.password'))->toBeNull();
    $page->fillForm($changes)->call('save')->assertHasNoFormErrors();

    $event = AuditEvent::query()->sole();
    expect($event->event_type)->toBe(AuditEventType::AccountChanged)
        ->and($event->company_id)->toBe($this->company->id)
        ->and($event->actor_id)->toBe($this->manager->id)
        ->and($event->actor_name)->toBe('Autore storico')
        ->and($event->beneficiary_id)->toBe($this->beneficiary->id)
        ->and($event->subject_id)->toBe($this->beneficiary->id)
        ->and($event->previous_value)->toBe($previous)
        ->and($event->new_value)->toBe($new)
        ->and($event->event_sequence)->toBe(0);
    $this->beneficiary->refresh();
    if (isset($changes['password'])) {
        expect(Hash::check($changes['password'], $this->beneficiary->password))->toBeTrue();
    }
    $stored = json_encode(DB::table('audit_events')->get(), JSON_THROW_ON_ERROR);
    expect($stored)->not->toContain($oldHash, $this->beneficiary->password, 'nuova-password-segreta');

    Livewire::test(EditUser::class, ['record' => $this->beneficiary->getRouteKey()])
        ->call('save')->assertHasNoFormErrors();
    expect(AuditEvent::query()->count())->toBe(1);
})->with([
    'name' => [['name' => 'Nome nuovo'], ['name' => 'Nome precedente'], ['name' => 'Nome nuovo']],
    'email' => [['email' => 'dopo@example.test'], ['email' => 'prima@example.test'], ['email' => 'dopo@example.test']],
    'password' => [['password' => 'nuova-password-segreta'], [], ['password_changed' => true]],
    'combined' => [['name' => 'Nome nuovo', 'email' => 'dopo@example.test', 'password' => 'nuova-password-segreta'], ['name' => 'Nome precedente', 'email' => 'prima@example.test'], ['name' => 'Nome nuovo', 'email' => 'dopo@example.test', 'password_changed' => true]],
]);

it('groups account and authorization changes in one operation with distinct sequences', function () {
    $newRole = Role::query()->create(['name' => 'Nuovo ruolo', 'guard_name' => 'web']);
    Livewire::test(EditUser::class, ['record' => $this->beneficiary->getRouteKey()])
        ->fillForm(['name' => 'Nome nuovo', 'roles' => [$newRole->id]])
        ->call('save')->assertHasNoFormErrors();
    $events = AuditEvent::query()->orderBy('event_sequence')->get();
    expect($events)->toHaveCount(2)
        ->and($events->pluck('event_type')->all())->toBe([AuditEventType::AuthorizationChanged, AuditEventType::AccountChanged])
        ->and($events->pluck('operation_id')->unique())->toHaveCount(1)
        ->and($events->pluck('event_sequence')->all())->toBe([0, 1]);
});

it('rolls back identity credentials roles and earlier audit events when account auditing fails', function (bool $changeRoles) {
    $oldHash = $this->beneficiary->password;
    $newRole = Role::query()->create(['name' => 'Nuovo ruolo', 'guard_name' => 'web']);
    $changes = ['name' => 'Nome nuovo', 'email' => 'dopo@example.test', 'password' => 'nuova-password-segreta'];
    if ($changeRoles) {
        $changes['roles'] = [$newRole->id];
    }
    AuditEvent::created(function (AuditEvent $event): void {
        if ($event->event_type === AuditEventType::AccountChanged) {
            throw new RuntimeException('Account audit unavailable');
        }
    });
    try {
        expect(fn () => Livewire::test(EditUser::class, ['record' => $this->beneficiary->getRouteKey()])
            ->fillForm($changes)->call('save'))->toThrow(RuntimeException::class, 'Account audit unavailable');
    } finally {
        Event::forget('eloquent.created: '.AuditEvent::class);
    }
    expect($this->beneficiary->refresh()->name)->toBe('Nome precedente')
        ->and($this->beneficiary->email)->toBe('prima@example.test')
        ->and($this->beneficiary->password)->toBe($oldHash)
        ->and($this->beneficiary->roles->modelKeys())->toBe([$this->role->id])
        ->and(AuditEvent::query()->count())->toBe(0);
})->with([false, true]);

it('preserves historical author labels and presents account changes without secrets', function () {
    Livewire::test(EditUser::class, ['record' => $this->beneficiary->getRouteKey()])
        ->fillForm(['email' => 'dopo@example.test', 'password' => 'nuova-password-segreta'])
        ->call('save')->assertHasNoFormErrors();
    $event = AuditEvent::query()->sole();
    $this->manager->update(['name' => 'Autore rinominato']);
    $page = Livewire::test(CompanyAudit::class);
    $page->set('tableColumns', collect($page->get('tableColumns'))->map(fn (array $column): array => [...$column, 'isToggled' => true])->all());
    $page->assertTableColumnStateSet('actor_name', 'Autore storico (#'.$this->manager->id.')', $event)
        ->assertTableColumnStateSet('new_value', 'Email: dopo@example.test · Password modificata', $event)
        ->mountTableAction('details', $event)
        ->assertSee('Autore storico (#'.$this->manager->id.')')
        ->assertSee('Password modificata')
        ->assertDontSee('nuova-password-segreta')
        ->assertDontSee($this->beneficiary->refresh()->password);
    expect($event->refresh()->actor_name)->toBe('Autore storico');
});

it('uses a stable ID for legacy events without inventing a historical author name', function () {
    $id = DB::table('audit_events')->insertGetId([
        'company_id' => $this->company->id, 'actor_id' => $this->manager->id,
        'operation_id' => (string) Str::uuid(), 'event_type' => 'company_created',
        'subject_type' => Company::class, 'subject_id' => $this->company->id,
        'affected_exercise_ids' => '[]', 'effective_from' => '2026-01-01',
        'allocated_impact_by_exercise' => '[]', 'actual_impact_by_exercise' => '[]',
    ]);
    $this->manager->update(['name' => 'Autore rinominato']);
    $event = AuditEvent::findOrFail($id);
    $label = 'Utente #'.$this->manager->id.' (nome storico non disponibile)';
    expect($event->actor_name)->toBeNull()->and($event->actorLabel())->toBe($label);
    $page = Livewire::test(CompanyAudit::class);
    $page->set('tableColumns', collect($page->get('tableColumns'))->map(fn (array $column): array => [...$column, 'isToggled' => true])->all());
    $page->assertTableColumnStateSet('actor_name', $label, $event)
        ->mountTableAction('details', $event)
        ->assertSchemaComponentExists('detail_actor')
        ->assertSee($label);
});

it('snapshots authors for ordinary domain events and handles an administrator renaming themselves', function () {
    $event = AuditEvent::query()->create([
        'company_id' => $this->company->id, 'actor_id' => $this->manager->id,
        'event_type' => AuditEventType::CompanyCreated,
        'subject_type' => Company::class, 'subject_id' => $this->company->id,
        'affected_exercise_ids' => [], 'effective_from' => '2026-01-01',
        'allocated_impact_by_exercise' => [], 'actual_impact_by_exercise' => [],
    ]);
    Livewire::test(EditUser::class, ['record' => $this->manager->getRouteKey()])
        ->fillForm(['name' => 'Autore rinominato'])
        ->call('save')->assertHasNoFormErrors();
    $rename = AuditEvent::query()->where('event_type', AuditEventType::AccountChanged)->sole();
    expect($event->refresh()->actor_name)->toBe('Autore storico')
        ->and($rename->actor_id)->toBe($this->manager->id)
        ->and($rename->beneficiary_id)->toBe($this->manager->id)
        ->and($rename->actor_name)->toBe('Autore rinominato')
        ->and($rename->previous_value)->toBe(['name' => 'Autore storico'])
        ->and($rename->new_value)->toBe(['name' => 'Autore rinominato']);
});
