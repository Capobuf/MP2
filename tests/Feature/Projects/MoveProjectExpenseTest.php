<?php

use App\Actions\Operations\UpdateExpense;
use App\Domain\Company\Capability;
use App\Domain\Projects\ProjectState;
use App\Models\AuditEvent;
use App\Models\Company;
use App\Models\CompanyCapability;
use App\Models\CostCenter;
use App\Models\Exercise;
use App\Models\Expense;
use App\Models\ExpenseLine;
use App\Models\Project;
use App\Models\ProjectExerciseClassification;
use App\Models\ProjectTransition;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

uses(RefreshDatabase::class);

beforeEach(function () {
    CarbonImmutable::setTestNow('2026-08-18 10:00:00 Europe/Rome');
});

afterEach(function () {
    CarbonImmutable::setTestNow();
});

function projectMoveContext(ProjectState $state = ProjectState::Open): array
{
    $actor = User::factory()->create();
    $company = Company::factory()->create(['timezone' => 'Europe/Rome']);
    foreach ([Capability::View, Capability::ManageOperations] as $capability) {
        CompanyCapability::query()->create(['company_id' => $company->id, 'user_id' => $actor->id, 'capability' => $capability]);
    }
    $exercise = Exercise::factory()->for($company)->create(['year' => 2026]);
    $project = Project::factory()->for($company)->create(['initial_state' => $state, 'initial_effective_date' => '2026-01-01']);
    ProjectExerciseClassification::factory()->forProjectAndExercise($project, $exercise)->create();

    return [$actor, $company, $exercise, $project];
}

it('moves one stable Expense autonomous to Project and clears direct classification idempotently', function () {
    [$actor, $company, $exercise, $project] = projectMoveContext(ProjectState::Planned);
    $direct = CostCenter::factory()->for($company)->create();
    $expense = Expense::factory()->forExercise($exercise)->create(['direct_cost_center_id' => $direct->id]);
    $line = ExpenseLine::factory()->for($expense)->create(['amount' => '100.00']);
    $action = app(UpdateExpense::class);
    $plan = $action->preview($actor, $expense, ['project_id' => $project->id]);
    $operationId = (string) Str::uuid();

    $moved = $action->confirm($actor, $expense, $plan, $operationId);
    $retry = $action->confirm($actor, $expense, $plan, $operationId);

    expect($retry->is($moved))->toBeTrue()
        ->and($moved->id)->toBe($expense->id)
        ->and($line->refresh()->expense_id)->toBe($expense->id)
        ->and($moved->project_id)->toBe($project->id)
        ->and($moved->direct_cost_center_id)->toBeNull()
        ->and($project->refresh()->revision)->toBe(1)
        ->and($exercise->refresh()->revision)->toBe(1)
        ->and($exercise->allocation())->toBe('100.00')
        ->and(AuditEvent::query()->count())->toBe(1);
});

it('requires an explicit autonomous classification when leaving a Project', function () {
    [$actor, , $exercise, $project] = projectMoveContext();
    $expense = Expense::factory()->forExercise($exercise)->for($project)->create(['direct_cost_center_id' => null]);
    ExpenseLine::factory()->for($expense)->create(['amount' => '20.00']);
    $action = app(UpdateExpense::class);

    expect(fn () => $action->preview($actor, $expense, ['project_id' => null]))->toThrow(ValidationException::class);

    $plan = $action->preview($actor, $expense, ['project_id' => null, 'direct_cost_center_id' => null]);
    $action->confirm($actor, $expense, $plan, (string) Str::uuid());

    expect($expense->refresh()->project_id)->toBeNull()
        ->and($expense->direct_cost_center_id)->toBeNull()
        ->and($project->refresh()->annualTotals())->not->toHaveKey($exercise->id)
        ->and($exercise->allocation())->toBe('20.00');
});

it('moves an Expense with Actuals between open Projects with exact Project deltas and a reason', function () {
    [$actor, $company, $exercise, $source] = projectMoveContext();
    $target = Project::factory()->for($company)->create(['initial_state' => ProjectState::Open]);
    ProjectExerciseClassification::factory()->forProjectAndExercise($target, $exercise)->create();
    $expense = Expense::factory()->forExercise($exercise)->for($source)->create(['direct_cost_center_id' => null]);
    ExpenseLine::factory()->for($expense)->create(['amount' => '50.00']);
    ExpenseLine::factory()->for($expense)->actual()->create(['amount' => '40.00']);
    $action = app(UpdateExpense::class);

    expect(fn () => $action->preview($actor, $expense, ['project_id' => $target->id]))->toThrow(ValidationException::class);
    $plan = $action->preview($actor, $expense, ['project_id' => $target->id, 'reason' => 'Attribuzione corretta']);
    $action->confirm($actor, $expense, $plan, (string) Str::uuid());

    expect($source->refresh()->annualTotals())->not->toHaveKey($exercise->id)
        ->and($target->refresh()->annualTotals()[$exercise->id]['allocation'])->toBe('50.00')
        ->and($target->annualTotals()[$exercise->id]['actual'])->toBe('40.00')
        ->and($exercise->allocation())->toBe('50.00')
        ->and($source->revision)->toBe(1)
        ->and($target->revision)->toBe(1);
});

it('supports atomic opening and declared late attribution while rejecting invalid targets', function () {
    [$actor, $company, $exercise, $planned] = projectMoveContext(ProjectState::Planned);
    $closed = Project::factory()->for($company)->create(['initial_state' => ProjectState::Closed]);
    $archived = Project::factory()->for($company)->archived()->create(['initial_state' => ProjectState::Open]);
    foreach ([$closed, $archived] as $project) {
        ProjectExerciseClassification::factory()->forProjectAndExercise($project, $exercise)->create();
    }
    $expense = Expense::factory()->forExercise($exercise)->create();
    ExpenseLine::factory()->for($expense)->actual()->create(['amount' => '5.00']);
    $action = app(UpdateExpense::class);

    expect(fn () => $action->preview($actor, $expense, ['project_id' => $planned->id, 'reason' => 'Ingresso']))->toThrow(ValidationException::class);
    $opening = $action->preview($actor, $expense, ['project_id' => $planned->id, 'reason' => 'Ingresso', 'open_project' => true]);
    $action->confirm($actor, $expense, $opening, (string) Str::uuid());
    expect($planned->refresh()->stateAtDate('2026-08-18'))->toBe(ProjectState::Open)
        ->and(ProjectTransition::query()->count())->toBe(1);

    $lateExpense = Expense::factory()->forExercise($exercise)->create();
    ExpenseLine::factory()->for($lateExpense)->actual()->create(['amount' => '2.00']);
    expect(fn () => $action->preview($actor, $lateExpense, ['project_id' => $closed->id, 'reason' => 'Tardiva']))->toThrow(ValidationException::class);
    $late = $action->preview($actor, $lateExpense, [
        'project_id' => $closed->id,
        'reason' => 'Tardiva',
        'actual_kind' => 'late',
        'activity_note' => 'Documento ricevuto dopo la chiusura',
    ]);
    $action->confirm($actor, $lateExpense, $late, (string) Str::uuid());
    expect($closed->refresh()->stateAtDate('2026-08-18'))->toBe(ProjectState::Closed);

    $invalid = Expense::factory()->forExercise($exercise)->create();
    ExpenseLine::factory()->for($invalid)->create();
    expect(fn () => $action->preview($actor, $invalid, ['project_id' => $archived->id]))->toThrow(ValidationException::class)
        ->and(fn () => $action->preview($actor, $invalid, ['contract_id' => 10]))->toThrow(ValidationException::class);
    $invalid->update(['reversed_at' => now()]);
    expect(fn () => $action->preview($actor, $invalid, ['project_id' => $planned->id]))->toThrow(ValidationException::class);
});

it('rejects stale previews and rolls back ownership revisions and opening on audit failure', function () {
    [$actor, , $exercise, $project] = projectMoveContext(ProjectState::Planned);
    $expense = Expense::factory()->forExercise($exercise)->create();
    ExpenseLine::factory()->for($expense)->actual()->create(['amount' => '1.00']);
    $action = app(UpdateExpense::class);
    $stale = $action->preview($actor, $expense, ['project_id' => $project->id, 'reason' => 'Ingresso', 'open_project' => true]);
    $project->increment('revision');
    expect(fn () => $action->confirm($actor, $expense, $stale, (string) Str::uuid()))->toThrow(ValidationException::class);

    $project->update(['revision' => 0]);
    $preview = $action->preview($actor, $expense, ['project_id' => $project->id, 'reason' => 'Ingresso', 'open_project' => true]);
    AuditEvent::creating(fn () => throw new RuntimeException('Forced audit failure'));
    expect(fn () => $action->confirm($actor, $expense, $preview, (string) Str::uuid()))->toThrow(RuntimeException::class);

    expect($expense->refresh()->project_id)->toBeNull()
        ->and($project->refresh()->revision)->toBe(0)
        ->and($exercise->refresh()->revision)->toBe(0)
        ->and(ProjectTransition::query()->count())->toBe(0);
    AuditEvent::flushEventListeners();
});
