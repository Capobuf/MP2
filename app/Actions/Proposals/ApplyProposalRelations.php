<?php

namespace App\Actions\Proposals;

use App\Domain\Proposals\ProposalActionType;
use App\Models\Contract;
use App\Models\Expense;
use App\Models\Project;
use App\Models\ProjectContractLink;
use App\Models\Proposal;
use Illuminate\Validation\ValidationException;

final class ApplyProposalRelations
{
    /** @param array<string, Project|Contract|Expense> $identities */
    public function execute(Proposal $proposal, array $identities): void
    {
        foreach ($proposal->actions->filter(fn ($action): bool => $action->action_type === ProposalActionType::LinkProjectContract) as $action) {
            $payload = $action->payload;
            $project = isset($payload['project_item_id']) ? ($identities[$payload['project_item_id']] ?? null) : Project::query()->where('company_id', $proposal->company_id)->find((int) str($payload['project_origin_key'])->after(':')->toString());
            $contract = isset($payload['contract_item_id']) ? ($identities[$payload['contract_item_id']] ?? null) : Contract::query()->where('company_id', $proposal->company_id)->find((int) str($payload['contract_origin_key'])->after(':')->toString());
            if (! $project instanceof Project || ! $contract instanceof Contract) {
                throw ValidationException::withMessages(['relation' => 'Riferimenti Progetto–Contratto non risolti.']);
            }
            if (isset($payload['restore_link_id'])) {
                $link = ProjectContractLink::query()->where('company_id', $proposal->company_id)->where('project_id', $project->id)->where('contract_id', $contract->id)->whereNotNull('archived_at')->find($payload['restore_link_id']);
                if ($link === null) {
                    throw ValidationException::withMessages(['relation' => 'Il collegamento Archiviato da ripristinare non è più disponibile.']);
                }
                $link->update(['archived_at' => null, 'revision' => $link->revision + 1]);
            } else {
                ProjectContractLink::query()->firstOrCreate(['company_id' => $proposal->company_id, 'project_id' => $project->id, 'contract_id' => $contract->id, 'archived_at' => null], ['note' => 'Collegato da Proposta #'.$proposal->id]);
            }
        }
    }
}
