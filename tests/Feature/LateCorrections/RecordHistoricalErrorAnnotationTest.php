<?php

use App\Actions\LateCorrections\RecordHistoricalErrorAnnotation;
use App\Domain\Company\AuditEventType;
use App\Models\AuditEvent;
use App\Models\BudgetSnapshot;
use App\Models\Company;
use App\Models\Exercise;
use App\Models\HistoricalErrorAnnotation;
use App\Models\Project;
use App\Models\ProjectDeferral;
use App\Models\ProjectExerciseClassification;
use App\Models\Proposal;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Tests\Support\TestPermissions;

uses(RefreshDatabase::class);
/** @return array<string, mixed> */
function annotationActionFixture(bool $grant = true): array
{
    $company = Company::factory()->create();
    $actor = User::factory()->create();
    if ($grant) {
        grantTestPermissions([
            'company_id' => $company->id,
            'user' => $actor,
            'permissions' => TestPermissions::CORRECT_CLOSED_EXERCISE,
        ]);
    }
    $exercise = Exercise::factory()->for($company)->create(['year' => 2025]);
    $nextExercise = Exercise::factory()->for($company)->create(['year' => 2026]);
    $project = Project::factory()->for($company)->create(['initial_effective_date' => '2025-01-01']);
    $classification = ProjectExerciseClassification::factory()->forProjectAndExercise($project, $exercise)->create();
    $deferral = ProjectDeferral::factory()->for($project)->for($exercise, 'sourceExercise')->for($nextExercise, 'destinationExercise')->carryover('100.00')->state(['carryover_state' => 'consolidated'])->create([
        'company_id' => $company->id,
    ]);
    $proposal = Proposal::factory()->for($company)->for($exercise)->create([
        'status' => 'approved',
        'approved_by_id' => $actor->id,
        'approved_at' => now(),
    ]);
    $budget = BudgetSnapshot::factory()->for($proposal)->create([
        'company_id' => $company->id,
        'exercise_id' => $exercise->id,
        'approved_by_id' => $actor->id,
    ]);
    $snapshot = closeExerciseFixture($exercise, $actor);
    $exercise->refresh();

    return compact('company', 'actor', 'exercise', 'snapshot', 'nextExercise', 'project', 'classification', 'deferral', 'budget');
}

/** @param array<string, mixed> $overrides
 * @return array<string, mixed>
 */
function annotationActionInput(Exercise $exercise, array $overrides = []): array
{
    $exercise->refresh();

    return [
        'kind' => 'carryover',
        'reason' => 'Il Riporto storico richiede evidenza',
        'recorded_facts' => ['carryover' => '100.00'],
        'believed_correct_facts' => ['carryover' => '0.00'],
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

it('requires facts, reason and a current closed context before any write', function (): void {
    $fixture = annotationActionFixture();

    expect(fn () => app(RecordHistoricalErrorAnnotation::class)->execute(
        $fixture['actor'],
        $fixture['exercise'],
        annotationActionInput($fixture['exercise'], ['reason' => '']),
        (string) Str::uuid(),
    ))->toThrow(ValidationException::class)
        ->and(fn () => app(RecordHistoricalErrorAnnotation::class)->execute(
            $fixture['actor'],
            $fixture['exercise'],
            annotationActionInput($fixture['exercise'], ['affected_sources' => []]),
            (string) Str::uuid(),
        ))->toThrow(ValidationException::class)
        ->and(HistoricalErrorAnnotation::query()->count())->toBe(0);
});

it('rejects missing capability, cross-company access and stale Exercise revision atomically', function (): void {
    $fixture = annotationActionFixture(false);
    expect(fn () => app(RecordHistoricalErrorAnnotation::class)->execute(
        $fixture['actor'],
        $fixture['exercise'],
        annotationActionInput($fixture['exercise']),
        (string) Str::uuid(),
    ))->toThrow(AuthorizationException::class)
        ->and(HistoricalErrorAnnotation::query()->count())->toBe(0);

    $authorized = annotationActionFixture();
    $authorized['exercise']->increment('revision');
    expect(fn () => app(RecordHistoricalErrorAnnotation::class)->execute(
        $authorized['actor'],
        $authorized['exercise'],
        annotationActionInput($authorized['exercise'], ['expected_exercise_revision' => 0]),
        (string) Str::uuid(),
    ))->toThrow(ValidationException::class)
        ->and(HistoricalErrorAnnotation::query()->count())->toBe(0);

    $foreign = annotationActionFixture();
    expect(fn () => app(RecordHistoricalErrorAnnotation::class)->execute(
        $authorized['actor'],
        $foreign['exercise'],
        annotationActionInput($foreign['exercise']),
        (string) Str::uuid(),
    ))->toThrow(AuthorizationException::class)
        ->and(HistoricalErrorAnnotation::query()->where('company_id', $foreign['company']->id)->count())->toBe(0);
});
it('does not change Budget, Carryover, state or classification while recording an annotation', function (): void {
    $fixture = annotationActionFixture();
    $exerciseRevision = $fixture['exercise']->revision;
    $snapshotValues = $fixture['snapshot']->only([
        'total_final_allocation',
        'total_closing_actual',
        'total_operational_variance',
        'total_consolidated_carryover',
    ]);
    $budgetValues = $fixture['budget']->only(['company_id', 'exercise_id', 'version', 'purpose', 'previous_budget_id', 'total_approved_allocation', 'affected_exercises']);
    $deferralValues = $fixture['deferral']->only(['company_id', 'project_id', 'source_exercise_id', 'destination_exercise_id', 'mode', 'carryover_amount', 'carryover_state']);
    $projectValues = [
        'company_id' => $fixture['project']->company_id,
        'title' => $fixture['project']->title,
        'initial_state' => $fixture['project']->initialState()->value,
        'initial_effective_date' => $fixture['project']->initialEffectiveDate()->toDateString(),
        'archived_at' => $fixture['project']->archivedAt()?->toDateTimeString(),
        'revision' => $fixture['project']->revision,
    ];
    $classificationValues = $fixture['classification']->only(['company_id', 'project_id', 'exercise_id', 'cost_center_id']);
    $annotation = app(RecordHistoricalErrorAnnotation::class)->execute(
        $fixture['actor'],
        $fixture['exercise'],
        annotationActionInput($fixture['exercise']),
        (string) Str::uuid(),
    );
    $project = $fixture['project']->refresh();
    $projectObserved = [
        'company_id' => $project->company_id,
        'title' => $project->title,
        'initial_state' => $project->initialState()->value,
        'initial_effective_date' => $project->initialEffectiveDate()->toDateString(),
        'archived_at' => $project->archivedAt()?->toDateTimeString(),
        'revision' => $project->revision,
    ];

    expect($annotation->exists)->toBeTrue()
        ->and($fixture['exercise']->refresh()->revision)->toBe($exerciseRevision)
        ->and($fixture['exercise']->status()->value)->toBe('closed')
        ->and($fixture['snapshot']->refresh()->only(array_keys($snapshotValues)))->toBe($snapshotValues)
        ->and($fixture['budget']->refresh()->only(array_keys($budgetValues)))->toBe($budgetValues)
        ->and($fixture['deferral']->refresh()->only(array_keys($deferralValues)))->toBe($deferralValues)
        ->and($projectObserved)->toBe($projectValues)
        ->and($fixture['classification']->refresh()->only(array_keys($classificationValues)))->toBe($classificationValues)
        ->and($fixture['exercise']->expenses()->count())->toBe(0);
});

it('rejects arbitrary or stale sources and materializes authoritative references', function (): void {
    $fixture = annotationActionFixture();

    expect(fn () => app(RecordHistoricalErrorAnnotation::class)->execute(
        $fixture['actor'],
        $fixture['exercise'],
        annotationActionInput($fixture['exercise'], [
            'affected_sources' => [[
                'type' => 'invented_source',
                'id' => $fixture['exercise']->id,
                'revision' => $fixture['exercise']->revision,
            ]],
        ]),
        (string) Str::uuid(),
    ))->toThrow(ValidationException::class);

    $annotation = app(RecordHistoricalErrorAnnotation::class)->execute(
        $fixture['actor'],
        $fixture['exercise'],
        annotationActionInput($fixture['exercise'], [
            'affected_sources' => [[
                'type' => 'exercise',
                'id' => $fixture['exercise']->id,
                'revision' => $fixture['exercise']->revision,
                'origin_key' => 'exercise:999999',
                'label' => 'Etichetta falsificata',
            ]],
        ]),
        (string) Str::uuid(),
    );
    $affectedSource = $annotation->getAttribute('affected_sources')[0];
    expect($affectedSource['origin_key'])->toBe('exercise:'.$fixture['exercise']->id)
        ->and($affectedSource['label'])->toBe('Esercizio '.$fixture['exercise']->year);

    $project = $fixture['project']->fresh();
    $staleInput = annotationActionInput($fixture['exercise'], [
        'affected_sources' => [[
            'type' => 'project',
            'id' => $project->id,
            'revision' => $project->revision,
        ]],
    ]);
    $project->increment('revision');
    expect(fn () => app(RecordHistoricalErrorAnnotation::class)->execute(
        $fixture['actor'],
        $fixture['exercise'],
        $staleInput,
        (string) Str::uuid(),
    ))->toThrow(ValidationException::class);
});

it('rejects direct annotation attempts on an Open Exercise', function (): void {
    $company = Company::factory()->create();
    $actor = User::factory()->create();
    grantTestPermissions([
        'company_id' => $company->id,
        'user' => $actor,
        'permissions' => TestPermissions::CORRECT_CLOSED_EXERCISE,
    ]);
    $exercise = Exercise::factory()->for($company)->create(['year' => 2025]);

    expect(fn () => app(RecordHistoricalErrorAnnotation::class)->execute(
        $actor,
        $exercise,
        annotationActionInput($exercise),
        (string) Str::uuid(),
    ))->toThrow(AuthorizationException::class)
        ->and(HistoricalErrorAnnotation::query()->count())->toBe(0);
});
it('rolls back the immutable annotation when the audit receipt fails', function (): void {
    $fixture = annotationActionFixture();
    $eventName = 'eloquent.creating: '.AuditEvent::class;
    Event::listen($eventName, function (AuditEvent $event): void {
        if ($event->eventType() === AuditEventType::HistoricalErrorAnnotationRecorded) {
            throw new RuntimeException('forced audit failure');
        }
    });

    try {
        expect(fn () => app(RecordHistoricalErrorAnnotation::class)->execute(
            $fixture['actor'],
            $fixture['exercise'],
            annotationActionInput($fixture['exercise']),
            (string) Str::uuid(),
        ))->toThrow(RuntimeException::class);
    } finally {
        Event::forget($eventName);
    }

    expect(HistoricalErrorAnnotation::query()->count())->toBe(0)
        ->and(AuditEvent::query()->where('event_type', AuditEventType::HistoricalErrorAnnotationRecorded->value)->count())->toBe(0);
});
