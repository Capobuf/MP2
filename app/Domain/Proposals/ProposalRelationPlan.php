<?php

namespace App\Domain\Proposals;

use App\Models\Contract;
use App\Models\Project;
use App\Models\ProjectContractLink;
use App\Models\Proposal;
use Illuminate\Validation\ValidationException;

final class ProposalRelationPlan
{
    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public static function validate(Proposal $proposal, array $payload): array
    {
        $validated = ProposalActionPayload::validate(ProposalActionType::LinkProjectContract, $payload);
        if (isset($validated['project_item_id'])) {
            ProposalItemReference::item($proposal, $validated['project_item_id'], 'project');
        } else {
            self::live($proposal, $validated['project_origin_key'], 'project');
        }
        if (isset($validated['contract_item_id'])) {
            ProposalItemReference::item($proposal, $validated['contract_item_id'], 'contract');
        } else {
            self::live($proposal, $validated['contract_origin_key'], 'contract');
        }
        if (isset($validated['project_origin_key'], $validated['contract_origin_key'])) {
            $projectId = (int) str($validated['project_origin_key'])->after(':')->toString();
            $contractId = (int) str($validated['contract_origin_key'])->after(':')->toString();
            if (ProjectContractLink::query()->active()->where('company_id', $proposal->company_id)->where('project_id', $projectId)->where('contract_id', $contractId)->exists()) {
                throw ValidationException::withMessages(['relation' => 'Il collegamento Progetto–Contratto è già attivo.']);
            }
            $archived = ProjectContractLink::query()->where('company_id', $proposal->company_id)->where('project_id', $projectId)->where('contract_id', $contractId)->whereNotNull('archived_at')->latest('id')->first();
            if ($archived !== null) {
                $validated['restore_link_id'] = $archived->id;
            }
        }
        ksort($validated);

        return $validated;
    }

    private static function live(Proposal $proposal, string $originKey, string $type): void
    {
        if (! preg_match('/^'.preg_quote($type, '/').':([1-9]\d*)$/', $originKey, $matches)) {
            throw ValidationException::withMessages([$type.'_origin_key' => 'OriginKey non valido.']);
        }
        $model = $type === 'project' ? Project::class : Contract::class;
        if (! $model::query()->where('company_id', $proposal->company_id)->whereKey((int) $matches[1])->exists()) {
            throw ValidationException::withMessages([$type.'_origin_key' => 'Sorgente non disponibile in questa Azienda.']);
        }
    }
}
