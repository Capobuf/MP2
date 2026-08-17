<?php

use App\Actions\Operations\CreateExpenseLine;
use App\Actions\Operations\SetExpenseLineActive;
use App\Actions\Operations\SetExpenseReversed;
use App\Actions\Operations\UpdateExpense;
use App\Actions\Operations\UpdateExpenseLine;
use App\Domain\Company\Capability;
use App\Domain\Projects\ProjectState;
use App\Models\AuditEvent;
use App\Models\Company;
use App\Models\CompanyCapability;
use App\Models\Exercise;
use App\Models\Expense;
use App\Models\ExpenseLine;
use App\Models\Project;
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

function manageableProjectExpense(ProjectState $state = ProjectState::Open, bool $noteRequired = false): array
{
    $actor = User::factory()->create();
    $company = Company::factory()->create(['timezone' => 'Europe/Rome', 'overspend_note_required' => $noteRequired]);
    foreach ([Capability::View, Capability::ManageOperations] as $capability) {
        CompanyCapability::query()->create(['company_id' => $company->id, 'user_id' => $actor->id, 'capability' => $capability]);
    }
    $exercise = Exercise::factory()->for($company)->create(['year' => 2026]);
    $project = Project::factory()->for($company)->create(['initial_state' => $state, 'initial_effective_date' => '2026-01-01']);
    $expense = Expense::factory()->forExercise($exercise)->for($project)->create(['direct_cost_center_id' => null]);

    return [$actor, $company, $exercise, $project, $expense];
}

it('propagates child Line mutations to Expense Project Exercise and Project-referenced audit', function () {
    [$actor, , $exercise, $project, $expense] = manageableProjectExpense();
    $line = app(CreateExpenseLine::class)->execute($actor, $expense, ['type' => 'estimate', 'amount' => '100.00'], (string) Str::uuid());
    app(UpdateExpenseLine::class)->execute($actor, $line, ['type' => 'estimate', 'amount' => '120.00'], (string) Str::uuid());
    app(SetExpenseLineActive::class)->execute($actor, $line, false, (string) Str::uuid());
    app(SetExpenseLineActive::class)->execute($actor, $line, true, (string) Str::uuid());

    expect($expense->refresh()->revision)->toBe(4)
        ->and($project->refresh()->revision)->toBe(4)
        ->and($exercise->refresh()->revision)->toBe(4)
        ->and($project->annualTotals()[$exercise->id]['allocation'])->toBe('120.00')
        ->and(AuditEvent::query()->where('reference_type', Project::class)->where('reference_id', $project->id)->count())->toBe(4);
});

it('enforces Project state and declarations on added updated and restored Actual Lines', function () {
    [$actor, , , $project, $expense] = manageableProjectExpense(ProjectState::Closed);
    $estimate = ExpenseLine::factory()->for($expense)->create(['type' => 'estimate', 'amount' => '10.00']);

    expect(fn () => app(CreateExpenseLine::class)->execute($actor, $expense, ['type' => 'actual', 'amount' => '2.00'], (string) Str::uuid()))
        ->toThrow(ValidationException::class)
        ->and(fn () => app(UpdateExpenseLine::class)->execute($actor, $estimate, ['type' => 'actual', 'amount' => '2.00'], (string) Str::uuid()))
        ->toThrow(ValidationException::class);

    $actual = app(CreateExpenseLine::class)->execute($actor, $expense, [
        'type' => 'actual',
        'amount' => '2.00',
        'actual_kind' => 'late',
        'activity_note' => 'Fattura tardiva',
    ], (string) Str::uuid());
    app(SetExpenseLineActive::class)->execute($actor, $actual, false, (string) Str::uuid());

    expect(fn () => app(SetExpenseLineActive::class)->execute($actor, $actual, true, (string) Str::uuid()))
        ->toThrow(ValidationException::class);
    app(SetExpenseLineActive::class)->execute($actor, $actual, true, (string) Str::uuid(), [
        'actual_kind' => 'corrective',
        'activity_note' => 'Ripristino verificato',
    ]);

    expect($project->refresh()->stateAtDate('2026-08-18'))->toBe(ProjectState::Closed)
        ->and($expense->refresh()->actual())->toBe('2.00');
});

it('requires the configured overspend note and rolls the Line mutation back', function () {
    [$actor, , $exercise, $project, $expense] = manageableProjectExpense(ProjectState::Open, true);

    expect(fn () => app(CreateExpenseLine::class)->execute($actor, $expense, ['type' => 'actual', 'amount' => '5.00'], (string) Str::uuid()))
        ->toThrow(ValidationException::class);
    expect(ExpenseLine::query()->count())->toBe(0)
        ->and($project->refresh()->revision)->toBe(0)
        ->and($exercise->refresh()->revision)->toBe(0);

    app(CreateExpenseLine::class)->execute($actor, $expense, [
        'type' => 'actual',
        'amount' => '5.00',
        'overspend_note' => 'Costo autorizzato',
    ], (string) Str::uuid());

    expect($project->refresh()->annualTotals()[$exercise->id]['actual'])->toBe('5.00')
        ->and(AuditEvent::query()->sole()->new_value['project_activity']['overspend']['result'])->toBe('created');
});

it('can atomically open a Planned Project while adding an ordinary Actual Line', function () {
    [$actor, , $exercise, $project, $expense] = manageableProjectExpense(ProjectState::Planned);

    app(CreateExpenseLine::class)->execute($actor, $expense, [
        'type' => 'actual',
        'amount' => '4.00',
        'open_project' => true,
    ], (string) Str::uuid());

    expect($project->refresh()->stateAtDate('2026-08-18'))->toBe(ProjectState::Open)
        ->and($project->revision)->toBe(2)
        ->and($exercise->refresh()->revision)->toBe(1)
        ->and(AuditEvent::query()->sole()->new_value['project_activity']['opening_transition'])->not->toBeNull();
});

it('reverses and restores a Project Expense with stable ownership and Project revisions', function () {
    [$actor, , $exercise, $project, $expense] = manageableProjectExpense();
    ExpenseLine::factory()->for($expense)->create(['type' => 'estimate', 'amount' => '50.00']);

    app(SetExpenseReversed::class)->execute($actor, $expense, true, 'Duplicata', (string) Str::uuid());
    app(SetExpenseReversed::class)->execute($actor, $expense, false, 'Confermata', (string) Str::uuid());

    expect($expense->refresh()->project_id)->toBe($project->id)
        ->and($expense->allocation())->toBe('50.00')
        ->and($project->refresh()->revision)->toBe(2)
        ->and($exercise->refresh()->revision)->toBe(2)
        ->and(AuditEvent::query()->where('reference_type', Project::class)->count())->toBe(2);
});

it('propagates descriptive Expense edits to the owning Project without changing annual economics', function () {
    [$actor, , $exercise, $project, $expense] = manageableProjectExpense();

    app(UpdateExpense::class)->updateDetails($actor, $expense, [
        'description' => 'Descrizione aggiornata',
        'notes' => 'Nota aggiornata',
    ], (string) Str::uuid());

    $event = AuditEvent::query()->sole();

    expect($expense->refresh()->revision)->toBe(1)
        ->and($project->refresh()->revision)->toBe(1)
        ->and($exercise->refresh()->revision)->toBe(0)
        ->and($event->reference_type)->toBe(Project::class)
        ->and($event->reference_id)->toBe($project->id);
});
