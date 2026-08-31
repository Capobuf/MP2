<?php

use App\Actions\Operations\UpdateExpense;
use App\Domain\Company\AuditEventType;
use App\Models\AuditEvent;
use App\Models\BudgetSnapshot;
use App\Models\Company;
use App\Models\Contract;
use App\Models\ContractExerciseClassification;
use App\Models\ContractLifecycleFact;
use App\Models\CostCenter;
use App\Models\Exercise;
use App\Models\Expense;
use App\Models\ExpenseLine;
use App\Models\Proposal;
use App\Models\Supplier;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Tests\Support\TestPermissions;

uses(RefreshDatabase::class);

function moveContext(): array
{
    $actor = User::factory()->create();
    $company = Company::factory()->create(['timezone' => 'Europe/Rome']);
    foreach ([TestPermissions::VIEW, TestPermissions::MANAGE_OPERATIONS] as $capability) {
        grantTestPermissions(['company_id' => $company->id, 'user' => $actor, 'permissions' => $capability]);
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

it('requires a reason only when the Description changes after an approved Budget', function () {
    [$actor, $company, $exercise, , $expense] = moveContext();
    $proposal = Proposal::factory()->for($company)->for($exercise)->create(['created_by_id' => $actor->id]);
    BudgetSnapshot::factory()->for($proposal)->create([
        'company_id' => $company->id,
        'exercise_id' => $exercise->id,
        'approved_by_id' => $actor->id,
    ]);

    app(UpdateExpense::class)->updateDetails($actor, $expense, [
        'description' => $expense->description,
        'notes' => 'Nota aggiornata',
    ], (string) Str::uuid());

    expect(fn () => app(UpdateExpense::class)->updateDetails($actor, $expense->refresh(), [
        'description' => 'Descrizione aggiornata',
        'notes' => 'Nota aggiornata',
    ], (string) Str::uuid()))->toThrow(ValidationException::class);

    app(UpdateExpense::class)->updateDetails($actor, $expense->refresh(), [
        'description' => 'Descrizione aggiornata',
        'notes' => 'Nota aggiornata',
        'change_reason' => 'Correzione descrittiva',
    ], (string) Str::uuid());

    expect($expense->refresh()->description)->toBe('Descrizione aggiornata');
});

it('moves one whole Actual Expense between autonomous and Contract ownership with stable identity and totals once', function () {
    [$actor, $company, $exercise, , $expense] = moveContext();
    $supplier = Supplier::factory()->for($company)->create();
    $contract = Contract::factory()->for($company)->for($supplier)->create(['next_expiry_date' => null, 'renewal_anchor_date' => null]);
    ContractLifecycleFact::factory()->forContract($contract)->create();
    ContractExerciseClassification::factory()->forContractAndExercise($contract, $exercise)->create();
    $line = ExpenseLine::factory()->for($expense)->actual()->create(['amount' => '40.00']);
    $action = app(UpdateExpense::class);

    expect(fn () => $action->preview($actor, $expense, ['contract_id' => $contract->id, 'reason' => 'Attribuzione', 'actual_kind' => 'ordinary']))
        ->toThrow(ValidationException::class);
    $plan = $action->preview($actor, $expense, [
        'contract_id' => $contract->id,
        'reason' => 'Attribuzione al Contratto',
        'actual_kind' => 'ordinary',
        'supplier_replacement_acknowledged' => true,
    ]);
    $moved = $action->confirm($actor, $expense, $plan, (string) Str::uuid());

    expect($moved->id)->toBe($expense->id)
        ->and($line->refresh()->expense_id)->toBe($expense->id)
        ->and($moved->project_id)->toBeNull()
        ->and($moved->contract_id)->toBe($contract->id)
        ->and($moved->supplier_id)->toBe($supplier->id)
        ->and($moved->direct_cost_center_id)->toBeNull()
        ->and($contract->refresh()->annualTotals()[$exercise->id]['actual'])->toBe('40.00')
        ->and($exercise->actual())->toBe('40.00');

    $out = $action->preview($actor, $moved, ['contract_id' => null, 'direct_cost_center_id' => null, 'reason' => 'Ritorno autonoma']);
    $action->confirm($actor, $moved, $out, (string) Str::uuid());

    expect($expense->refresh()->contract_id)->toBeNull()
        ->and($expense->supplier_id)->toBe($supplier->id)
        ->and($expense->direct_cost_center_id)->toBeNull()
        ->and($contract->refresh()->annualTotals())->not->toHaveKey($exercise->id)
        ->and($exercise->actual())->toBe('40.00');
});

it('rejects moving manual Estimates into a Contract and rejects stale Contract previews atomically', function () {
    [$actor, $company, $exercise, , $expense] = moveContext();
    $contract = Contract::factory()->for($company)->create(['next_expiry_date' => null, 'renewal_anchor_date' => null]);
    ContractLifecycleFact::factory()->forContract($contract)->create();
    ContractExerciseClassification::factory()->forContractAndExercise($contract, $exercise)->create();
    ExpenseLine::factory()->for($expense)->create(['amount' => '10.00']);

    expect(fn () => app(UpdateExpense::class)->preview($actor, $expense, ['contract_id' => $contract->id]))
        ->toThrow(ValidationException::class);

    $expense->lines()->update(['type' => 'actual']);
    $plan = app(UpdateExpense::class)->preview($actor, $expense->refresh(), [
        'contract_id' => $contract->id, 'reason' => 'Attribuzione', 'supplier_replacement_acknowledged' => true,
    ]);
    $contract->increment('revision');
    expect(fn () => app(UpdateExpense::class)->confirm($actor, $expense, $plan, (string) Str::uuid()))
        ->toThrow(ValidationException::class)
        ->and($expense->refresh()->contract_id)->toBeNull()
        ->and(AuditEvent::query()->count())->toBe(0);
});

it('rolls back Contract ownership Supplier totals and revisions when audit persistence fails', function () {
    [$actor, $company, $exercise, , $expense] = moveContext();
    $supplier = Supplier::factory()->for($company)->create();
    $contract = Contract::factory()->for($company)->for($supplier)->create(['next_expiry_date' => null, 'renewal_anchor_date' => null]);
    ContractLifecycleFact::factory()->forContract($contract)->create();
    ContractExerciseClassification::factory()->forContractAndExercise($contract, $exercise)->create();
    ExpenseLine::factory()->actual()->for($expense)->create(['amount' => '7.00']);
    $plan = app(UpdateExpense::class)->preview($actor, $expense, [
        'contract_id' => $contract->id, 'reason' => 'Attribuzione', 'supplier_replacement_acknowledged' => true,
    ]);
    AuditEvent::creating(fn () => throw new RuntimeException('forced contract move rollback'));

    expect(fn () => app(UpdateExpense::class)->confirm($actor, $expense, $plan, (string) Str::uuid()))
        ->toThrow(RuntimeException::class)
        ->and($expense->refresh()->contract_id)->toBeNull()
        ->and($expense->supplier_id)->toBeNull()
        ->and($contract->refresh()->revision)->toBe(0)
        ->and($exercise->refresh()->revision)->toBe(0)
        ->and($contract->annualTotals())->toBe([]);
    AuditEvent::flushEventListeners();
});
