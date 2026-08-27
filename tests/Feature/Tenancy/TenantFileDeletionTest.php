<?php

use App\Actions\Tenancy\DeletePendingTenantFiles;
use App\Models\PendingFileDeletion;
use Illuminate\Console\Command;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

it('stores a global unique file manifest with failure metadata and no Company foreign key', function (): void {
    $operationId = (string) Str::uuid();
    $pending = PendingFileDeletion::query()->create([
        'operation_id' => $operationId,
        'storage_disk' => 'local',
        'storage_path' => 'attachments/one.pdf',
        'attempts' => 2,
        'last_attempted_at' => now(),
        'last_error' => 'Storage deletion returned false.',
    ]);

    expect($pending->attempts)->toBe(2)
        ->and($pending->last_attempted_at)->not->toBeNull()
        ->and($pending->last_error)->toBe('Storage deletion returned false.')
        ->and(DB::table('information_schema.KEY_COLUMN_USAGE')
            ->where('CONSTRAINT_SCHEMA', DB::getDatabaseName())
            ->where('TABLE_NAME', 'pending_file_deletions')
            ->whereNotNull('REFERENCED_TABLE_NAME')
            ->count())->toBe(0);

    expect(fn () => PendingFileDeletion::query()->create([
        'operation_id' => (string) Str::uuid(),
        'storage_disk' => 'local',
        'storage_path' => 'attachments/one.pdf',
    ]))->toThrow(QueryException::class);
});

it('removes exact files and treats absent files and repeat cleanup as complete', function (): void {
    Storage::fake('tenant-cleanup');
    Storage::disk('tenant-cleanup')->put('attachments/existing.pdf', 'content');
    $operationId = (string) Str::uuid();

    foreach (['attachments/existing.pdf', 'attachments/absent.pdf'] as $path) {
        PendingFileDeletion::query()->create([
            'operation_id' => $operationId,
            'storage_disk' => 'tenant-cleanup',
            'storage_path' => $path,
        ]);
    }

    $first = app(DeletePendingTenantFiles::class)->execute($operationId);
    $second = app(DeletePendingTenantFiles::class)->execute($operationId);

    expect($first)->toBe(['processed' => 2, 'completed' => 2, 'failed' => 0])
        ->and($second)->toBe(['processed' => 0, 'completed' => 0, 'failed' => 0])
        ->and(PendingFileDeletion::query()->count())->toBe(0);
    Storage::disk('tenant-cleanup')->assertMissing('attachments/existing.pdf');
});

it('keeps sanitized retry metadata after false or exceptional storage deletion and later succeeds', function (string $disk, Throwable|bool $failure): void {
    $filesystem = Mockery::mock(Filesystem::class);
    $filesystem->shouldReceive('exists')->once()->andReturn(true);
    $deleteExpectation = $filesystem->shouldReceive('delete')->once();
    $failure instanceof Throwable
        ? $deleteExpectation->andThrow($failure)
        : $deleteExpectation->andReturn($failure);
    Storage::set($disk, $filesystem);

    $operationId = (string) Str::uuid();
    $pending = PendingFileDeletion::query()->create([
        'operation_id' => $operationId,
        'storage_disk' => $disk,
        'storage_path' => 'attachments/failure.pdf',
    ]);

    $failed = app(DeletePendingTenantFiles::class)->execute($operationId);
    $pending->refresh();

    expect($failed)->toBe(['processed' => 1, 'completed' => 0, 'failed' => 1])
        ->and($pending->attempts)->toBe(1)
        ->and($pending->last_attempted_at)->not->toBeNull()
        ->and($pending->last_error)->not->toContain('attachments/failure.pdf');

    Storage::fake($disk);
    Storage::disk($disk)->put('attachments/failure.pdf', 'content');
    $retried = app(DeletePendingTenantFiles::class)->execute($operationId);

    expect($retried)->toBe(['processed' => 1, 'completed' => 1, 'failed' => 0])
        ->and(PendingFileDeletion::query()->count())->toBe(0);
})->with([
    'delete returns false' => ['cleanup-false', false],
    'delete throws' => ['cleanup-exception', new RuntimeException('sensitive attachments/failure.pdf')],
]);

it('reports cleanup command failure and validates an optional operation UUID', function (): void {
    $filesystem = Mockery::mock(Filesystem::class);
    $filesystem->shouldReceive('exists')->once()->andReturn(true);
    $filesystem->shouldReceive('delete')->once()->andReturn(false);
    Storage::set('cleanup-command', $filesystem);

    $operationId = (string) Str::uuid();
    PendingFileDeletion::query()->create([
        'operation_id' => $operationId,
        'storage_disk' => 'cleanup-command',
        'storage_path' => 'attachments/pending.pdf',
    ]);

    expect(Artisan::call('tenant-files:cleanup', ['--operation' => $operationId]))->toBe(Command::FAILURE)
        ->and(Artisan::output())->toContain('Elaborati: 1; completati: 0; falliti: 1.')
        ->and(Artisan::call('tenant-files:cleanup', ['--operation' => 'invalid']))->toBe(Command::FAILURE);
});
