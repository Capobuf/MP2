<?php

use App\Actions\Operations\UpdateExpense;
use App\Domain\Projects\ProjectState;
use App\Models\AuditEvent;
use App\Models\Company;
use App\Models\Contract;
use App\Models\ContractExerciseClassification;
use App\Models\ContractLifecycleFact;
use App\Models\CostCenter;
use App\Models\Exercise;
use App\Models\Expense;
use App\Models\ExpenseLine;
use App\Models\Project;
use App\Models\ProjectExerciseClassification;
use App\Models\ProjectTransition;
use App\Models\Supplier;
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

function projectMoveContext(ProjectState $state = ProjectState::Open): array
{
    $actor = User::factory()->create();
    $company = Company::factory()->create(['timezone' => 'Europe/Rome']);
    foreach ([TestPermissions::VIEW, TestPermissions::MANAGE_OPERATIONS] as $capability) {
        grantTestPermissions(['company_id' => $company->id, 'user' => $actor, 'permissions' => $capability]);
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

it('moves a contained Expense to a previous open Exercise and keeps the same Project and line identities', function () {
    [$actor, $company, $source, $project] = projectMoveContext();
    $target = Exercise::factory()->for($company)->create(['year' => 2025]);
    $project->update(['initial_effective_date' => '2025-01-01']);
    ProjectExerciseClassification::factory()->forProjectAndExercise($project, $target)->create();
    $expense = Expense::factory()->forExercise($source)->for($project)->create(['direct_cost_center_id' => null]);
    $estimate = ExpenseLine::factory()->for($expense)->create(['amount' => '100.00']);
    $actual = ExpenseLine::factory()->for($expense)->actual()->create(['amount' => '30.00']);
    $action = app(UpdateExpense::class);

    $plan = $action->preview($actor, $expense, [
        'exercise_id' => $target->id,
        'reason' => 'Correzione dell’Esercizio',
    ]);
    $moved = $action->confirm($actor, $expense, $plan, (string) Str::uuid());

    expect($moved->exercise_id)->toBe($target->id)
        ->and($moved->project_id)->toBe($project->id)
        ->and($estimate->refresh()->expense_id)->toBe($expense->id)
        ->and($actual->refresh()->expense_id)->toBe($expense->id)
        ->and($project->refresh()->annualTotals())->not->toHaveKey($source->id)
        ->and($project->annualTotals()[$target->id]['allocation'])->toBe('100.00')
        ->and($project->annualTotals()[$target->id]['actual'])->toBe('30.00')
        ->and(AuditEvent::query()->sole()->affected_exercise_ids)->toBe([$source->id, $target->id]);
});

it('routes a future estimate-only Project move through Reprogramming', function () {
    $actor = User::factory()->create();
    $company = Company::factory()->create(['timezone' => 'Europe/Rome']);
    foreach ([TestPermissions::VIEW, TestPermissions::MANAGE_OPERATIONS] as $capability) {
        grantTestPermissions(['company_id' => $company->id, 'user' => $actor, 'permissions' => $capability]);
    }
    $source = Exercise::factory()->for($company)->create(['year' => 2025]);
    $target = Exercise::factory()->for($company)->create(['year' => 2026]);
    $project = Project::factory()->for($company)->create(['initial_state' => ProjectState::Open, 'initial_effective_date' => '2025-01-01']);
    $expense = Expense::factory()->forExercise($source)->for($project)->create(['direct_cost_center_id' => null]);
    ExpenseLine::factory()->for($expense)->create(['amount' => '100.00']);

    expect(fn () => app(UpdateExpense::class)->preview($actor, $expense, ['exercise_id' => $target->id]))
        ->toThrow(ValidationException::class, 'Riprogrammazione del Progetto');
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
    $otherContract = Contract::factory()->create();
    expect(fn () => $action->preview($actor, $invalid, ['project_id' => $archived->id]))->toThrow(ValidationException::class)
        ->and(fn () => $action->preview($actor, $invalid, ['contract_id' => $otherContract->id]))->toThrow(ValidationException::class);
    $invalid->update(['reversed_at' => now()]);
    expect(fn () => $action->preview($actor, $invalid, ['project_id' => $planned->id]))->toThrow(ValidationException::class);
});

it('moves one whole Actual Expense from Project to Contract and back to Project', function () {
    [$actor, $company, $exercise, $project] = projectMoveContext();
    $supplier = Supplier::factory()->for($company)->create();
    $contract = Contract::factory()->for($company)->for($supplier)->create(['next_expiry_date' => null, 'renewal_anchor_date' => null]);
    ContractLifecycleFact::factory()->forContract($contract)->create();
    ContractExerciseClassification::factory()->forContractAndExercise($contract, $exercise)->create();
    $expense = Expense::factory()->forExercise($exercise)->for($project)->create(['direct_cost_center_id' => null]);
    $line = ExpenseLine::factory()->for($expense)->actual()->create(['amount' => '15.00']);
    $action = app(UpdateExpense::class);

    $toContract = $action->preview($actor, $expense, [
        'project_id' => null,
        'contract_id' => $contract->id,
        'reason' => 'Contratto corretto',
        'supplier_replacement_acknowledged' => true,
    ]);
    $action->confirm($actor, $expense, $toContract, (string) Str::uuid());
    expect($expense->refresh()->project_id)->toBeNull()
        ->and($expense->contract_id)->toBe($contract->id)
        ->and($line->refresh()->expense_id)->toBe($expense->id);

    $toProject = $action->preview($actor, $expense, [
        'contract_id' => null,
        'project_id' => $project->id,
        'reason' => 'Progetto corretto',
    ]);
    $action->confirm($actor, $expense, $toProject, (string) Str::uuid());

    expect($expense->refresh()->project_id)->toBe($project->id)
        ->and($expense->contract_id)->toBeNull()
        ->and($exercise->actual())->toBe('15.00')
        ->and($contract->refresh()->annualTotals())->not->toHaveKey($exercise->id)
        ->and($project->refresh()->annualTotals()[$exercise->id]['actual'])->toBe('15.00');
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
