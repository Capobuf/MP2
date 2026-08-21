<?php

namespace App\Console\Commands;

use App\Actions\Operations\ProcessContractRenewals;
use App\Domain\Company\Capability;
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
        Contract::query()->active()->whereNotNull('next_expiry_date')->orderBy('company_id')->orderBy('id')->each(function (Contract $contract) use ($process, &$failed): void {
            $actor = User::query()->whereHas('capabilities', fn ($query) => $query
                ->where('company_id', $contract->company_id)
                ->where('capability', Capability::ManageOperations->value))
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
