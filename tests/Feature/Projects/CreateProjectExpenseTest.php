<?php

use App\Actions\Operations\CreateExpense;
use App\Domain\Company\AuditEventType;
use App\Domain\Projects\ProjectState;
use App\Models\AuditEvent;
use App\Models\Company;
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
use Tests\Support\TestPermissions;

uses(RefreshDatabase::class);

function projectExpenseContext(ProjectState $state = ProjectState::Planned, bool $overspendNoteRequired = false): array
{
    $actor = User::factory()->create();
    $company = Company::factory()->create([
        'timezone' => 'Europe/Rome',
        'overspend_note_required' => $overspendNoteRequired,
    ]);
    foreach ([TestPermissions::VIEW, TestPermissions::MANAGE_OPERATIONS] as $capability) {
        grantTestPermissions(['company_id' => $company->id, 'user' => $actor, 'permissions' => $capability]);
    }
    $exercise = Exercise::factory()->for($company)->create(['year' => 2026]);
    $project = Project::factory()->for($company)->create([
        'initial_state' => $state,
        'initial_effective_date' => '2026-01-01',
    ]);

    return [$actor, $company, $exercise, $project];
}

beforeEach(function () {
    CarbonImmutable::setTestNow('2026-08-18 10:00:00 Europe/Rome');
});

afterEach(function () {
    CarbonImmutable::setTestNow();
});

it('creates a Project Expense with inherited classification and exact totals counted once', function () {
    [$actor, $company, $exercise, $project] = projectExpenseContext();
    $costCenter = CostCenter::factory()->for($company)->create();
    ProjectExerciseClassification::factory()->forProjectAndExercise($project, $exercise)->create(['cost_center_id' => $costCenter->id]);
    $autonomous = Expense::factory()->forExercise($exercise)->create();
    ExpenseLine::factory()->for($autonomous)->create(['amount' => '25.00']);

    $expense = app(CreateExpense::class)->execute($actor, $company, [
        'project_id' => $project->id,
        'exercise_id' => $exercise->id,
        'description' => 'Servizi progetto',
        'lines' => [
            ['type' => 'estimate', 'amount' => '100.10'],
            ['type' => 'estimate', 'amount' => '0.20'],
        ],
    ], (string) Str::uuid());

    expect($expense->project_id)->toBe($project->id)
        ->and($expense->direct_cost_center_id)->toBeNull()
        ->and($expense->costCenterLabel())->toContain($costCenter->name, 'ereditata dal Progetto')
        ->and($project->annualTotals()[$exercise->id]['allocation'])->toBe('100.30')
        ->and($exercise->allocation())->toBe('125.30')
        ->and($project->refresh()->revision)->toBe(1)
        ->and($exercise->refresh()->revision)->toBe(1)
        ->and(AuditEvent::query()->sole()->reference_id)->toBe($project->id);
});

it('opens a Planned Project and records its first ordinary Actual in one atomic command', function () {
    [$actor, $company, $exercise, $project] = projectExpenseContext();
    $input = [
        'project_id' => $project->id,
        'exercise_id' => $exercise->id,
        'description' => 'Prima fattura',
        'actual_kind' => 'ordinary',
        'lines' => [['type' => 'actual', 'amount' => '80.00']],
    ];

    expect(fn () => app(CreateExpense::class)->execute($actor, $company, $input, (string) Str::uuid()))
        ->toThrow(ValidationException::class);

    $operationId = (string) Str::uuid();
    $expense = app(CreateExpense::class)->execute($actor, $company, [...$input, 'open_project' => true], $operationId);
    $retry = app(CreateExpense::class)->execute($actor, $company, [...$input, 'open_project' => true], $operationId);
    $event = AuditEvent::query()->sole();

    expect($retry->is($expense))->toBeTrue()
        ->and(ProjectTransition::query()->count())->toBe(1)
        ->and($project->refresh()->stateAtDate('2026-08-18'))->toBe(ProjectState::Open)
        ->and($project->revision)->toBe(2)
        ->and($event->event_type)->toBe(AuditEventType::ExpenseCreated)
        ->and($event->new_value['project_activity']['opening_transition']['effective_date'])->toBe('2026-08-18')
        ->and(AuditEvent::query()->count())->toBe(1);
});

it('accepts only declared and noted late reimbursement or corrective Actuals on terminal Projects', function (string $kind) {
    [$actor, $company, $exercise, $project] = projectExpenseContext(ProjectState::Closed);
    $base = [
        'project_id' => $project->id,
        'exercise_id' => $exercise->id,
        'description' => 'Attribuzione successiva',
        'lines' => [['type' => 'actual', 'amount' => '-10.00', 'note' => 'Rettifica documento']],
    ];

    expect(fn () => app(CreateExpense::class)->execute($actor, $company, $base, (string) Str::uuid()))
        ->toThrow(ValidationException::class)
        ->and(fn () => app(CreateExpense::class)->execute($actor, $company, [...$base, 'actual_kind' => $kind], (string) Str::uuid()))
        ->toThrow(ValidationException::class);

    app(CreateExpense::class)->execute($actor, $company, [
        ...$base,
        'actual_kind' => $kind,
        'activity_note' => 'Documento ricevuto dopo la chiusura',
    ], (string) Str::uuid());

    expect($project->refresh()->stateAtDate('2026-08-18'))->toBe(ProjectState::Closed)
        ->and(ProjectTransition::query()->count())->toBe(0)
        ->and(AuditEvent::query()->sole()->reason)->toBe('Documento ricevuto dopo la chiusura');
})->with(['late', 'reimbursement', 'corrective']);

it('rejects direct classification archived targets and missing required overspend note with full rollback', function () {
    [$actor, $company, $exercise, $project] = projectExpenseContext(ProjectState::Open, true);
    $costCenter = CostCenter::factory()->for($company)->create();
    $project->update(['archived_at' => now()]);
    $base = [
        'project_id' => $project->id,
        'exercise_id' => $exercise->id,
        'description' => 'Non valida',
        'lines' => [['type' => 'actual', 'amount' => '10.00']],
    ];

    expect(fn () => app(CreateExpense::class)->execute($actor, $company, [...$base, 'direct_cost_center_id' => $costCenter->id], (string) Str::uuid()))
        ->toThrow(ValidationException::class);

    $project->update(['archived_at' => null]);
    expect(fn () => app(CreateExpense::class)->execute($actor, $company, $base, (string) Str::uuid()))
        ->toThrow(ValidationException::class);

    expect(Expense::query()->count())->toBe(0)
        ->and(ExpenseLine::query()->count())->toBe(0)
        ->and(AuditEvent::query()->count())->toBe(0)
        ->and($project->refresh()->revision)->toBe(0)
        ->and($exercise->refresh()->revision)->toBe(0);
});

it('rolls back atomic opening economics revisions and transition when audit persistence fails', function () {
    [$actor, $company, $exercise, $project] = projectExpenseContext();
    AuditEvent::creating(fn () => throw new RuntimeException('Forced audit failure'));

    expect(fn () => app(CreateExpense::class)->execute($actor, $company, [
        'project_id' => $project->id,
        'exercise_id' => $exercise->id,
        'description' => 'Rollback completo',
        'open_project' => true,
        'lines' => [['type' => 'actual', 'amount' => '1.00']],
    ], (string) Str::uuid()))->toThrow(RuntimeException::class);

    expect(Expense::query()->count())->toBe(0)
        ->and(ProjectTransition::query()->count())->toBe(0)
        ->and($project->refresh()->revision)->toBe(0)
        ->and($exercise->refresh()->revision)->toBe(0);

    AuditEvent::flushEventListeners();
});
