<?php

use App\Actions\Operations\UpdateExpense;
use App\Domain\Company\AuditEventType;
use App\Domain\Company\Capability;
use App\Models\AuditEvent;
use App\Models\Company;
use App\Models\CompanyCapability;
use App\Models\CostCenter;
use App\Models\Exercise;
use App\Models\Expense;
use App\Models\ExpenseLine;
use App\Models\Supplier;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

uses(RefreshDatabase::class);

function moveContext(): array
{
    $actor = User::factory()->create();
    $company = Company::factory()->create(['timezone' => 'Europe/Rome']);
    foreach ([Capability::View, Capability::ManageOperations] as $capability) {
        CompanyCapability::query()->create(['company_id' => $company->id, 'user_id' => $actor->id, 'capability' => $capability]);
    }
    $source = Exercise::factory()->for($company)->create(['year' => now('Europe/Rome')->year]);
    $target = Exercise::factory()->for($company)->create(['year' => now('Europe/Rome')->year + 1]);
    $expense = Expense::factory()->forExercise($source)->create();

    return [$actor, $company, $source, $target, $expense];
}

it('moves an estimate-only expense atomically after exact preview and preserves identity', function () {
    [$actor, , $source, $target, $expense] = moveContext();
    $line = ExpenseLine::factory()->for($expense)->create(['amount' => '100.00']);
    $plan = app(UpdateExpense::class)->preview($actor, $expense, ['exercise_id' => $target->id]);
    $result = app(UpdateExpense::class)->confirm($actor, $expense, $plan, (string) Str::uuid());
    $event = AuditEvent::query()->sole();

    expect($result->id)->toBe($expense->id)
        ->and($line->refresh()->expense_id)->toBe($expense->id)
        ->and($result->exercise_id)->toBe($target->id)
        ->and($source->allocation())->toBe('0.00')
        ->and($target->allocation())->toBe('100.00')
        ->and($event->event_type)->toBe(AuditEventType::ExpenseMovedOrReclassified)
        ->and($event->allocated_impact_by_exercise)->toBe([(string) $source->id => '-100.00', (string) $target->id => '100.00']);
});

it('rejects a stale preview and future move with actuals', function () {
    [$actor, , , $target, $expense] = moveContext();
    ExpenseLine::factory()->for($expense)->create(['amount' => '100.00']);
    $stale = app(UpdateExpense::class)->preview($actor, $expense, ['exercise_id' => $target->id]);
    $expense->increment('revision');

    expect(fn () => app(UpdateExpense::class)->confirm($actor, $expense, $stale, (string) Str::uuid()))
        ->toThrow(ValidationException::class);

    $expense->refresh();
    ExpenseLine::factory()->for($expense)->actual()->create(['amount' => '1.00']);
    expect(fn () => app(UpdateExpense::class)->preview($actor, $expense, ['exercise_id' => $target->id, 'reason' => 'Correzione']))
        ->toThrow(ValidationException::class)
        ->and(AuditEvent::query()->count())->toBe(0);
});

it('reclassifies only to active same-company references and audits descriptive edits', function () {
    [$actor, $company, , , $expense] = moveContext();
    ExpenseLine::factory()->for($expense)->create(['amount' => '10.00']);
    $supplier = Supplier::factory()->for($company)->create();
    $costCenter = CostCenter::factory()->for($company)->create();
    $plan = app(UpdateExpense::class)->preview($actor, $expense, [
        'supplier_id' => $supplier->id,
        'direct_cost_center_id' => $costCenter->id,
    ]);
    app(UpdateExpense::class)->confirm($actor, $expense, $plan, (string) Str::uuid());
    app(UpdateExpense::class)->updateDetails($actor, $expense->refresh(), [
        'description' => ' Nuova descrizione ',
        'notes' => ' Nota ',
    ], (string) Str::uuid());

    expect($expense->refresh()->supplier_id)->toBe($supplier->id)
        ->and($expense->direct_cost_center_id)->toBe($costCenter->id)
        ->and($expense->description)->toBe('Nuova descrizione')
        ->and(AuditEvent::query()->count())->toBe(2);

    $other = Supplier::factory()->create();
    expect(fn () => app(UpdateExpense::class)->preview($actor, $expense, ['supplier_id' => $other->id]))
        ->toThrow(ValidationException::class);
});
