<?php

use App\Domain\Company\AuditEventType;
use App\Domain\Projects\ProjectAuditSnapshot;
use App\Domain\Projects\ProjectOverspendResult;
use App\Domain\Projects\ProjectState;
use App\Models\Company;
use App\Models\Exercise;
use App\Models\Project;
use App\Models\ProjectExerciseClassification;
use App\Models\ProjectTransition;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

it('materializes stable Project facts and exact overspend consequences', function () {
    $company = Company::factory()->create();
    $actor = User::factory()->create();
    $exercise = Exercise::factory()->for($company)->create(['year' => 2026]);
    $project = Project::factory()->for($company)->create([
        'title' => 'Rinnovo laboratorio',
        'initial_state' => ProjectState::Planned,
        'initial_effective_date' => '2026-01-01',
    ]);
    $transition = ProjectTransition::factory()->forProject($project)->create([
        'from_state' => ProjectState::Planned,
        'to_state' => ProjectState::Open,
        'effective_date' => '2026-02-01',
        'created_by_id' => $actor->id,
    ]);
    $classification = ProjectExerciseClassification::factory()
        ->forProjectAndExercise($project, $exercise)
        ->create();

    expect(ProjectAuditSnapshot::project($project))->toMatchArray([
        'id' => $project->id,
        'origin_key' => 'project:'.$project->id,
        'title' => 'Rinnovo laboratorio',
        'initial_state' => 'planned',
        'initial_effective_date' => '2026-01-01',
        'revision' => 0,
    ])->and(ProjectAuditSnapshot::transition($transition))->toMatchArray([
        'id' => $transition->id,
        'from_state' => 'planned',
        'to_state' => 'open',
        'effective_date' => '2026-02-01',
    ])->and(ProjectAuditSnapshot::classification($classification))->toMatchArray([
        'project_id' => $project->id,
        'exercise_id' => $exercise->id,
        'cost_center_id' => null,
    ])->and(ProjectAuditSnapshot::overspend('0.00', '10.00'))->toBe([
        'result' => ProjectOverspendResult::Created->value,
        'variance_before' => '0.00',
        'variance_after' => '10.00',
    ]);
});

it('defines the bounded S4 audit vocabulary with Italian labels', function () {
    expect(AuditEventType::ProjectCreated->label())->toBe('Progetto Creato')
        ->and(AuditEventType::ProjectTransitionPlanned->label())->toBe('Transizione Progetto Pianificata')
        ->and(AuditEventType::ProjectOverspendCreated->label())->toBe('Sovraspesa Progetto Creata')
        ->and(AuditEventType::ProjectRestored->label())->toBe('Progetto Ripristinato');
});
