<?php

use App\Actions\Operations\CreateExpenseLine;
use App\Actions\Operations\SetExpenseLineActive;
use App\Actions\Operations\UpdateExpenseLine;
use App\Domain\Company\Capability;
use App\Domain\Projects\ProjectState;
use App\Filament\Resources\Expenses\Pages\ViewExpense;
use App\Filament\Resources\Expenses\RelationManagers\ExpenseLinesRelationManager;
use App\Models\AuditEvent;
use App\Models\Company;
use App\Models\CompanyCapability;
use App\Models\Exercise;
use App\Models\Expense;
use App\Models\ExpenseLine;
use App\Models\Project;
use App\Models\User;
use Carbon\CarbonImmutable;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    CarbonImmutable::setTestNow('2026-08-18 10:00:00 Europe/Rome');
});

afterEach(function () {
    CarbonImmutable::setTestNow();
});

function overspendContext(bool $noteRequired = false): array
{
    $actor = User::factory()->create();
    $company = Company::factory()->create([
        'timezone' => 'Europe/Rome',
        'overspend_note_required' => $noteRequired,
    ]);
    foreach ([Capability::View, Capability::ManageOperations] as $capability) {
        CompanyCapability::query()->create([
            'company_id' => $company->id,
            'user_id' => $actor->id,
            'capability' => $capability,
        ]);
    }
    $exercise = Exercise::factory()->for($company)->create(['year' => 2026]);
    $project = Project::factory()->for($company)->create([
        'initial_state' => ProjectState::Open,
        'initial_effective_date' => '2026-01-01',
    ]);
    $expense = Expense::factory()->forExercise($exercise)->for($project)->create([
        'direct_cost_center_id' => null,
    ]);

    return [$actor, $company, $exercise, $project, $expense];
}

it('records created increased and absent overspend outcomes in one causal event', function () {
    [$actor, , , , $expense] = overspendContext();
    app(CreateExpenseLine::class)->execute($actor, $expense, [
        'type' => 'estimate',
        'amount' => '10.00',
    ], (string) Str::uuid());

    $createOperation = (string) Str::uuid();
    $actual = app(CreateExpenseLine::class)->execute($actor, $expense, [
        'type' => 'actual',
        'amount' => '12.00',
    ], $createOperation);
    app(CreateExpenseLine::class)->execute($actor, $expense, [
        'type' => 'actual',
        'amount' => '12.00',
    ], $createOperation);
    $created = AuditEvent::query()->where('operation_id', $createOperation)->sole();

    $increaseOperation = (string) Str::uuid();
    app(UpdateExpenseLine::class)->execute($actor, $actual, [
        'type' => 'actual',
        'amount' => '14.00',
    ], $increaseOperation);
    $increased = AuditEvent::query()->where('operation_id', $increaseOperation)->sole();

    $decreaseOperation = (string) Str::uuid();
    app(UpdateExpenseLine::class)->execute($actor, $actual->refresh(), [
        'type' => 'actual',
        'amount' => '13.00',
    ], $decreaseOperation);
    $decreased = AuditEvent::query()->where('operation_id', $decreaseOperation)->sole();

    expect($created->new_value['project_activity']['overspend'])->toMatchArray([
        'result' => 'created',
        'variance_before' => '-10.00',
        'variance_after' => '2.00',
    ])->and($increased->new_value['project_activity']['overspend'])->toMatchArray([
        'result' => 'increased',
        'variance_before' => '2.00',
        'variance_after' => '4.00',
    ])->and($decreased->new_value['project_activity']['overspend'])->toBeNull()
        ->and(AuditEvent::query()->where('operation_id', $createOperation)->count())->toBe(1);
});

it('requires a note when annulling an Estimate creates overspend and rolls back with audit', function () {
    [$actor, , $exercise, $project, $expense] = overspendContext(true);
    $estimate = ExpenseLine::factory()->for($expense)->create(['type' => 'estimate', 'amount' => '10.00']);
    ExpenseLine::factory()->for($expense)->actual()->create(['amount' => '10.00']);

    expect(fn () => app(SetExpenseLineActive::class)->execute(
        $actor,
        $estimate,
        false,
        (string) Str::uuid(),
    ))->toThrow(ValidationException::class);

    expect($estimate->refresh()->isAnnulled())->toBeFalse()
        ->and($project->refresh()->revision)->toBe(0)
        ->and($exercise->refresh()->revision)->toBe(0)
        ->and(AuditEvent::query()->count())->toBe(0);

    AuditEvent::creating(fn () => throw new RuntimeException('Forced audit failure'));
    expect(fn () => app(SetExpenseLineActive::class)->execute(
        $actor,
        $estimate,
        false,
        (string) Str::uuid(),
        ['overspend_note' => 'Scostamento autorizzato'],
    ))->toThrow(RuntimeException::class);

    expect($estimate->refresh()->isAnnulled())->toBeFalse()
        ->and($project->refresh()->revision)->toBe(0)
        ->and($exercise->refresh()->revision)->toBe(0);
    AuditEvent::flushEventListeners();
});

it('notifies the operator when a Line creates Project overspend', function () {
    [$actor, $company, , , $expense] = overspendContext();
    $this->actingAs($actor);
    Filament::setTenant(($company)->tenantCompany);

    $component = Livewire::test(ExpenseLinesRelationManager::class, [
        'ownerRecord' => $expense,
        'pageClass' => ViewExpense::class,
    ])->callTableAction('create', data: [
        'type' => 'actual',
        'amount' => '5.00',
        'quantity' => null,
        'unit_amount' => null,
        'unit_of_measure' => null,
        'note' => null,
        'amount_warning_acknowledged' => false,
        'operation_id' => (string) Str::uuid(),
    ])->assertHasNoTableActionErrors()
        ->assertNotified('Sovraspesa creata');

    $line = ExpenseLine::query()->sole();
    $component->callTableAction('edit', record: $line, data: [
        'type' => 'actual',
        'amount' => '6.00',
        'quantity' => null,
        'unit_amount' => null,
        'unit_of_measure' => null,
        'note' => null,
        'amount_warning_acknowledged' => false,
        'operation_id' => (string) Str::uuid(),
    ])->assertHasNoTableActionErrors()
        ->assertNotified('Sovraspesa aumentata');
});
