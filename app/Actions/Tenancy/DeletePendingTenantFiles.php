<?php

namespace App\Actions\Tenancy;

use App\Models\PendingFileDeletion;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Throwable;

class DeletePendingTenantFiles
{
    /** @return array{processed: int, completed: int, failed: int} */
    public function execute(?string $operationId = null): array
    {
        $query = PendingFileDeletion::query()->orderBy('id');
        if ($operationId !== null) {
            $query->where('operation_id', $operationId);
        }

        $result = ['processed' => 0, 'completed' => 0, 'failed' => 0];

        foreach ($query->pluck('id') as $id) {
            DB::transaction(function () use ($id, &$result): void {
                $pending = PendingFileDeletion::query()->lockForUpdate()->find($id);
                if (! $pending instanceof PendingFileDeletion) {
                    return;
                }

                $result['processed']++;

                try {
                    $disk = $pending->getAttribute('storage_disk');
                    $path = $pending->getAttribute('storage_path');
                    if (! is_string($disk) || trim($disk) === '' || ! is_string($path) || trim($path) === '') {
                        throw new \UnexpectedValueException('Invalid storage reference.');
                    }

                    $storage = Storage::disk($disk);
                    if (! $storage->exists($path) || $storage->delete($path)) {
                        $pending->delete();
                        $result['completed']++;

                        return;
                    }

                    $this->recordFailure($pending, 'Storage deletion returned false.');
                    $result['failed']++;
                } catch (Throwable $exception) {
                    $this->recordFailure($pending, 'Storage cleanup failed: '.class_basename($exception));
                    $result['failed']++;
                }
            });
        }

        return $result;
    }

    private function recordFailure(PendingFileDeletion $pending, string $error): void
    {
        $pending->update([
            'attempts' => $pending->getAttribute('attempts') + 1,
            'last_attempted_at' => now(),
            'last_error' => $error,
        ]);
    }
}
