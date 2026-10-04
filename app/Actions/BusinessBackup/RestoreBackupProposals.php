<?php

namespace App\Actions\BusinessBackup;

use App\BusinessBackup\V1\ProposalPortableCodec;
use App\Domain\Proposals\ContractPlan;
use App\Domain\Proposals\ExpensePlan;
use App\Domain\Proposals\ProjectPlan;
use App\Domain\Proposals\ProposalActionPayload;
use App\Domain\Proposals\ProposalActionReplay;
use App\Domain\Proposals\ProposalActionType;
use App\Domain\Proposals\ProposalReadiness;
use App\Domain\Proposals\ProposalReadinessState;
use App\Domain\Proposals\ProposalSourceSnapshot;
use App\Domain\Proposals\ProposalSourceType;
use App\Models\Expense;
use App\Models\ProjectDeferral;
use App\Models\Proposal;
use App\Models\ProposalItem;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class RestoreBackupProposals
{
    private ProposalPortableCodec $codec;

    public function __construct()
    {
        $this->codec = new ProposalPortableCodec;
    }

    /** @param array<string, mixed> $package
     * @param  array<string, array<string, int|string>>  $ids
     */
    public function headersAndItems(array $package, array &$ids, int $companyId): void
    {
        foreach ($this->rows($package, '_MP2_proposals') as $row) {
            $ids['proposal'][$row['proposal_ref']] = DB::table('proposals')->insertGetId([
                'company_id' => $companyId, 'exercise_id' => $ids['exercise'][$row['exercise_ref']],
                'purpose' => $row['purpose'], 'status' => $row['status'], 'created_by_id' => null,
                'reference_budget_id' => null, 'revision' => 0,
                'approved_at' => $row['approved_at'] ?: null, 'approval_operation_id' => $row['status'] === 'approved' ? (string) Str::uuid() : null,
                'discarded_at' => $row['discarded_at'] ?: null, 'discard_reason' => $row['discard_reason'] ?: null,
                'discard_operation_id' => $row['status'] === 'discarded' ? (string) Str::uuid() : null,
                'created_at' => $row['created_at'], 'updated_at' => $row['updated_at'],
            ]);
        }
        foreach ($this->rows($package, '_MP2_proposal_items') as $row) {
            $ids['proposal_item_uuid'][$row['proposal_item_ref']] = (string) Str::uuid();
        }
        $resolve = $this->resolver($ids);
        foreach ($this->rows($package, '_MP2_proposal_items') as $row) {
            $baseline = $this->codec->decode(json_decode($row['baseline_json'], true, flags: JSON_THROW_ON_ERROR), $resolve, null, $row['source_type']);
            $result = $this->codec->decode(json_decode($row['result_json'], true, flags: JSON_THROW_ON_ERROR), $resolve, null, $row['source_type']);
            $ids['proposal_item'][$row['proposal_item_ref']] = DB::table('proposal_items')->insertGetId([
                'company_id' => $companyId, 'proposal_id' => $ids['proposal'][$row['proposal_ref']],
                'proposal_item_id' => $ids['proposal_item_uuid'][$row['proposal_item_ref']],
                'source_type' => $row['source_type'], $row['source_type'].'_id' => $row['source_ref'] === '' ? null : $ids[$row['source_type']][$row['source_ref']],
                'copied_from_origin_key' => $row['copied_from_source_ref'] === '' ? null : $resolve('origin', $row['copied_from_source_ref']),
                'baseline_revision' => $row['source_ref'] === '' ? null : 0,
                'baseline_fingerprint' => $row['source_ref'] === '' ? null : ProposalSourceSnapshot::fingerprint($baseline),
                'baseline' => json_encode($baseline, JSON_THROW_ON_ERROR), 'result' => json_encode($result, JSON_THROW_ON_ERROR),
                'readiness_state' => $row['readiness_state'], 'readiness_reasons' => $row['readiness_reasons_json'],
                'read_only_source' => (int) $row['read_only_source'], 'last_aligned_at' => $row['last_aligned_at'] ?: null,
                'last_aligned_by_id' => null, 'created_at' => now('UTC'), 'updated_at' => now('UTC'),
            ]);
        }
    }

    /** @param array<string, mixed> $package
     * @param  array<string, array<string, int|string>>  $ids
     */
    public function actionsAndReferences(array $package, array $ids, int $companyId): void
    {
        foreach ($this->rows($package, '_MP2_proposals') as $row) {
            if ($row['reference_budget_ref'] !== '') {
                DB::table('proposals')->where('id', $ids['proposal'][$row['proposal_ref']])->update(['reference_budget_id' => $ids['budget'][$row['reference_budget_ref']]]);
            }
        }
        foreach ($this->rows($package, '_MP2_proposal_actions') as $row) {
            $type = ProposalActionType::from($row['action_type']);
            $payload = $this->codec->decodeAction($type, json_decode($row['payload_json'], true, flags: JSON_THROW_ON_ERROR), $this->resolver($ids));
            $itemId = $row['proposal_item_ref'] === '' ? null : $ids['proposal_item'][$row['proposal_item_ref']];
            $item = $itemId === null ? null : ProposalItem::query()->findOrFail($itemId);
            $payload = $this->localPreconditions($type, $payload, $item);
            ProposalActionPayload::validate($type, $payload);
            DB::table('proposal_actions')->insert([
                'company_id' => $companyId, 'proposal_id' => $ids['proposal'][$row['proposal_ref']], 'proposal_item_id' => $itemId,
                'sequence' => (int) $row['sequence'], 'action_type' => $row['action_type'], 'payload_version' => (int) $row['payload_version'],
                'payload' => json_encode($payload, JSON_THROW_ON_ERROR), 'reason' => $row['reason'] ?: null, 'status' => $row['status'],
                'created_by_id' => null, 'withdrawn_by_id' => null, 'withdrawn_at' => $row['withdrawn_at'] ?: null,
                'withdraw_reason' => $row['withdraw_reason'] ?: null,
                'operation_id' => (string) Str::uuid(), 'withdraw_operation_id' => $row['status'] === 'withdrawn' ? (string) Str::uuid() : null,
                'created_at' => $row['created_at'], 'updated_at' => $row['withdrawn_at'] ?: $row['created_at'],
            ]);
        }
    }

    public function rebuildDrafts(int $companyId): void
    {
        $proposals = Proposal::query()->where('company_id', $companyId)->where('status', 'draft')->with(['items.expense', 'items.project', 'items.contract', 'items.actions', 'actions'])->get();
        foreach ($proposals as $proposal) {
            // Rebuild every result before assessing references between planned items.
            foreach ($proposal->items as $item) {
                $source = $item->expense ?? $item->project ?? $item->contract;
                if ($source !== null) {
                    $baseline = match ($item->source_type) {
                        ProposalSourceType::Expense => ProposalSourceSnapshot::expense($item->expense),
                        ProposalSourceType::Project => ProposalSourceSnapshot::project($item->project, $proposal->exercise_id),
                        ProposalSourceType::Contract => ProposalSourceSnapshot::contract($item->contract, $proposal->exercise_id),
                    };
                    $result = app(ProposalActionReplay::class)->replay($item, $baseline, validateSemantic: false);
                    $item->update(['baseline' => $baseline, 'baseline_revision' => (int) $source->revision, 'baseline_fingerprint' => ProposalSourceSnapshot::fingerprint($baseline), 'result' => $result, 'readiness_state' => 'aligned', 'readiness_reasons' => []]);
                } else {
                    $actions = $item->actions->sortBy('sequence')->values();
                    $creation = $actions->first();
                    if ($creation === null) {
                        throw new \UnexpectedValueException('New Proposal item has no creation Action.');
                    }
                    $payload = $creation->payload;
                    $result = match ($item->source_type) {
                        ProposalSourceType::Expense => $this->newExpense($proposal, $creation->action_type, $payload),
                        ProposalSourceType::Project => ProjectPlan::create($payload),
                        ProposalSourceType::Contract => ContractPlan::create($payload),
                    };
                    $item->result = $result;
                    foreach ($actions->skip(1) as $action) {
                        $item->result = match ($item->source_type) {
                            ProposalSourceType::Expense => ExpensePlan::apply($item, $action->action_type, $action->payload),
                            ProposalSourceType::Project => ProjectPlan::apply($proposal, $item, $action->action_type, $action->payload),
                            ProposalSourceType::Contract => ContractPlan::apply($item, $action->action_type, $action->payload),
                        };
                    }
                    $item->readiness_state = ProposalReadinessState::Aligned;
                    $item->readiness_reasons = [];
                    $item->save();
                }
            }
            foreach ($proposal->items as $item) {
                $assessment = app(ProposalReadiness::class)->assessItem($item->fresh(['proposal', 'actions', 'expense', 'project', 'contract']));
                $item->update(['readiness_state' => $assessment['state'], 'readiness_reasons' => $assessment['reasons']]);
            }
            app(ProposalReadiness::class)->assessProposal($proposal->fresh());
        }
    }

    /** @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    private function newExpense(Proposal $proposal, ProposalActionType $type, array $payload): array
    {
        ExpensePlan::validateNew($proposal, $payload, $type);
        $result = $payload;
        unset($result['source_expense_id'], $result['target_exercise_id']);
        $result['exercise_id'] = $payload['exercise_id'] ?? $payload['target_exercise_id'];

        return $result;
    }

    /** @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    private function localPreconditions(ProposalActionType $type, array $payload, ?ProposalItem $item): array
    {
        if ($type === ProposalActionType::CopyExpense) {
            $source = Expense::query()->findOrFail($payload['source_expense_id']);
            $payload['source_revision'] = (int) $source->revision;
            $payload['source_fingerprint'] = ProposalSourceSnapshot::fingerprint(ProposalSourceSnapshot::expense($source));
        }
        if ($type === ProposalActionType::PlanProjectDeferral && $item?->project !== null) {
            $project = $item->project;
            $payload['source_context']['project_revision'] = (int) $project->revision;
            $payload['source_context']['project_fingerprint'] = ProposalSourceSnapshot::fingerprint(ProposalSourceSnapshot::project($project, $payload['source_exercise_id']));
            if (isset($payload['active_reprogramming_operation_id'])) {
                $deferral = ProjectDeferral::query()->where('project_id', $project->id)->where('source_exercise_id', $payload['source_exercise_id'])->where('destination_exercise_id', $payload['destination_exercise_id'])->firstOrFail();
                $payload['active_reprogramming_operation_id'] = $deferral->reprogramming_operation_id;
                $payload['active_reprogramming_fingerprint'] = ProposalSourceSnapshot::fingerprint(['operation_id' => $deferral->reprogramming_operation_id, 'effects' => $deferral->reprogramming_effects]);
            }
        }

        return $payload;
    }

    /** @param array<string, array<string, int|string>> $ids
     * @return callable(string, mixed): (int|string)
     */
    private function resolver(array $ids): callable
    {
        return function (string $type, mixed $ref) use ($ids): int|string {
            if ($type === 'origin') {
                foreach (['expense', 'project', 'contract'] as $originType) {
                    if (isset($ids[$originType][$ref])) {
                        return $originType.':'.$ids[$originType][$ref];
                    }
                }
            }

            return $ids[$type][$ref] ?? throw new \UnexpectedValueException("Unresolved Proposal reference [$type:$ref].");
        };
    }

    /** @param array<string, mixed> $package
     * @return list<array<string, string>>
     */
    private function rows(array $package, string $sheet): array
    {
        return array_map(fn (array $row): array => array_combine($package['machine'][$sheet]['columns'], $row), $package['machine'][$sheet]['rows']);
    }
}
