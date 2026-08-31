<?php

use App\Actions\Operations\SetExpenseReversed;
use App\Domain\Company\AuditEventType;
use App\Models\AuditEvent;
use App\Models\Company;
use App\Models\Exercise;
use App\Models\Expense;
use App\Models\ExpenseLine;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Tests\Support\TestPermissions;

uses(RefreshDatabase::class);

function reversibleExpense(): array
{
    $actor = User::factory()->create();
    $company = Company::factory()->create();
    foreach ([TestPermissions::VIEW, TestPermissions::MANAGE_OPERATIONS] as $capability) {
        grantTestPermissions(['company_id' => $company->id, 'user' => $actor, 'permissions' => $capability]);
    }
    $exercise = Exercise::factory()->for($company)->create();
    $expense = Expense::factory()->forExercise($exercise)->create();

    return [$actor, $exercise, $expense];
}

it('reverses and restores estimate-only expense with exact impacts and stable identity', function () {
    [$actor, $exercise, $expense] = reversibleExpense();
    $line = ExpenseLine::factory()->for($expense)->create(['amount' => '100.00']);
    $reverseId = (string) Str::uuid();
    $reversed = app(SetExpenseReversed::class)->execute($actor, $expense, true, 'Duplicata', $reverseId);
    $retry = app(SetExpenseReversed::class)->execute($actor, $expense, true, 'Duplicata', $reverseId);
    app(SetExpenseReversed::class)->execute($actor, $expense, false, 'Confermata', (string) Str::uuid());
    $events = AuditEvent::query()->orderBy('id')->get();

    expect($retry->is($reversed))->toBeTrue()
        ->and($expense->refresh()->isReversed())->toBeFalse()
        ->and($expense->id)->toBe($reversed->id)
        ->and($line->refresh()->expense_id)->toBe($expense->id)
        ->and($expense->allocation())->toBe('100.00')
        ->and($exercise->refresh()->revision)->toBe(2)
        ->and($events)->toHaveCount(2)
        ->and($events[0]->event_type)->toBe(AuditEventType::ExpenseReversed)
        ->and($events[0]->allocated_impact_by_exercise)->toBe([(string) $exercise->id => '-100.00'])
        ->and($events[1]->event_type)->toBe(AuditEventType::ExpenseRestored)
        ->and($events[1]->allocated_impact_by_exercise)->toBe([(string) $exercise->id => '100.00']);
});

it('blocks storno for offsetting non-zero actuals and requires a reason', function () {
    [$actor, , $expense] = reversibleExpense();
    ExpenseLine::factory()->for($expense)->actual()->create(['amount' => '100.00']);
    ExpenseLine::factory()->for($expense)->actual()->create(['amount' => '-100.00', 'note' => 'Rimborso']);

    expect($expense->actual())->toBe('0.00')
        ->and($expense->hasActuals())->toBeTrue()
        ->and(fn () => app(SetExpenseReversed::class)->execute($actor, $expense, true, 'Storno', (string) Str::uuid()))
        ->toThrow(ValidationException::class)
        ->and(fn () => app(SetExpenseReversed::class)->execute($actor, $expense, true, ' ', (string) Str::uuid()))
        ->toThrow(ValidationException::class)
        ->and(AuditEvent::query()->count())->toBe(0);
});

it('does not append an event for a current-state request', function () {
    [$actor, , $expense] = reversibleExpense();
    app(SetExpenseReversed::class)->execute($actor, $expense, false, 'Già attiva', (string) Str::uuid());

    expect(AuditEvent::query()->count())->toBe(0);
});
