<?php

use App\Actions\Operations\CreateExpenseLine;
use App\Actions\Operations\SetExpenseLineActive;
use App\Actions\Operations\UpdateExpenseLine;
use App\Domain\Company\AuditEventType;
use App\Domain\Company\Capability;
use App\Models\AuditEvent;
use App\Models\Company;
use App\Models\CompanyCapability;
use App\Models\Exercise;
use App\Models\Expense;
use App\Models\ExpenseLine;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

uses(RefreshDatabase::class);

function lineContext(bool $reversed = false): array
{
    $actor = User::factory()->create();
    $company = Company::factory()->create(['timezone' => 'Europe/Rome']);
    foreach ([Capability::View, Capability::ManageOperations] as $capability) {
        CompanyCapability::query()->create(['company_id' => $company->id, 'user_id' => $actor->id, 'capability' => $capability]);
    }
    $exercise = Exercise::factory()->for($company)->create(['year' => now('Europe/Rome')->year]);
    $expenseFactory = Expense::factory()->forExercise($exercise);
    $expense = $reversed ? $expenseFactory->reversed()->create() : $expenseFactory->create();

    return [$actor, $company, $exercise, $expense];
}

it('creates updates annuls and restores one stable line with exact impacts', function () {
    [$actor, , $exercise, $expense] = lineContext();
    $createId = (string) Str::uuid();
    $line = app(CreateExpenseLine::class)->execute($actor, $expense, ['type' => 'estimate', 'amount' => '100.00'], $createId);
    $retry = app(CreateExpenseLine::class)->execute($actor, $expense, ['type' => 'estimate', 'amount' => '999.00'], $createId);
    app(UpdateExpenseLine::class)->execute($actor, $line, ['type' => 'estimate', 'amount' => '150.00'], (string) Str::uuid());
    app(SetExpenseLineActive::class)->execute($actor, $line, false, (string) Str::uuid());
    app(SetExpenseLineActive::class)->execute($actor, $line, true, (string) Str::uuid());

    expect($retry->is($line))->toBeTrue()
        ->and($line->refresh()->amount)->toBe('150.00')
        ->and($line->isAnnulled())->toBeFalse()
        ->and($expense->refresh()->allocation())->toBe('150.00')
        ->and($expense->revision)->toBe(4)
        ->and($exercise->refresh()->revision)->toBe(4)
        ->and(AuditEvent::query()->pluck('event_type')->all())->toBe([
            AuditEventType::ExpenseLineCreated,
            AuditEventType::ExpenseLineUpdated,
            AuditEventType::ExpenseLineAnnulled,
            AuditEventType::ExpenseLineRestored,
        ]);
});

it('makes current-state requests no-ops and blocks line mutation on a reversed expense', function () {
    [$actor, , , $expense] = lineContext();
    $line = ExpenseLine::factory()->for($expense)->create();

    app(SetExpenseLineActive::class)->execute($actor, $line, true, (string) Str::uuid());
    expect(AuditEvent::query()->count())->toBe(0);

    $expense->update(['reversed_at' => now()]);
    expect(fn () => app(CreateExpenseLine::class)->execute($actor, $expense, ['type' => 'estimate', 'amount' => '1.00'], (string) Str::uuid()))
        ->toThrow(ValidationException::class)
        ->and(fn () => app(UpdateExpenseLine::class)->execute($actor, $line, ['type' => 'estimate', 'amount' => '2.00'], (string) Str::uuid()))
        ->toThrow(ValidationException::class)
        ->and(fn () => app(SetExpenseLineActive::class)->execute($actor, $line, false, (string) Str::uuid()))
        ->toThrow(ValidationException::class)
        ->and(AuditEvent::query()->count())->toBe(0);
});

it('preserves offsetting actual presence independently from the net total', function () {
    [$actor, , , $expense] = lineContext();
    app(CreateExpenseLine::class)->execute($actor, $expense, ['type' => 'actual', 'amount' => '100.00'], (string) Str::uuid());
    app(CreateExpenseLine::class)->execute($actor, $expense, ['type' => 'actual', 'amount' => '-100.00', 'note' => 'Rimborso'], (string) Str::uuid());

    expect($expense->refresh()->actual())->toBe('0.00')
        ->and($expense->hasActuals())->toBeTrue();
});
