<?php

use App\Actions\Operations\DetachAttachment;
use App\Actions\Operations\UploadAttachment;
use App\Domain\Company\AuditEventType;
use App\Models\Attachment;
use App\Models\AuditEvent;
use App\Models\Company;
use App\Models\Contract;
use App\Models\Exercise;
use App\Models\Expense;
use App\Models\ExpenseLine;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\Support\TestPermissions;

uses(RefreshDatabase::class);

beforeEach(fn () => Storage::fake('local'));

function attachmentContext(): array
{
    $actor = User::factory()->create();
    $company = Company::factory()->create();
    foreach ([TestPermissions::VIEW, TestPermissions::MANAGE_OPERATIONS] as $capability) {
        grantTestPermissions(['company_id' => $company->id, 'user' => $actor, 'permissions' => $capability]);
    }
    $contract = Contract::factory()->for($company)->create();
    $exercise = Exercise::factory()->for($company)->create(['year' => 2026]);
    $expense = Expense::factory()->forExercise($exercise)->create();
    $line = ExpenseLine::factory()->for($expense)->create();

    return compact('actor', 'company', 'contract', 'expense', 'line');
}

it('uploads immutable private versions for Contract Expense and Line with checksum metadata and exact owner', function () {
    ['actor' => $actor, 'contract' => $contract, 'expense' => $expense, 'line' => $line] = attachmentContext();
    $owners = [
        [$contract, 'contratto.txt', 'contratto', 'contract_id'],
        [$expense, 'spesa.txt', 'spesa', 'expense_id'],
        [$line, 'riga.txt', 'riga', 'expense_line_id'],
    ];

    foreach ($owners as [$owner, $name, $contents, $ownerKey]) {
        $attachment = app(UploadAttachment::class)->execute(
            $actor,
            $owner,
            UploadedFile::fake()->createWithContent($name, $contents),
            (string) Str::uuid(),
        );

        expect($attachment->{$ownerKey})->toBe($owner->id)
            ->and($attachment->storage_disk)->toBe('local')
            ->and($attachment->original_name)->toBe($name)
            ->and($attachment->size_bytes)->toBe(strlen($contents))
            ->and($attachment->sha256)->toBe(hash('sha256', $contents));
        Storage::disk('local')->assertExists($attachment->storage_path);
    }

    expect(Attachment::query()->count())->toBe(3)
        ->and(Attachment::query()->distinct()->count('storage_path'))->toBe(3)
        ->and(AuditEvent::query()->where('event_type', AuditEventType::AttachmentUploaded)->count())->toBe(3);
});

it('downloads only after authenticated same-company authorization and does not disclose guessed IDs', function () {
    ['actor' => $actor, 'contract' => $contract] = attachmentContext();
    $attachment = app(UploadAttachment::class)->execute(
        $actor,
        $contract,
        UploadedFile::fake()->createWithContent('evidenza.txt', 'contenuto riservato'),
        (string) Str::uuid(),
    );

    $this->get(route('attachments.download', $attachment))->assertRedirect();
    $this->actingAs($actor)->get(route('attachments.download', $attachment))
        ->assertOk()->assertDownload('evidenza.txt');

    $other = User::factory()->create();
    $this->actingAs($other)->get(route('attachments.download', $attachment))->assertNotFound();
    $this->actingAs($actor)->get('/attachments/999999/download')->assertNotFound();
});

it('streams a PDF inline only after authenticated same-company authorization', function () {
    ['actor' => $actor, 'contract' => $contract] = attachmentContext();
    $attachment = app(UploadAttachment::class)->execute(
        $actor,
        $contract,
        UploadedFile::fake()->create('evidenza.pdf', 10, 'application/pdf'),
        (string) Str::uuid(),
    );

    $this->get(route('attachments.preview', $attachment))->assertRedirect();
    $response = $this->actingAs($actor)->get(route('attachments.preview', $attachment));
    $response
        ->assertOk()
        ->assertHeader('content-type', 'application/pdf')
        ->assertHeader('x-content-type-options', 'nosniff')
        ->assertHeader('content-disposition', 'inline; filename=evidenza.pdf');
    expect($response->headers->hasCacheControlDirective('private'))->toBeTrue()
        ->and($response->headers->hasCacheControlDirective('no-store'))->toBeTrue();

    $other = User::factory()->create();
    $this->actingAs($other)->get(route('attachments.preview', $attachment))->assertNotFound();

    $nonPdf = app(UploadAttachment::class)->execute(
        $actor,
        $contract,
        UploadedFile::fake()->createWithContent('note.txt', 'testo'),
        (string) Str::uuid(),
    );
    $this->actingAs($actor)->get(route('attachments.preview', $nonPdf))->assertNotFound();

    Storage::disk('local')->delete($attachment->storage_path);
    $this->actingAs($actor)->get(route('attachments.preview', $attachment))->assertNotFound();
});

it('retries upload idempotently and replacement uses a new row and immutable blob', function () {
    ['actor' => $actor, 'contract' => $contract] = attachmentContext();
    $operationId = (string) Str::uuid();
    $first = app(UploadAttachment::class)->execute($actor, $contract, UploadedFile::fake()->createWithContent('v1.txt', 'prima'), $operationId);
    $retry = app(UploadAttachment::class)->execute($actor, $contract, UploadedFile::fake()->createWithContent('ignored.txt', 'diversa'), $operationId);
    $second = app(UploadAttachment::class)->execute($actor, $contract, UploadedFile::fake()->createWithContent('v2.txt', 'seconda'), (string) Str::uuid());

    expect($retry->is($first))->toBeTrue()
        ->and($second->id)->not->toBe($first->id)
        ->and($second->storage_path)->not->toBe($first->storage_path)
        ->and(Attachment::query()->count())->toBe(2);
    Storage::disk('local')->assertExists($first->storage_path);
    Storage::disk('local')->assertExists($second->storage_path);
});

it('detaches logically and idempotently while retaining the row and private blob', function () {
    ['actor' => $actor, 'contract' => $contract] = attachmentContext();
    $attachment = app(UploadAttachment::class)->execute($actor, $contract, UploadedFile::fake()->createWithContent('prova.txt', 'prova'), (string) Str::uuid());
    $operationId = (string) Str::uuid();

    $detached = app(DetachAttachment::class)->execute($actor, $attachment, $operationId);
    $retry = app(DetachAttachment::class)->execute($actor, $attachment, $operationId);

    expect($retry->is($detached))->toBeTrue()
        ->and($detached->isDetached())->toBeTrue()
        ->and($detached->detached_by_id)->toBe($actor->id)
        ->and(Attachment::query()->whereKey($attachment->id)->exists())->toBeTrue()
        ->and(AuditEvent::query()->orderBy('id')->pluck('event_type')->all())->toBe([
            AuditEventType::AttachmentUploaded,
            AuditEventType::AttachmentDetached,
        ]);
    Storage::disk('local')->assertExists($attachment->storage_path);
});

it('retains Expense and Line attachment events in the Contract Timeline after ownership movement', function () {
    ['actor' => $actor, 'contract' => $contract, 'expense' => $autonomous] = attachmentContext();
    $expense = Expense::factory()->forExercise($autonomous->exercise)->for($contract)->create([
        'origin' => 'manual',
        'direct_cost_center_id' => null,
    ]);
    $line = ExpenseLine::factory()->for($expense)->actual()->create();
    $expenseAttachment = app(UploadAttachment::class)->execute(
        $actor,
        $expense,
        UploadedFile::fake()->createWithContent('spesa.txt', 'spesa'),
        (string) Str::uuid(),
    );
    $lineAttachment = app(UploadAttachment::class)->execute(
        $actor,
        $line,
        UploadedFile::fake()->createWithContent('riga.txt', 'riga'),
        (string) Str::uuid(),
    );
    app(DetachAttachment::class)->execute($actor, $expenseAttachment, (string) Str::uuid());
    app(DetachAttachment::class)->execute($actor, $lineAttachment, (string) Str::uuid());

    $expense->update(['contract_id' => null]);

    expect(AuditEvent::query()->forContract($contract)->orderBy('id')->pluck('event_type')->all())->toBe([
        AuditEventType::AttachmentUploaded,
        AuditEventType::AttachmentUploaded,
        AuditEventType::AttachmentDetached,
        AuditEventType::AttachmentDetached,
    ]);
});

it('removes only an uncommitted upload blob and rolls detachment back if Timeline persistence fails', function () {
    ['actor' => $actor, 'contract' => $contract] = attachmentContext();
    AuditEvent::creating(fn () => throw new RuntimeException('Forced upload audit failure'));

    expect(fn () => app(UploadAttachment::class)->execute(
        $actor,
        $contract,
        UploadedFile::fake()->createWithContent('fallisce.txt', 'fallisce'),
        (string) Str::uuid(),
    ))->toThrow(RuntimeException::class)
        ->and(Attachment::query()->count())->toBe(0)
        ->and(Storage::disk('local')->allFiles())->toBe([]);
    AuditEvent::flushEventListeners();

    $attachment = app(UploadAttachment::class)->execute($actor, $contract, UploadedFile::fake()->createWithContent('resta.txt', 'resta'), (string) Str::uuid());
    AuditEvent::creating(fn () => throw new RuntimeException('Forced detach audit failure'));
    expect(fn () => app(DetachAttachment::class)->execute($actor, $attachment, (string) Str::uuid()))
        ->toThrow(RuntimeException::class)
        ->and($attachment->refresh()->isDetached())->toBeFalse();
    Storage::disk('local')->assertExists($attachment->storage_path);
    AuditEvent::flushEventListeners();
});
