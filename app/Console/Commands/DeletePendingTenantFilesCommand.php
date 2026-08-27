<?php

namespace App\Console\Commands;

use App\Actions\Tenancy\DeletePendingTenantFiles;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

class DeletePendingTenantFilesCommand extends Command
{
    protected $signature = 'tenant-files:cleanup {--operation=}';

    protected $description = 'Completa la pulizia dei file appartenuti a Tenant eliminati';

    public function handle(DeletePendingTenantFiles $deletePendingTenantFiles): int
    {
        $operationId = $this->option('operation');
        if ($operationId !== null && ! Str::isUuid($operationId)) {
            $this->components->error('Identificativo operazione non valido.');

            return self::FAILURE;
        }

        $result = $deletePendingTenantFiles->execute($operationId);
        $this->components->info(
            "Elaborati: {$result['processed']}; completati: {$result['completed']}; falliti: {$result['failed']}.",
        );

        return $result['failed'] === 0 ? self::SUCCESS : self::FAILURE;
    }
}
