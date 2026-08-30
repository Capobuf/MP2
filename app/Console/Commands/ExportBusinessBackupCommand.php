<?php

namespace App\Console\Commands;

use App\Actions\BusinessBackup\ExportBusinessBackup;
use App\Actions\BusinessBackup\StoreBusinessBackupOnDrive;
use App\Models\Company;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;

final class ExportBusinessBackupCommand extends Command
{
    protected $signature = 'business-backup:export {company : ID Azienda} {--drive : Salva sul disk Google Drive configurato} {--output= : Directory locale di destinazione}';

    protected $description = 'Esporta il Business Data Backup XLSX di un Tenant operativo';

    public function handle(ExportBusinessBackup $export): int
    {
        $stream = null;
        $company = Company::query()->find($this->argument('company'));
        if ($company === null) {
            $this->components->error('Azienda non trovata.');

            return self::FAILURE;
        }

        try {
            $artifact = $export->execute($company);
            if ($this->option('drive')) {
                if (! StoreBusinessBackupOnDrive::configured()) {
                    throw new \RuntimeException('Google Drive non è configurato.');
                }
                $stream = fopen($artifact['path'], 'rb');
                if ($stream === false || ! Storage::disk('google')->writeStream($artifact['filename'], $stream)) {
                    throw new \RuntimeException('Scrittura su Google Drive non riuscita.');
                }
                $destination = 'Google Drive/'.$artifact['filename'];
            } else {
                $directory = $this->option('output') ?: storage_path('app/private/business-backups/published');
                File::ensureDirectoryExists($directory);
                $destination = rtrim($directory, DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR.$artifact['filename'];
                if (! File::copy($artifact['path'], $destination)) {
                    throw new \RuntimeException('Copia locale del backup non riuscita.');
                }
            }
        } catch (\Throwable $exception) {
            $this->components->error($exception->getMessage());

            return self::FAILURE;
        } finally {
            if (is_resource($stream)) {
                fclose($stream);
            }
            if (isset($artifact['path'])) {
                @unlink($artifact['path']);
            }
        }

        $this->components->info("Backup creato: $destination");

        return self::SUCCESS;
    }
}
