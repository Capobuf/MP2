<?php

use App\Domain\Company\AuditEventType;
use App\Models\AuditEvent;
use App\Models\Company;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

it('stores several deterministically ordered events for one operation', function () {
    $company = Company::factory()->create();
    $actor = User::factory()->create();
    $operationId = (string) Str::uuid();

    foreach ([0, 1, 2] as $sequence) {
        AuditEvent::query()->create([
            'company_id' => $company->id,
            'operation_id' => $operationId,
            'event_sequence' => $sequence,
            'actor_id' => $actor->id,
            'event_type' => AuditEventType::ContractCreated,
            'subject_type' => 'contract',
            'subject_id' => 123,
            'affected_exercise_ids' => [],
            'effective_from' => '2026-01-01',
            'allocated_impact_by_exercise' => [],
            'actual_impact_by_exercise' => [],
        ]);
    }

    expect(AuditEvent::query()
        ->where('operation_id', $operationId)
        ->orderBy('event_sequence')
        ->pluck('event_sequence')
        ->all())->toBe([0, 1, 2]);
});

it('keeps sequence zero as the default for legacy actions and rejects duplicate sequences', function () {
    $company = Company::factory()->create();
    $actor = User::factory()->create();
    $operationId = (string) Str::uuid();
    $attributes = [
        'company_id' => $company->id,
        'operation_id' => $operationId,
        'actor_id' => $actor->id,
        'event_type' => AuditEventType::ExerciseCreated,
        'subject_type' => 'exercise',
        'subject_id' => 456,
        'affected_exercise_ids' => [],
        'effective_from' => '2026-01-01',
        'allocated_impact_by_exercise' => [],
        'actual_impact_by_exercise' => [],
    ];

    $event = AuditEvent::query()->create($attributes);

    expect($event->event_sequence)->toBe(0);
    expect(fn () => AuditEvent::query()->create($attributes))->toThrow(QueryException::class);
});
