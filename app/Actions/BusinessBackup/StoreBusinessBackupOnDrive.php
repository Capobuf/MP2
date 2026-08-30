<?php

namespace App\Actions\BusinessBackup;

use App\Models\Company;
use App\Models\User;
use Illuminate\Support\Facades\Storage;

final class StoreBusinessBackupOnDrive
{
    public function __construct(private readonly ExportBusinessBackup $export) {}

    public static function configured(): bool
    {
        $disk = config('filesystems.disks.google');

        return is_array($disk)
            && filled($disk['clientId'] ?? null)
            && filled($disk['clientSecret'] ?? null)
            && (filled($disk['refreshToken'] ?? null) || filled($disk['accessToken'] ?? null));
    }

    public function execute(Company $company, User $actor): string
    {
        if (! self::configured()) {
            throw new \RuntimeException('Google Drive non è configurato.');
        }

        return $this->store($this->export->execute($company, $actor));
    }

    /** @param array{path: string, filename: string, package_id: string} $artifact */
    public function store(array $artifact): string
    {
        if (! self::configured()) {
            throw new \RuntimeException('Google Drive non è configurato.');
        }
        $stream = null;

        try {
            $stream = fopen($artifact['path'], 'rb');
            if ($stream === false || ! Storage::disk('google')->writeStream($artifact['filename'], $stream)) {
                throw new \RuntimeException('Scrittura del backup su Google Drive non riuscita.');
            }

            return $artifact['filename'];
        } finally {
            if (is_resource($stream)) {
                fclose($stream);
            }
            @unlink($artifact['path']);
        }
    }
}
