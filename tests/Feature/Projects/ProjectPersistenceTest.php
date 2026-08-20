<?php

use App\Domain\Projects\ProjectState;
use App\Models\Company;
use App\Models\CostCenter;
use App\Models\Exercise;
use App\Models\Expense;
use App\Models\Project;
use App\Models\ProjectExerciseClassification;
use App\Models\ProjectTransition;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;

uses(RefreshDatabase::class);

it('creates the complete forward S4 Project schema', function () {
    expect(Schema::hasColumns('projects', [
        'id', 'company_id', 'title', 'description', 'notes', 'initial_state',
        'initial_effective_date', 'archived_at', 'revision',
    ]))->toBeTrue()
        ->and(Schema::hasColumns('project_transitions', [
            'id', 'company_id', 'project_id', 'from_state', 'to_state',
            'effective_date', 'active_effective_date', 'reason', 'created_by_id',
            'annulled_at', 'annulled_by_id', 'annulment_reason',
        ]))->toBeTrue()
        ->and(Schema::hasColumns('project_exercise_classifications', [
            'id', 'company_id', 'project_id', 'exercise_id', 'cost_center_id',
        ]))->toBeTrue()
        ->and(Schema::hasColumn('expenses', 'project_id'))->toBeTrue();
});

it('persists tenant-safe Project facts and one supported Expense owner', function () {
    $company = Company::factory()->create();
    $actor = User::factory()->create();
    $exercise = Exercise::factory()->for($company)->create();
    $costCenter = CostCenter::factory()->for($company)->create();
    $project = Project::factory()->for($company)->create([
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
        ->create(['cost_center_id' => $costCenter->id]);
    $expense = Expense::factory()->forExercise($exercise)->create([
        'project_id' => $project->id,
        'direct_cost_center_id' => null,
    ]);

    expect($project->originKey())->toBe('project:'.$project->id)
        ->and($project->transitions->modelKeys())->toBe([$transition->id])
        ->and($project->classifications->modelKeys())->toBe([$classification->id])
        ->and($project->expenses->modelKeys())->toBe([$expense->id])
        ->and($expense->project->is($project))->toBeTrue();

    expect(fn () => Expense::factory()->forExercise($exercise)->create([
        'project_id' => $project->id,
        'direct_cost_center_id' => $costCenter->id,
    ]))->toThrow(QueryException::class);
});

it('enforces Project company boundaries transition rules and active date uniqueness', function () {
    $companyA = Company::factory()->create();
    $companyB = Company::factory()->create();
    $actor = User::factory()->create();
    $projectA = Project::factory()->for($companyA)->create();
    $exerciseA = Exercise::factory()->for($companyA)->create();
    $exerciseB = Exercise::factory()->for($companyB)->create();
    $costCenterB = CostCenter::factory()->for($companyB)->create();

    expect(fn () => ProjectTransition::query()->create([
        'company_id' => $companyB->id,
        'project_id' => $projectA->id,
        'from_state' => ProjectState::Planned,
        'to_state' => ProjectState::Open,
        'effective_date' => '2027-01-01',
        'created_by_id' => $actor->id,
    ]))->toThrow(QueryException::class)
        ->and(fn () => ProjectExerciseClassification::query()->create([
            'company_id' => $companyA->id,
            'project_id' => $projectA->id,
            'exercise_id' => $exerciseB->id,
            'cost_center_id' => null,
        ]))->toThrow(QueryException::class)
        ->and(fn () => ProjectExerciseClassification::query()->create([
            'company_id' => $companyA->id,
            'project_id' => $projectA->id,
            'exercise_id' => $exerciseA->id,
            'cost_center_id' => $costCenterB->id,
        ]))->toThrow(QueryException::class);

    $first = ProjectTransition::factory()->forProject($projectA)->create([
        'from_state' => ProjectState::Planned,
        'to_state' => ProjectState::Open,
        'effective_date' => '2027-02-01',
        'created_by_id' => $actor->id,
    ]);

    expect(fn () => ProjectTransition::factory()->forProject($projectA)->create([
        'from_state' => ProjectState::Planned,
        'to_state' => ProjectState::Cancelled,
        'effective_date' => '2027-02-01',
        'reason' => 'Annullamento',
        'created_by_id' => $actor->id,
    ]))->toThrow(QueryException::class)
        ->and(fn () => ProjectTransition::factory()->forProject($projectA)->create([
            'from_state' => ProjectState::Planned,
            'to_state' => ProjectState::Closed,
            'effective_date' => '2027-03-01',
            'reason' => 'Non ammessa',
            'created_by_id' => $actor->id,
        ]))->toThrow(QueryException::class)
        ->and(fn () => ProjectTransition::factory()->forProject($projectA)->create([
            'from_state' => ProjectState::Open,
            'to_state' => ProjectState::Closed,
            'effective_date' => '2027-03-01',
            'reason' => null,
            'created_by_id' => $actor->id,
        ]))->toThrow(QueryException::class);

    $first->update([
        'annulled_at' => now(),
        'annulled_by_id' => $actor->id,
        'annulment_reason' => 'Sostituita',
    ]);

    expect(ProjectTransition::factory()->forProject($projectA)->create([
        'from_state' => ProjectState::Planned,
        'to_state' => ProjectState::Open,
        'effective_date' => '2027-02-01',
        'created_by_id' => $actor->id,
    ]))->toBeInstanceOf(ProjectTransition::class);
});

it('rejects ordinary physical deletion of every S4 identity', function () {
    $company = Company::factory()->create();
    $actor = User::factory()->create();
    $exercise = Exercise::factory()->for($company)->create();
    $project = Project::factory()->for($company)->create();
    $transition = ProjectTransition::factory()->forProject($project)->create([
        'created_by_id' => $actor->id,
    ]);
    $classification = ProjectExerciseClassification::factory()
        ->forProjectAndExercise($project, $exercise)
        ->create();

    expect(fn () => $transition->delete())->toThrow(LogicException::class)
        ->and(fn () => $classification->delete())->toThrow(LogicException::class)
        ->and(fn () => $project->delete())->toThrow(LogicException::class);
});
