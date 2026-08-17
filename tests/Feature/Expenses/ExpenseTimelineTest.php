<?php

use App\Actions\Operations\CreateExpense;
use App\Actions\Operations\CreateExpenseLine;
use App\Domain\Company\Capability;
use App\Models\AuditEvent;
use App\Models\Company;
use App\Models\CompanyCapability;
use App\Models\Exercise;
use App\Models\Expense;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

it('records one complete company event for every real S3 command', function () {
    $actor = User::factory()->create();
    $company = Company::factory()->create(['timezone' => 'Europe/Rome']);
    foreach ([Capability::View, Capability::ManageOperations] as $capability) {
        CompanyCapability::query()->create(['company_id' => $company->id, 'user_id' => $actor->id, 'capability' => $capability]);
    }
    $exercise = Exercise::factory()->for($company)->create(['year' => now('Europe/Rome')->year]);
    $expense = app(CreateExpense::class)->execute($actor, $company, [
        'exercise_id' => $exercise->id,
        'description' => 'Timeline',
        'lines' => [['type' => 'estimate', 'amount' => '10.00']],
    ], (string) Str::uuid());
    app(CreateExpenseLine::class)->execute($actor, $expense, [
        'type' => 'actual',
        'amount' => '5.00',
    ], (string) Str::uuid());

    $events = AuditEvent::query()->orderBy('id')->get();
    expect($events)->toHaveCount(2);
    foreach ($events as $event) {
        expect($event->company_id)->toBe($company->id)
            ->and($event->actor_id)->toBe($actor->id)
            ->and($event->operation_id)->not->toBeNull()
            ->and($event->affected_exercise_ids)->toBe([$exercise->id])
            ->and($event->effective_from->toDateString())->toBe(now('Europe/Rome')->toDateString())
            ->and($event->new_value)->toBeArray()
            ->and($event->allocated_impact_by_exercise)->toHaveKey((string) $exercise->id)
            ->and($event->actual_impact_by_exercise)->toHaveKey((string) $exercise->id);
    }
});

it('keeps S3 events append only', function () {
    $event = AuditEvent::withoutEvents(fn () => AuditEvent::query()->create([
        'operation_id' => (string) Str::uuid(),
        'company_id' => Company::factory()->create()->id,
        'actor_id' => User::factory()->create()->id,
        'event_type' => 'expense_created',
        'subject_type' => Expense::class,
        'subject_id' => 1,
        'affected_exercise_ids' => [],
        'effective_from' => now()->toDateString(),
        'allocated_impact_by_exercise' => [],
        'actual_impact_by_exercise' => [],
    ]));

    expect(fn () => $event->update(['reason' => 'No']))->toThrow(LogicException::class)
        ->and(fn () => $event->delete())->toThrow(LogicException::class);
});
