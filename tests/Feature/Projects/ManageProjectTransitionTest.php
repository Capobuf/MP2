<?php

use App\Actions\Operations\AnnulProjectTransition;
use App\Actions\Operations\CreateProjectTransition;
use App\Actions\Operations\ReplaceProjectTransition;
use App\Domain\Company\AuditEventType;
use App\Domain\Projects\ProjectState;
use App\Models\AuditEvent;
use App\Models\Company;
use App\Models\Exercise;
use App\Models\Project;
use App\Models\ProjectTransition;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Tests\Support\TestPermissions;

uses(RefreshDatabase::class);

function grantProjectTransitionOperations(User $user, Company $company): void
{
    foreach ([TestPermissions::VIEW, TestPermissions::MANAGE_OPERATIONS] as $capability) {
        grantTestPermissions([
            'company_id' => $company->id,
            'user' => $user,
            'permissions' => $capability,
        ]);
    }
}

beforeEach(function () {
    CarbonImmutable::setTestNow('2026-08-17 10:00:00 Europe/Rome');
});

afterEach(function () {
    CarbonImmutable::setTestNow();
});

it('records allowed transitions idempotently and derives exact state at every date', function () {
    $actor = User::factory()->create();
    $company = Company::factory()->create(['timezone' => 'Europe/Rome']);
    grantProjectTransitionOperations($actor, $company);
    $exercise = Exercise::factory()->for($company)->create(['year' => 2026]);
    $project = Project::factory()->for($company)->create([
        'initial_state' => ProjectState::Planned,
        'initial_effective_date' => '2026-01-01',
    ]);
    $openOperation = (string) Str::uuid();

    $opening = app(CreateProjectTransition::class)->execute($actor, $project, [
        'from_state' => 'planned',
        'to_state' => 'open',
        'effective_date' => '2026-04-01',
    ], $openOperation);
    $retry = app(CreateProjectTransition::class)->execute($actor, $project, [
        'from_state' => 'planned',
        'to_state' => 'open',
        'effective_date' => '2026-04-01',
    ], $openOperation);
    $closeOperation = (string) Str::uuid();
    $closing = app(CreateProjectTransition::class)->execute($actor, $project, [
        'from_state' => 'open',
        'to_state' => 'closed',
        'effective_date' => '2026-09-01',
        'reason' => 'Attività completata',
    ], $closeOperation);

    expect($retry->is($opening))->toBeTrue()
        ->and($project->refresh()->stateAtDate('2026-03-31'))->toBe(ProjectState::Planned)
        ->and($project->stateAtDate('2026-04-01'))->toBe(ProjectState::Open)
        ->and($project->stateAtDate('2026-09-01'))->toBe(ProjectState::Closed)
        ->and($project->revision)->toBe(2)
        ->and($exercise->refresh()->revision)->toBe(1)
        ->and(ProjectTransition::query()->count())->toBe(2)
        ->and(AuditEvent::query()->count())->toBe(2)
        ->and(AuditEvent::query()->where('operation_id', $openOperation)->firstOrFail()->eventType())->toBe(AuditEventType::ProjectTransitionEffective)
        ->and(AuditEvent::query()->where('operation_id', $closeOperation)->firstOrFail()->eventType())->toBe(AuditEventType::ProjectTransitionPlanned)
        ->and($closing->reason)->toBe('Attività completata');
});

it('rejects duplicate incompatible unreasoned and pre-initial transitions atomically', function () {
    $actor = User::factory()->create();
    $company = Company::factory()->create();
    grantProjectTransitionOperations($actor, $company);
    $project = Project::factory()->for($company)->create([
        'initial_state' => ProjectState::Planned,
        'initial_effective_date' => '2026-01-01',
    ]);
    app(CreateProjectTransition::class)->execute($actor, $project, [
        'from_state' => 'planned',
        'to_state' => 'open',
        'effective_date' => '2026-10-01',
    ], (string) Str::uuid());

    foreach ([
        ['from_state' => 'planned', 'to_state' => 'cancelled', 'effective_date' => '2026-10-01', 'reason' => 'Cambio'],
        ['from_state' => 'planned', 'to_state' => 'cancelled', 'effective_date' => '2026-11-01', 'reason' => 'Origine errata'],
        ['from_state' => 'open', 'to_state' => 'closed', 'effective_date' => '2026-11-01'],
        ['from_state' => 'planned', 'to_state' => 'open', 'effective_date' => '2025-12-31'],
    ] as $input) {
        expect(fn () => app(CreateProjectTransition::class)->execute($actor, $project, $input, (string) Str::uuid()))
            ->toThrow(ValidationException::class);
    }

    expect(ProjectTransition::query()->count())->toBe(1)
        ->and(AuditEvent::query()->count())->toBe(1)
        ->and($project->refresh()->revision)->toBe(1);
});

it('annuls only a future transition and preserves its identity and history', function () {
    $actor = User::factory()->create();
    $company = Company::factory()->create(['timezone' => 'Europe/Rome']);
    grantProjectTransitionOperations($actor, $company);
    $project = Project::factory()->for($company)->create();
    $future = app(CreateProjectTransition::class)->execute($actor, $project, [
        'from_state' => 'planned',
        'to_state' => 'open',
        'effective_date' => '2026-10-01',
    ], (string) Str::uuid());
    $operationId = (string) Str::uuid();

    $annulled = app(AnnulProjectTransition::class)->execute($actor, $future, 'Decisione rinviata', $operationId);
    $retry = app(AnnulProjectTransition::class)->execute($actor, $future, 'Decisione rinviata', $operationId);

    expect($retry->is($annulled))->toBeTrue()
        ->and($annulled->annulled_at)->not->toBeNull()
        ->and($annulled->annulment_reason)->toBe('Decisione rinviata')
        ->and($project->refresh()->stateAtDate('2026-12-31'))->toBe(ProjectState::Planned)
        ->and(ProjectTransition::query()->count())->toBe(1)
        ->and(AuditEvent::query()->count())->toBe(2)
        ->and(AuditEvent::query()->latest('id')->firstOrFail()->eventType())->toBe(AuditEventType::ProjectTransitionAnnulled);

    $effective = ProjectTransition::factory()->forProject($project)->create([
        'from_state' => ProjectState::Planned,
        'to_state' => ProjectState::Open,
        'effective_date' => '2026-08-01',
        'created_by_id' => $actor->id,
    ]);
    expect(fn () => app(AnnulProjectTransition::class)->execute($actor, $effective, 'Non ammesso', (string) Str::uuid()))
        ->toThrow(ValidationException::class);
});

it('replaces one future transition atomically and rolls back both rows on audit failure', function () {
    $actor = User::factory()->create();
    $company = Company::factory()->create();
    grantProjectTransitionOperations($actor, $company);
    $project = Project::factory()->for($company)->create();
    $original = app(CreateProjectTransition::class)->execute($actor, $project, [
        'from_state' => 'planned',
        'to_state' => 'open',
        'effective_date' => '2026-10-01',
    ], (string) Str::uuid());

    $replacement = app(ReplaceProjectTransition::class)->execute($actor, $original, [
        'from_state' => 'planned',
        'to_state' => 'cancelled',
        'effective_date' => '2026-11-01',
        'reason' => 'Progetto non più necessario',
        'replacement_reason' => 'Decisione aggiornata',
    ], (string) Str::uuid());

    expect($original->refresh()->annulled_at)->not->toBeNull()
        ->and($replacement->id)->not->toBe($original->id)
        ->and($replacement->to_state)->toBe(ProjectState::Cancelled)
        ->and($project->refresh()->stateAtDate('2026-12-31'))->toBe(ProjectState::Cancelled)
        ->and(AuditEvent::query()->latest('id')->firstOrFail()->eventType())->toBe(AuditEventType::ProjectTransitionReplaced)
        ->and(AuditEvent::query()->latest('id')->firstOrFail()->previous_value['id'])->toBe($original->id)
        ->and(AuditEvent::query()->latest('id')->firstOrFail()->new_value['id'])->toBe($replacement->id);

    $second = app(CreateProjectTransition::class)->execute($actor, $project, [
        'from_state' => 'cancelled',
        'to_state' => 'planned',
        'effective_date' => '2027-01-01',
    ], (string) Str::uuid());
    AuditEvent::creating(function (): never {
        throw new RuntimeException('audit unavailable');
    });

    expect(fn () => app(ReplaceProjectTransition::class)->execute($actor, $second, [
        'from_state' => 'cancelled',
        'to_state' => 'open',
        'effective_date' => '2027-02-01',
        'reason' => 'Riapertura',
        'replacement_reason' => 'Nuova data',
    ], (string) Str::uuid()))->toThrow(RuntimeException::class, 'audit unavailable');

    expect($second->refresh()->annulled_at)->toBeNull()
        ->and(ProjectTransition::query()->where('effective_date', '2027-02-01')->exists())->toBeFalse();

    AuditEvent::flushEventListeners();
});
