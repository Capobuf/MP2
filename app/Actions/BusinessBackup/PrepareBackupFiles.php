<?php

namespace App\Actions\BusinessBackup;

use App\BusinessBackup\BusinessBackupBundle;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use ZipArchive;

final class PrepareBackupFiles
{
    /** @param array<string, mixed> $package
     * @return array<string, array{disk: string, path: string, media_type: ?string}>
     */
    public function execute(array $package): array
    {
        if (! isset($package['bundle'])) {
            return [];
        }
        if (! hash_equals($package['upload_sha256'], hash_file('sha256', $package['bundle_path']))) {
            throw ValidationException::withMessages(['backup' => 'Il package è cambiato dopo la validazione.']);
        }
        $zip = new ZipArchive;
        if ($zip->open($package['bundle_path']) !== true) {
            throw new \RuntimeException('Il package non è più leggibile.');
        }
        $files = [];
        try {
            foreach ($package['bundle']['files'] as $file) {
                $relative = 'business-backup-restores/'.Str::uuid();
                $files[$file['kind'].':'.$file['ref']] = ['disk' => 'local', 'path' => $relative, 'media_type' => $file['media_type']];
                Storage::disk('local')->makeDirectory('business-backup-restores');
                app(BusinessBackupBundle::class)->readEntry($zip, $file, Storage::disk('local')->path($relative));
            }

            return $files;
        } catch (\Throwable $exception) {
            $this->cleanup($files);
            throw $exception;
        } finally {
            $zip->close();
        }
    }

    /** @param array<string, array{disk: string, path: string, media_type: ?string}> $files */
    public function cleanup(array $files): void
    {
        foreach ($files as $file) {
            Storage::disk($file['disk'])->delete($file['path']);
        }
    }
}
