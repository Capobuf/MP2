<?php

use App\Actions\LateCorrections\RecordHistoricalErrorAnnotation;
use App\Domain\Company\AuditEventType;
use App\Domain\LateCorrections\HistoricalErrorKind;
use App\Models\AuditEvent;
use App\Models\Company;
use App\Models\Exercise;
use App\Models\HistoricalErrorAnnotation;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Tests\Support\TestPermissions;

uses(RefreshDatabase::class);

function annotationPersistenceFixture(): array
{
    $company = Company::factory()->create();
    $actor = User::factory()->create();
    grantTestPermissions([
        'company_id' => $company->id,
        'user' => $actor,
        'permissions' => TestPermissions::CORRECT_CLOSED_EXERCISE,
    ]);
    $exercise = Exercise::factory()->for($company)->create(['year' => 2025]);
    $snapshot = closeExerciseFixture($exercise, $actor);
    $exercise->refresh();

    return compact('company', 'actor', 'exercise', 'snapshot');
}

function annotationPersistenceInput(Exercise $exercise, array $overrides = []): array
{
    $exercise->refresh();

    return [
        'kind' => HistoricalErrorKind::CostCenter->value,
        'reason' => 'Centro di Costo storico errato',
        'recorded_facts' => ['id' => 1, 'label' => 'Registrato'],
        'believed_correct_facts' => ['id' => 2, 'label' => 'Corretto'],
        'affected_sources' => [[
            'type' => 'exercise',
            'id' => $exercise->id,
            'revision' => $exercise->revision,
            'origin_key' => 'exercise:'.$exercise->id,
            'label' => 'Esercizio '.$exercise->year,
        ]],
        'expected_exercise_revision' => $exercise->revision,
        ...$overrides,
    ];
}

it('persists every closed kind with versioned facts and a typed zero-impact receipt', function (): void {
    $fixture = annotationPersistenceFixture();

    foreach (HistoricalErrorKind::cases() as $kind) {
        $input = annotationPersistenceInput($fixture['exercise'], ['kind' => $kind->value]);
        $annotation = app(RecordHistoricalErrorAnnotation::class)->execute(
            $fixture['actor'],
            $fixture['exercise'],
            $input,
            (string) Str::uuid(),
        );

        expect($annotation->kind)->toBe($kind)
            ->and($annotation->recorded_facts_version)->toBe(1)
            ->and($annotation->believed_correct_facts_version)->toBe(1)
            ->and($annotation->affected_sources_version)->toBe(1)
            ->and($annotation->closing_snapshot_id)->toBe($fixture['snapshot']->id)
            ->and($annotation->exercise_id)->toBe($fixture['exercise']->id)
            ->and($annotation->company_id)->toBe($fixture['company']->id);
        $fixture['exercise']->refresh();
        $input['expected_exercise_revision'] = $fixture['exercise']->revision;
    }

    expect(AuditEvent::query()->where('event_type', AuditEventType::HistoricalErrorAnnotationRecorded->value)->count())->toBe(count(HistoricalErrorKind::cases()))
        ->and($fixture['exercise']->refresh()->revision)->toBe(0);
});

it('rejects invalid kinds, empty facts and immutable writes', function (): void {
    $fixture = annotationPersistenceFixture();

    expect(fn () => app(RecordHistoricalErrorAnnotation::class)->execute(
        $fixture['actor'],
        $fixture['exercise'],
        annotationPersistenceInput($fixture['exercise'], ['kind' => 'invented_kind']),
        (string) Str::uuid(),
    ))->toThrow(ValidationException::class)
        ->and(fn () => app(RecordHistoricalErrorAnnotation::class)->execute(
            $fixture['actor'],
            $fixture['exercise'],
            annotationPersistenceInput($fixture['exercise'], ['recorded_facts' => []]),
            (string) Str::uuid(),
        ))->toThrow(ValidationException::class);

    $annotation = HistoricalErrorAnnotation::factory()->forExercise($fixture['exercise'])->create();
    expect(fn () => $annotation->update(['reason' => 'riscrittura']))->toThrow(LogicException::class)
        ->and(fn () => $annotation->delete())->toThrow(LogicException::class);
});

it('returns the same immutable annotation for a successful operation retry', function (): void {
    $fixture = annotationPersistenceFixture();
    $operationId = (string) Str::uuid();
    $input = annotationPersistenceInput($fixture['exercise']);

    $annotation = app(RecordHistoricalErrorAnnotation::class)->execute($fixture['actor'], $fixture['exercise'], $input, $operationId);
    $retry = app(RecordHistoricalErrorAnnotation::class)->execute($fixture['actor'], $fixture['exercise'], $input, $operationId);

    expect($retry->id)->toBe($annotation->id)
        ->and(HistoricalErrorAnnotation::query()->where('operation_id', $operationId)->count())->toBe(1)
        ->and(AuditEvent::query()->where('operation_id', $operationId)->count())->toBe(1);
});
