<?php

use App\Actions\LateCorrections\RecordLateCorrection;
use App\Domain\Company\AuditEventType;
use App\Domain\Company\Capability;
use App\Models\AuditEvent;
use App\Models\ClosingSnapshot;
use App\Models\Company;
use App\Models\CompanyCapability;
use App\Models\Exercise;
use App\Models\Expense;
use App\Models\LateCorrection;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

function s10PersistenceFixture(): array
{
    $company = Company::factory()->create();
    $actor = User::factory()->create();
    CompanyCapability::query()->create(['company_id' => $company->id, 'user_id' => $actor->id, 'capability' => Capability::CorrectClosedExercise]);
    $exercise = Exercise::factory()->for($company)->create(['year' => 2025]);
    $expense = Expense::factory()->forExercise($exercise)->create();
    $line = $expense->lines()->create(['type' => 'actual', 'amount' => '100.00', 'note' => null]);
    $snapshot = closeExerciseFixture($exercise, $actor);

    return compact('company', 'actor', 'exercise', 'expense', 'line', 'snapshot');
}

function s10PersistenceInput(Exercise $exercise, Expense $expense): array
{
    $exercise->refresh();
    $expense->refresh();

    return [
        'source_type' => 'expense',
        'source_origin_id' => $expense->id,
        'source_origin_key' => $expense->originKey(),
        'source_label' => $expense->description,
        'owner_context' => ['container' => 'autonomous'],
        'historical_expense_id' => $expense->id,
        'amount' => '25.00',
        'reason' => 'Motivo',
        'belongs_to_closed_exercise' => true,
        'expected_exercise_revision' => $exercise->revision,
        'expected_source_revision' => $expense->revision,
        'expected_expense_revision' => $expense->revision,
    ];
}

it('persists the closed context, typed receipt and exact generated Actual line', function (): void {
    $fixture = s10PersistenceFixture();
    $operationId = (string) Str::uuid();
    $correction = app(RecordLateCorrection::class)->execute(
        $fixture['actor'],
        $fixture['exercise'],
        s10PersistenceInput($fixture['exercise'], $fixture['expense']),
        $operationId,
    );

    expect($correction->company_id)->toBe($fixture['company']->id)
        ->and($correction->exercise_id)->toBe($fixture['exercise']->id)
        ->and($correction->closing_snapshot_id)->toBe($fixture['snapshot']->id)
        ->and($correction->expense_id)->toBe($fixture['expense']->id)
        ->and($correction->expenseLine->lineType()->value)->toBe('actual')
        ->and($correction->expenseLine->amount)->toBe('25.00')
        ->and($correction->belongs_to_closed_exercise)->toBeTrue()
        ->and(AuditEvent::query()->where('operation_id', $operationId)->sole()->eventType())
        ->toBe(AuditEventType::LateCorrectionRecorded);
});

it('rejects updates and physical deletion of a correction', function (): void {
    $fixture = s10PersistenceFixture();
    $correction = app(RecordLateCorrection::class)->execute(
        $fixture['actor'],
        $fixture['exercise'],
        s10PersistenceInput($fixture['exercise'], $fixture['expense']),
        (string) Str::uuid(),
    );

    expect(fn () => $correction->update(['reason' => 'riscrittura']))->toThrow(LogicException::class)
        ->and(fn () => $correction->delete())->toThrow(LogicException::class)
        ->and(LateCorrection::query()->whereKey($correction->id)->exists())->toBeTrue();
});

it('builds a valid immutable correction factory context with a Closing Snapshot', function (): void {
    $correction = LateCorrection::factory()->create();

    expect($correction->exercise->isOpen())->toBeFalse()
        ->and($correction->closingSnapshot->exercise_id)->toBe($correction->exercise_id)
        ->and($correction->closingSnapshot->company_id)->toBe($correction->company_id)
        ->and($correction->expenseLine->expense_id)->toBe($correction->expense_id)
        ->and($correction->expenseLine->lineType()->value)->toBe('actual');
});
it('forExercise reuses the canonical Closing Snapshot', function (): void {
    $fixture = s10PersistenceFixture();
    $correction = LateCorrection::factory()->forExercise($fixture['exercise'])->create();

    expect($correction->closing_snapshot_id)->toBe($fixture['snapshot']->id)
        ->and(ClosingSnapshot::query()->where('exercise_id', $fixture['exercise']->id)->count())->toBe(1);
});
