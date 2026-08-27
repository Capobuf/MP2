<?php

use App\Actions\Closing\CloseExercise;
use App\Actions\Closing\PrepareExerciseClosing;
use App\Domain\Company\AuditEventType;
use App\Domain\Company\Capability;
use App\Models\AuditEvent;
use App\Models\ClosingSnapshot;
use App\Models\Company;
use App\Models\CompanyCapability;
use App\Models\Exercise;
use App\Models\Expense;
use App\Models\ExpenseLine;
use App\Models\Project;
use App\Models\ProjectDeferral;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

afterEach(fn () => CarbonImmutable::setTestNow());

it('rolls back all economic effects if Snapshot materialization fails', function (): void {
    CarbonImmutable::setTestNow('2026-08-23 12:00:00 Europe/Rome');
    $company = Company::factory()->create();
    $actor = User::factory()->create();
    foreach ([Capability::View, Capability::CloseExercise] as $capability) {
        CompanyCapability::query()->create(['company_id' => $company->id, 'user_id' => $actor->id, 'capability' => $capability]);
    }
    $exercise = Exercise::factory()->for($company)->create(['year' => 2025]);
    $project = Project::factory()->for($company)->create([
        'initial_state' => 'open',
        'initial_effective_date' => '2025-01-01',
    ]);
    $expense = Expense::factory()->forExercise($exercise)->for($project)->create();
    ExpenseLine::factory()->for($expense)->create(['amount' => '100.00']);
    ExpenseLine::factory()->for($expense)->actual()->create(['amount' => '20.00']);
    $prepared = app(PrepareExerciseClosing::class)->execute($actor, $exercise, [
        'create_next_exercise' => true,
        'projects' => [$project->id => [
            'project_id' => $project->id,
            'final_state' => 'open',
            'mode' => 'carryover',
            'carryover_amount' => '50.00',
            'reason' => 'Riporto atomico',
        ]],
    ]);
    $operationId = (string) Str::uuid();
    $failOnce = true;
    ClosingSnapshot::creating(function () use (&$failOnce): void {
        if ($failOnce) {
            $failOnce = false;
            throw new RuntimeException('Closing checkpoint failure');
        }
    });

    $input = [
        ...$prepared['input'],
        'review_fingerprint' => $prepared['execution_fingerprint'],
        'warnings_acknowledged' => true,
        'confirmed' => true,
    ];
    expect(fn () => app(CloseExercise::class)->execute($actor, $exercise, $input, $operationId))
        ->toThrow(RuntimeException::class);

    expect($exercise->refresh()->isOpen())->toBeTrue()
        ->and(ClosingSnapshot::query()->where('exercise_id', $exercise->id)->exists())->toBeFalse()
        ->and(Exercise::query()->where('company_id', $company->id)->where('year', 2026)->exists())->toBeFalse()
        ->and(ProjectDeferral::query()->where('project_id', $project->id)->exists())->toBeFalse()
        ->and(AuditEvent::query()->where('operation_id', $operationId)->where('event_type', AuditEventType::ExerciseClosingStarted->value)->exists())->toBeTrue()
        ->and(AuditEvent::query()->where('operation_id', $operationId)->where('event_type', AuditEventType::ExerciseClosingFailed->value)->exists())->toBeTrue();

    $snapshot = app(CloseExercise::class)->execute($actor, $exercise->refresh(), $input, $operationId);
    $sequences = AuditEvent::query()
        ->where('operation_id', $operationId)
        ->orderBy('event_sequence')
        ->pluck('event_sequence')
        ->map(fn (mixed $sequence): int => (int) $sequence)
        ->all();

    expect($snapshot->exercise_id)->toBe($exercise->id)
        ->and(ClosingSnapshot::query()->where('exercise_id', $exercise->id)->count())->toBe(1)
        ->and(AuditEvent::query()->where('operation_id', $operationId)->where('event_type', AuditEventType::ExerciseClosingStarted->value)->count())->toBe(1)
        ->and(AuditEvent::query()->where('operation_id', $operationId)->where('event_type', AuditEventType::ExerciseClosingFailed->value)->count())->toBe(1)
        ->and($sequences)->toBe(range(0, max($sequences)));
});
