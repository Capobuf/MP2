<?php

use App\Actions\CreateCompany;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('rejects updates and deletes of persisted audit events', function () {
    $administrator = User::factory()->platformAdmin()->create();
    $company = app(CreateCompany::class)->execute($administrator, [
        'name' => 'Azienda',
        'timezone' => 'Europe/Rome',
    ]);
    $event = $company->auditEvents()->firstOrFail();

    expect(fn () => $event->update(['reason' => 'Riscrittura']))
        ->toThrow(LogicException::class, 'Audit events are append-only.')
        ->and(fn () => $event->delete())
        ->toThrow(LogicException::class, 'Audit events are append-only.');

    expect($event->refresh()->reason)->toBeNull()
        ->and($company->auditEvents()->count())->toBe(1);
});
