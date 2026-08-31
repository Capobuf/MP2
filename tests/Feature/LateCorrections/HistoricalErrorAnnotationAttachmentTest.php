<?php

use App\Actions\LateCorrections\RecordHistoricalErrorAnnotation;
use App\Actions\Operations\DetachAttachment;
use App\Actions\Operations\UploadAttachment;
use App\Models\Attachment;
use App\Models\Company;
use App\Models\Exercise;
use App\Models\HistoricalErrorAnnotation;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\Support\TestPermissions;

uses(RefreshDatabase::class);

function annotationAttachmentFixture(): array
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
    $annotation = app(RecordHistoricalErrorAnnotation::class)->execute($actor, $exercise, [
        'kind' => 'supplier',
        'reason' => 'Il Fornitore storico era errato',
        'recorded_facts' => ['supplier_id' => 1],
        'believed_correct_facts' => ['supplier_id' => 2],
        'affected_sources' => [[
            'type' => 'exercise',
            'id' => $exercise->id,
            'revision' => $exercise->revision,
            'origin_key' => 'exercise:'.$exercise->id,
            'label' => 'Esercizio '.$exercise->year,
        ]],
        'expected_exercise_revision' => $exercise->revision,
    ], (string) Str::uuid());

    return compact('company', 'actor', 'exercise', 'snapshot', 'annotation');
}

it('stores annotation evidence under the immutable annotation owner and retains it on retry', function (): void {
    Storage::fake('local');
    $fixture = annotationAttachmentFixture();
    $operationId = (string) Str::uuid();

    $attachment = app(UploadAttachment::class)->execute(
        $fixture['actor'],
        $fixture['annotation'],
        UploadedFile::fake()->create('evidenza.pdf', 10, 'application/pdf'),
        $operationId,
    );
    $retry = app(UploadAttachment::class)->execute(
        $fixture['actor'],
        $fixture['annotation'],
        UploadedFile::fake()->create('different.pdf', 10, 'application/pdf'),
        $operationId,
    );

    expect($retry->id)->toBe($attachment->id)
        ->and($attachment->historical_error_annotation_id)->toBe($fixture['annotation']->id)
        ->and($attachment->expense_line_id)->toBeNull()
        ->and($attachment->isDetached())->toBeFalse()
        ->and(Attachment::query()->where('historical_error_annotation_id', $fixture['annotation']->id)->count())->toBe(1);
    Storage::disk('local')->assertExists($attachment->storage_path);
});

it('rejects detachment and cross-company annotation evidence upload', function (): void {
    Storage::fake('local');
    $fixture = annotationAttachmentFixture();
    $attachment = app(UploadAttachment::class)->execute(
        $fixture['actor'],
        $fixture['annotation'],
        UploadedFile::fake()->create('evidenza.pdf', 10, 'application/pdf'),
        (string) Str::uuid(),
    );

    expect(fn () => app(DetachAttachment::class)->execute($fixture['actor'], $attachment, (string) Str::uuid()))
        ->toThrow(AuthorizationException::class)
        ->and($attachment->refresh()->isDetached())->toBeFalse();
    expect(fn () => $attachment->update(['detached_at' => now(), 'detached_by_id' => $fixture['actor']->id]))
        ->toThrow(LogicException::class);

    $foreignActor = User::factory()->create();
    expect(fn () => app(UploadAttachment::class)->execute(
        $foreignActor,
        $fixture['annotation'],
        UploadedFile::fake()->create('foreign.pdf', 10, 'application/pdf'),
        (string) Str::uuid(),
    ))->toThrow(AuthorizationException::class)
        ->and(Attachment::query()->where('historical_error_annotation_id', $fixture['annotation']->id)->count())->toBe(1);

    expect($fixture['annotation']->attachments()->count())->toBe(1)
        ->and(HistoricalErrorAnnotation::query()->whereKey($fixture['annotation']->id)->exists())->toBeTrue();
});
