<?php

use App\Actions\Operations\CreateExpenseLine;
use App\Actions\Operations\SetProjectArchived;
use App\Domain\Company\AuditEventType;
use App\Domain\Projects\ProjectState;
use App\Models\AuditEvent;
use App\Models\Company;
use App\Models\Exercise;
use App\Models\Expense;
use App\Models\ExpenseLine;
use App\Models\Project;
use App\Models\ProjectExerciseClassification;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Tests\Support\TestPermissions;

uses(RefreshDatabase::class);

beforeEach(function () {
    CarbonImmutable::setTestNow('2026-08-18 10:00:00 Europe/Rome');
});

afterEach(function () {
    CarbonImmutable::setTestNow();
});

function archiveProjectContext(ProjectState $state): array
{
    $actor = User::factory()->create();
    $company = Company::factory()->create(['timezone' => 'Europe/Rome']);
    foreach ([TestPermissions::VIEW, TestPermissions::MANAGE_OPERATIONS] as $capability) {
        grantTestPermissions([
            'company_id' => $company->id,
            'user' => $actor,
            'permissions' => $capability,
        ]);
    }
    $exercise = Exercise::factory()->for($company)->create(['year' => 2026]);
    $project = Project::factory()->for($company)->create([
        'initial_state' => $state,
        'initial_effective_date' => '2026-01-01',
    ]);
    $classification = ProjectExerciseClassification::factory()
        ->forProjectAndExercise($project, $exercise)
        ->create();
    $expense = Expense::factory()->forExercise($exercise)->for($project)->create(['direct_cost_center_id' => null]);
    ExpenseLine::factory()->for($expense)->create(['type' => 'estimate', 'amount' => '40.00']);

    return [$actor, $company, $exercise, $project, $classification, $expense];
}

it('archives and restores terminal Projects without changing their economics or history', function (ProjectState $state) {
    [$actor, , $exercise, $project, $classification, $expense] = archiveProjectContext($state);
    $archiveOperation = (string) Str::uuid();

    $archived = app(SetProjectArchived::class)->execute($actor, $project, true, $archiveOperation);
    $retried = app(SetProjectArchived::class)->execute($actor, $project, true, $archiveOperation);
    app(SetProjectArchived::class)->execute($actor, $project, true, (string) Str::uuid());

    expect($archived->id)->toBe($project->id)
        ->and($retried->id)->toBe($project->id)
        ->and($archived->isArchived())->toBeTrue()
        ->and($archived->revision)->toBe(1)
        ->and($archived->annualTotals()[$exercise->id]['allocation'])->toBe('40.00')
        ->and($classification->refresh()->project_id)->toBe($project->id)
        ->and($expense->refresh()->project_id)->toBe($project->id)
        ->and(AuditEvent::query()->count())->toBe(1);

    $restored = app(SetProjectArchived::class)->execute($actor, $project, false, (string) Str::uuid());

    expect($restored->isArchived())->toBeFalse()
        ->and($restored->revision)->toBe(2)
        ->and($restored->annualTotals()[$exercise->id]['allocation'])->toBe('40.00')
        ->and(AuditEvent::query()->pluck('event_type')->all())->toBe([
            AuditEventType::ProjectArchived,
            AuditEventType::ProjectRestored,
        ]);
})->with([ProjectState::Closed, ProjectState::Cancelled]);

it('rejects archive outside terminal state and rejects new activity while archived', function () {
    [$actor, , , $open] = archiveProjectContext(ProjectState::Open);
    expect(fn () => app(SetProjectArchived::class)->execute($actor, $open, true, (string) Str::uuid()))
        ->toThrow(ValidationException::class);

    [$closedActor, , , $closed, , $expense] = archiveProjectContext(ProjectState::Closed);
    app(SetProjectArchived::class)->execute($closedActor, $closed, true, (string) Str::uuid());
    expect(fn () => app(CreateExpenseLine::class)->execute($closedActor, $expense, [
        'type' => 'actual',
        'amount' => '1.00',
        'actual_kind' => 'late',
        'activity_note' => 'Registrazione tardiva',
    ], (string) Str::uuid()))->toThrow(ValidationException::class);
});

it('rejects a stale lifecycle revision without changing archive visibility', function () {
    [$actor, , , $project] = archiveProjectContext(ProjectState::Closed);

    expect(fn () => app(SetProjectArchived::class)->execute(
        $actor,
        $project,
        true,
        (string) Str::uuid(),
        $project->revision + 1,
    ))->toThrow(ValidationException::class);

    expect($project->refresh()->isArchived())->toBeFalse()
        ->and($project->revision)->toBe(0)
        ->and(AuditEvent::query()->count())->toBe(0);
});

it('rolls Project archive and revision back when audit persistence fails', function () {
    [$actor, , , $project] = archiveProjectContext(ProjectState::Closed);
    AuditEvent::creating(fn () => throw new RuntimeException('Forced audit failure'));

    expect(fn () => app(SetProjectArchived::class)->execute($actor, $project, true, (string) Str::uuid()))
        ->toThrow(RuntimeException::class);

    expect($project->refresh()->isArchived())->toBeFalse()
        ->and($project->revision)->toBe(0)
        ->and(AuditEvent::query()->count())->toBe(0);
    AuditEvent::flushEventListeners();
});
