<?php

namespace App\Console\Commands;

use App\Actions\Operations\ProcessContractRenewals;
use App\Domain\Company\TenantCompanyStatus;
use App\Models\Contract;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

class ProcessContractRenewalsCommand extends Command
{
    protected $signature = 'contracts:process-renewals';

    protected $description = 'Elabora in modo idempotente le scadenze contrattuali maturate';

    public function handle(ProcessContractRenewals $process): int
    {
        $failed = 0;
        Contract::query()
            ->active()
            ->whereNotNull('next_expiry_date')
            ->whereHas('company.tenantCompany', fn ($query) => $query
                ->where('status', TenantCompanyStatus::Active->value))
            ->orderBy('company_id')
            ->orderBy('id')
            ->each(function (Contract $contract) use ($process, &$failed): void {
                $actor = User::permission('Update:Contract')
                    ->where(fn ($query) => $query
                        ->where('company_id', $contract->company_id)
                        ->orWhereHas('roles', fn ($query) => $query->where('name', 'super_admin')))
                    ->orderBy('id')->first();
                if (! $actor instanceof User) {
                    $failed++;
                    $this->warn("Contratto {$contract->id}: nessun operatore autorizzato disponibile.");

                    return;
                }
                try {
                    $process->execute($actor, $contract, (string) Str::uuid());
                } catch (\Throwable $exception) {
                    $failed++;
                    $this->warn("Contratto {$contract->id}: {$exception->getMessage()}");
                }
            });

        $this->info($failed === 0 ? 'Scadenze contrattuali elaborate.' : "Scadenze elaborate con {$failed} contratto/i non aggiornato/i.");

        return self::SUCCESS;
    }
}
