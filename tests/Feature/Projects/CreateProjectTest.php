<?php

use App\Actions\Operations\CreateProject;
use App\Actions\Operations\UpdateProject;
use App\Domain\Company\AuditEventType;
use App\Domain\Company\Capability;
use App\Domain\Projects\ProjectState;
use App\Models\AuditEvent;
use App\Models\Company;
use App\Models\CompanyCapability;
use App\Models\CostCenter;
use App\Models\Exercise;
use App\Models\Expense;
use App\Models\Project;
use App\Models\ProjectExerciseClassification;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

uses(RefreshDatabase::class);

function grantProjectOperations(User $user, Company $company): void
{
    foreach ([Capability::View, Capability::ManageOperations] as $capability) {
        CompanyCapability::query()->create([
            'company_id' => $company->id,
            'user_id' => $user->id,
            'capability' => $capability,
        ]);
    }
}

it('creates a stable Project and initial classification atomically and idempotently', function () {
    $actor = User::factory()->create();
    $company = Company::factory()->create(['timezone' => 'Europe/Rome']);
    grantProjectOperations($actor, $company);
    $exercise = Exercise::factory()->for($company)->create(['year' => 2027]);
    $costCenter = CostCenter::factory()->for($company)->create();
    $operationId = (string) Str::uuid();
    $input = [
        'title' => '  Rinnovo laboratorio  ',
        'description' => '  Nuovi strumenti  ',
        'notes' => '  Priorità alta  ',
        'initial_state' => 'planned',
        'initial_effective_date' => '2027-01-01',
        'exercise_id' => $exercise->id,
        'cost_center_id' => $costCenter->id,
    ];

    $project = app(CreateProject::class)->execute($actor, $company, $input, $operationId);
    $retry = app(CreateProject::class)->execute($actor, $company, $input, $operationId);
    $classification = ProjectExerciseClassification::query()->sole();
    $event = AuditEvent::query()->sole();

    expect($retry->is($project))->toBeTrue()
        ->and($project->originKey())->toBe('project:'.$project->id)
        ->and($project->title)->toBe('Rinnovo laboratorio')
        ->and($project->description)->toBe('Nuovi strumenti')
        ->and($project->notes)->toBe('Priorità alta')
        ->and($project->initialState())->toBe(ProjectState::Planned)
        ->and($classification->project_id)->toBe($project->id)
        ->and($classification->exercise_id)->toBe($exercise->id)
        ->and($classification->cost_center_id)->toBe($costCenter->id)
        ->and(Expense::query()->count())->toBe(0)
        ->and($event->eventType())->toBe(AuditEventType::ProjectCreated)
        ->and($event->subject_type)->toBe(Project::class)
        ->and($event->subject_id)->toBe($project->id)
        ->and($event->affected_exercise_ids)->toBe([$exercise->id])
        ->and($event->effective_from->toDateString())->toBe('2027-01-01')
        ->and($event->allocated_impact_by_exercise)->toBe([(string) $exercise->id => '0.00'])
        ->and($event->actual_impact_by_exercise)->toBe([(string) $exercise->id => '0.00']);
});

it('rejects unavailable initial references and invalid Project input without partial state', function () {
    $actor = User::factory()->create();
    $companyA = Company::factory()->create();
    $companyB = Company::factory()->create();
    grantProjectOperations($actor, $companyA);
    $closedExercise = Exercise::factory()->for($companyA)->create(['status' => 'closed']);
    $exerciseB = Exercise::factory()->for($companyB)->create();
    $archivedCostCenter = CostCenter::factory()->for($companyA)->archived()->create();
    $costCenterB = CostCenter::factory()->for($companyB)->create();
    $base = [
        'title' => 'Progetto',
        'initial_state' => 'open',
        'initial_effective_date' => '2026-08-17',
    ];

    foreach ([
        [...$base, 'title' => ' ', 'exercise_id' => $closedExercise->id],
        [...$base, 'initial_state' => 'suspended', 'exercise_id' => $closedExercise->id],
        [...$base, 'exercise_id' => $closedExercise->id],
        [...$base, 'exercise_id' => $exerciseB->id],
        [...$base, 'exercise_id' => Exercise::factory()->for($companyA)->create()->id, 'cost_center_id' => $archivedCostCenter->id],
        [...$base, 'exercise_id' => Exercise::factory()->for($companyA)->create()->id, 'cost_center_id' => $costCenterB->id],
    ] as $input) {
        expect(fn () => app(CreateProject::class)->execute($actor, $companyA, $input, (string) Str::uuid()))
            ->toThrow(ValidationException::class);
    }

    expect(Project::query()->count())->toBe(0)
        ->and(ProjectExerciseClassification::query()->count())->toBe(0)
        ->and(AuditEvent::query()->count())->toBe(0);
});

it('rejects unauthorized creation and rolls back when audit persistence fails', function () {
    $actor = User::factory()->create();
    $company = Company::factory()->create();
    $exercise = Exercise::factory()->for($company)->create();
    $input = [
        'title' => 'Progetto',
        'initial_state' => 'planned',
        'initial_effective_date' => '2026-01-01',
        'exercise_id' => $exercise->id,
    ];

    expect(fn () => app(CreateProject::class)->execute($actor, $company, $input, (string) Str::uuid()))
        ->toThrow(AuthorizationException::class);

    grantProjectOperations($actor, $company);
    AuditEvent::creating(function (): never {
        throw new RuntimeException('audit unavailable');
    });

    expect(fn () => app(CreateProject::class)->execute($actor, $company, $input, (string) Str::uuid()))
        ->toThrow(RuntimeException::class, 'audit unavailable')
        ->and(Project::query()->count())->toBe(0)
        ->and(ProjectExerciseClassification::query()->count())->toBe(0);

    AuditEvent::flushEventListeners();
});

it('updates only descriptive Project fields with revision idempotency and audit', function () {
    $actor = User::factory()->create();
    $company = Company::factory()->create();
    grantProjectOperations($actor, $company);
    $project = Project::factory()->for($company)->create([
        'title' => 'Prima',
        'initial_state' => ProjectState::Open,
        'initial_effective_date' => '2026-01-01',
    ]);
    $operationId = (string) Str::uuid();

    $updated = app(UpdateProject::class)->execute($actor, $project, [
        'title' => ' Dopo ',
        'description' => ' Descrizione ',
        'notes' => ' Note ',
        'initial_state' => 'cancelled',
        'archived_at' => now(),
    ], $operationId);
    app(UpdateProject::class)->execute($actor, $project, [
        'title' => 'Ignorato dal retry',
        'description' => null,
        'notes' => null,
    ], $operationId);
    $event = AuditEvent::query()->sole();

    expect($updated->title)->toBe('Dopo')
        ->and($updated->description)->toBe('Descrizione')
        ->and($updated->notes)->toBe('Note')
        ->and($updated->initialState())->toBe(ProjectState::Open)
        ->and($updated->archived_at)->toBeNull()
        ->and($updated->revision)->toBe(1)
        ->and($event->eventType())->toBe(AuditEventType::ProjectUpdated)
        ->and($event->previous_value['title'])->toBe('Prima')
        ->and($event->new_value['title'])->toBe('Dopo');
});
