<?php

use App\Actions\Tenancy\ArchiveTenantCompany;
use App\Actions\Tenancy\DeletePendingTenantFiles;
use App\Actions\Tenancy\DestroyTenantCompany;
use App\Actions\Tenancy\RestoreTenantCompany;
use App\Domain\Company\TenantCompanyStatus;
use App\Filament\Platform\Pages\TenantLifecycleLog;
use App\Models\AuditEvent;
use App\Models\Company;
use App\Models\PendingFileDeletion;
use App\Models\PlatformLifecycleEvent;
use App\Models\User;
use Carbon\CarbonImmutable;
use Filament\Facades\Filament;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    Filament::setCurrentPanel('platform');
    Filament::setTenant(null);
});

it('retains attributable UTC lifecycle records after destruction without keeping Tenant business data', function () {
    $this->travelTo(CarbonImmutable::parse('2026-09-17 12:34:56', 'Europe/Rome'));
    $actor = User::factory()->platformAdmin()->create(['name' => 'Platform Operator']);
    $company = Company::factory()->create(['name' => 'Confidential Tenant']);
    $tenant = $company->tenantCompany;
    $domainAuditCount = AuditEvent::query()->where('company_id', $company->id)->count();

    app(ArchiveTenantCompany::class)->execute($actor, $tenant);
    app(RestoreTenantCompany::class)->execute($actor, $tenant);
    expect(AuditEvent::query()->where('company_id', $company->id)->count())->toBe($domainAuditCount);
    $result = app(DestroyTenantCompany::class)->execute($actor, $tenant, true, true);
    $events = PlatformLifecycleEvent::query()->orderBy('id')->get();

    expect($events->pluck('operation')->all())->toBe(['archive', 'restore', 'destroy'])
        ->and($events->pluck('outcome')->all())->toBe(['completed', 'completed', 'data_deleted'])
        ->and($events->last()->operation_id)->toBe($result->operationId)
        ->and($result->isComplete())->toBeTrue()
        ->and(Company::query()->whereKey($company->id)->exists())->toBeFalse()
        ->and(PendingFileDeletion::query()->count())->toBe(0)
        ->and($events->toJson())->not->toContain('Confidential Tenant', $actor->email, $actor->password);
    foreach ($events as $event) {
        expect($event->actor_id)->toBe($actor->id)
            ->and($event->actor_name)->toBe('Platform Operator')
            ->and($event->tenant_id)->toBe($company->id)
            ->and($event->occurred_at->toIso8601String())->toBe('2026-09-17T10:34:56+00:00')
            ->and(Str::isUuid($event->operation_id))->toBeTrue()
            ->and(array_keys($event->getAttributes()))->toBe([
                'id', 'operation_id', 'operation', 'actor_id', 'actor_name', 'tenant_id', 'occurred_at', 'outcome', 'file_count',
            ]);
    }
    $actor->update(['name' => 'Renamed Operator']);
    expect($events->first()->fresh()->actor_name)->toBe('Platform Operator');
});

it('rolls the lifecycle operation and journal back together when recording fails', function (string $operation) {
    Storage::fake('platform-audit-rollback');
    Storage::disk('platform-audit-rollback')->put('logo.png', 'original');
    $actor = User::factory()->platformAdmin()->create();
    $company = Company::factory()->create(['logo_disk' => 'platform-audit-rollback', 'logo_path' => 'logo.png', 'logo_media_type' => 'image/png']);
    $tenant = $company->tenantCompany;
    if ($operation === 'restore') {
        $tenant->update(['status' => TenantCompanyStatus::Archived]);
    }
    $status = $tenant->status;
    PlatformLifecycleEvent::created(function (): never {
        throw new RuntimeException('Platform audit unavailable');
    });
    try {
        expect(fn () => match ($operation) {
            'archive' => app(ArchiveTenantCompany::class)->execute($actor, $tenant),
            'restore' => app(RestoreTenantCompany::class)->execute($actor, $tenant),
            'destroy' => app(DestroyTenantCompany::class)->execute($actor, $tenant, true, true),
        })->toThrow(RuntimeException::class, 'Platform audit unavailable');
    } finally {
        Event::forget('eloquent.created: '.PlatformLifecycleEvent::class);
    }

    expect($company->fresh())->not->toBeNull()
        ->and($tenant->fresh()->status)->toBe($status)
        ->and(PlatformLifecycleEvent::query()->count())->toBe(0)
        ->and(PendingFileDeletion::query()->count())->toBe(0);
    Storage::disk('platform-audit-rollback')->assertExists('logo.png');
})->with(['archive', 'restore', 'destroy']);

it('keeps the committed destruction trace while file cleanup is retried separately', function () {
    $actor = User::factory()->platformAdmin()->create();
    $company = Company::factory()->create(['logo_disk' => 'platform-audit-cleanup', 'logo_path' => 'private-logo.png', 'logo_media_type' => 'image/png']);
    $filesystem = Mockery::mock(Filesystem::class);
    $filesystem->shouldReceive('exists')->once()->andReturn(true);
    $filesystem->shouldReceive('delete')->once()->andReturn(false);
    Storage::set('platform-audit-cleanup', $filesystem);

    $result = app(DestroyTenantCompany::class)->execute($actor, $company->tenantCompany, true, true);
    $event = PlatformLifecycleEvent::query()->sole();
    $before = $event->getAttributes();
    expect($result->isComplete())->toBeFalse()
        ->and($event->outcome)->toBe('data_deleted')
        ->and($event->file_count)->toBe(1)
        ->and($event->operation_id)->toBe(PendingFileDeletion::query()->sole()->operation_id)
        ->and($event->toJson())->not->toContain('private-logo.png', 'platform-audit-cleanup');

    Storage::fake('platform-audit-cleanup');
    app(DeletePendingTenantFiles::class)->execute($result->operationId);
    expect(PendingFileDeletion::query()->count())->toBe(0)
        ->and($event->fresh()->getAttributes())->toBe($before);
});

it('exposes the read-only Platform journal only to Super Admins', function () {
    $actor = User::factory()->platformAdmin()->create();
    $ordinary = User::factory()->create();
    $company = Company::factory()->create();
    app(ArchiveTenantCompany::class)->execute($actor, $company->tenantCompany);
    $event = PlatformLifecycleEvent::query()->sole();
    $this->actingAs($actor);
    $this->get(TenantLifecycleLog::getUrl(panel: 'platform'))->assertOk();
    Livewire::test(TenantLifecycleLog::class)
        ->assertCanSeeTableRecords([$event])
        ->assertSee('Data e Ora UTC')
        ->assertTableActionDoesNotExist('edit')
        ->assertTableActionDoesNotExist('delete');
    expect(fn () => $event->update(['actor_name' => 'changed']))->toThrow(LogicException::class)
        ->and(fn () => $event->delete())->toThrow(LogicException::class);

    $this->actingAs($ordinary);
    $this->get(TenantLifecycleLog::getUrl(panel: 'platform'))->assertForbidden();
    Livewire::test(TenantLifecycleLog::class)->assertForbidden();
});

it('does not record a successful operation when Platform authorization is denied', function (string $operation) {
    $ordinary = User::factory()->create();
    $tenant = Company::factory()->create()->tenantCompany;
    expect(fn () => match ($operation) {
        'archive' => app(ArchiveTenantCompany::class)->execute($ordinary, $tenant),
        'restore' => app(RestoreTenantCompany::class)->execute($ordinary, $tenant),
        'destroy' => app(DestroyTenantCompany::class)->execute($ordinary, $tenant, true, true),
    })->toThrow(AuthorizationException::class);
    expect(PlatformLifecycleEvent::query()->count())->toBe(0);
})->with(['archive', 'restore', 'destroy']);
