<?php

namespace App\Actions\Proposals;

use App\Domain\Company\AuditEventType;
use App\Domain\Contracts\ContractState;
use App\Domain\Projects\ProjectState;
use App\Domain\Proposals\ProposalSourceSnapshot;
use App\Domain\Proposals\ProposalSourceType;
use App\Models\AuditEvent;
use App\Models\Company;
use App\Models\Contract;
use App\Models\Exercise;
use App\Models\Project;
use App\Models\Proposal;
use App\Models\ProposalItem;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

final class IncludeProposalSource
{
    public function execute(User $actor, Proposal $proposal, ProposalSourceType $type, int $sourceId, string $operationId, int $expectedRevision): ProposalItem
    {
        if (! Str::isUuid($operationId) || ! in_array($type, [ProposalSourceType::Project, ProposalSourceType::Contract], true)) {
            throw ValidationException::withMessages(['source' => 'Sorgente manuale non valida.']);
        }

        return DB::transaction(function () use ($actor, $proposal, $type, $sourceId, $operationId, $expectedRevision): ProposalItem {
            $company = Company::query()->lockForUpdate()->findOrFail($proposal->company_id);
            $locked = Proposal::query()->with('company')->lockForUpdate()->findOrFail($proposal->id);
            Gate::forUser($actor)->authorize('update', $locked);
            $receipt = AuditEvent::query()->where('operation_id', $operationId)->where('event_sequence', 0)->first();
            if ($receipt !== null) {
                if ($receipt->eventType() !== AuditEventType::ProposalSourceIncluded || $receipt->subject_type !== ProposalItem::class) {
                    throw ValidationException::withMessages(['operation_id' => 'Identificativo operazione già utilizzato.']);
                }

                return ProposalItem::query()->where('proposal_id', $locked->id)->findOrFail($receipt->subject_id);
            }
            if ($locked->revision !== $expectedRevision) {
                throw ValidationException::withMessages(['revision' => 'La Proposta è cambiata: ricaricare prima di continuare.']);
            }
            $exercise = Exercise::query()->where('company_id', $company->id)->lockForUpdate()->findOrFail($locked->exercise_id);
            if (! $exercise->isOpen()) {
                throw ValidationException::withMessages(['exercise' => 'L’Esercizio della Proposta deve essere Aperto.']);
            }
            $date = $exercise->year.'-01-01';
            $source = $type === ProposalSourceType::Project
                ? Project::query()->where('company_id', $company->id)->with(['transitions', 'classifications.costCenter', 'expenses.lines', 'contractLinks'])->find($sourceId)
                : Contract::query()->where('company_id', $company->id)->with(['supplier', 'conditions', 'lifecycleFacts', 'renewalConfigurations', 'classifications.costCenter', 'expenses.lines', 'projectLinks'])->find($sourceId);
            if ($source === null) {
                throw ValidationException::withMessages(['source' => 'Sorgente non disponibile nella stessa Azienda.']);
            }
            $eligible = $source instanceof Project
                ? in_array($source->stateAtDate($date), [ProjectState::Closed, ProjectState::Cancelled], true)
                : in_array($source->stateAtDate($date), [ContractState::Cessated, ContractState::Cancelled], true);
            if (! $eligible) {
                throw ValidationException::withMessages(['source' => 'La selezione manuale richiede un Progetto Chiuso/Cancellato o un Contratto Cessato/Annullato.']);
            }
            $column = $source instanceof Project ? 'project_id' : 'contract_id';
            if ($locked->items()->where($column, $source->id)->exists()) {
                throw ValidationException::withMessages(['source' => 'La sorgente è già inclusa nella Proposta.']);
            }
            $snapshot = $source instanceof Project ? ProposalSourceSnapshot::project($source, $exercise->id) : ProposalSourceSnapshot::contract($source, $exercise->id);
            $item = $locked->items()->create([
                'proposal_item_id' => (string) Str::uuid(), 'company_id' => $company->id, 'source_type' => $type,
                'project_id' => $source instanceof Project ? $source->id : null, 'contract_id' => $source instanceof Contract ? $source->id : null,
                'baseline_revision' => $source->revision, 'baseline_fingerprint' => ProposalSourceSnapshot::fingerprint($snapshot),
                'baseline' => $snapshot, 'result' => $snapshot['plan_baseline'], 'readiness_state' => 'aligned', 'readiness_reasons' => [],
                'read_only_source' => $source->isArchived(), 'last_aligned_at' => now(), 'last_aligned_by_id' => $actor->id,
            ]);
            $locked->increment('revision');
            AuditEvent::query()->create([
                'operation_id' => $operationId, 'company_id' => $company->id, 'actor_id' => $actor->id,
                'event_type' => AuditEventType::ProposalSourceIncluded, 'subject_type' => ProposalItem::class, 'subject_id' => $item->id,
                'affected_exercise_ids' => [$exercise->id], 'effective_from' => now($company->timezone)->toDateString(),
                'previous_value' => null, 'new_value' => ['proposal_id' => $locked->id, 'proposal_item_id' => $item->proposal_item_id, 'source_type' => $type->value, 'origin_key' => $source->originKey(), 'baseline_revision' => $source->revision, 'read_only_source' => $source->isArchived()],
                'allocated_impact_by_exercise' => [(string) $exercise->id => '0.00'], 'actual_impact_by_exercise' => [(string) $exercise->id => '0.00'],
            ]);

            return $item;
        });
    }
}
