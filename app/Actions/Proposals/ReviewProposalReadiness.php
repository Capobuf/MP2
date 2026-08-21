<?php

namespace App\Actions\Proposals;

use App\Domain\Company\AuditEventType;
use App\Domain\Proposals\ProposalReadiness;
use App\Domain\Proposals\ProposalReadinessReason;
use App\Domain\Proposals\ProposalReadinessState;
use App\Domain\Proposals\ProposalSourceCatalog;
use App\Domain\Proposals\ProposalSourceSnapshot;
use App\Models\AuditEvent;
use App\Models\Company;
use App\Models\Contract;
use App\Models\Exercise;
use App\Models\Expense;
use App\Models\Project;
use App\Models\Proposal;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

final class ReviewProposalReadiness
{
    public function __construct(private ProposalSourceCatalog $catalog, private ProposalReadiness $readiness) {}

    public function execute(User $actor, Proposal $proposal, string $operationId): Proposal
    {
        return DB::transaction(function () use ($actor, $proposal, $operationId): Proposal {
            Company::query()->lockForUpdate()->findOrFail($proposal->company_id);
            $locked = Proposal::query()->with('company')->lockForUpdate()->findOrFail($proposal->id);
            Gate::forUser($actor)->authorize('update', $locked);
            $receipt = AuditEvent::query()->where('operation_id', $operationId)->first();
            if ($receipt !== null) {
                if ($receipt->eventType() !== AuditEventType::ProposalReadinessReviewed || $receipt->subject_id !== $locked->id) {
                    throw ValidationException::withMessages(['operation_id' => 'Identificativo operazione già utilizzato.']);
                }

                return $locked->load('items');
            }
            if (! Str::isUuid($operationId)) {
                throw ValidationException::withMessages(['operation_id' => 'Identificativo operazione non valido.']);
            }
            $exercise = Exercise::query()->lockForUpdate()->findOrFail($locked->exercise_id);
            $locked->setRelation('exercise', $exercise);
            $items = $locked->items()->with(['expense', 'project', 'contract', 'actions'])->orderBy('id')->lockForUpdate()->get();
            $locked->setRelation('items', $items);
            $previousReadiness = $items->map(fn ($item): array => ['proposal_item_id' => $item->proposal_item_id, 'state' => $item->readiness_state->value, 'reasons' => $item->readiness_reasons])->values()->all();
            $existingKeys = $items->map(fn ($item): ?string => $item->expense_id ? 'expense:'.$item->expense_id : ($item->project_id ? 'project:'.$item->project_id : ($item->contract_id ? 'contract:'.$item->contract_id : null)))->filter();
            foreach ($this->catalog->forExercise($exercise) as $source) {
                if ($existingKeys->contains($source['origin_key'])) {
                    continue;
                }
                $model = $source['model'];
                $snapshot = match (true) {
                    $model instanceof Expense => ProposalSourceSnapshot::expense($model), $model instanceof Project => ProposalSourceSnapshot::project($model, $exercise->id), $model instanceof Contract => ProposalSourceSnapshot::contract($model, $exercise->id)
                };
                $locked->items()->create(['proposal_item_id' => (string) Str::uuid(), 'company_id' => $locked->company_id, 'source_type' => $source['source_type'], 'expense_id' => $model instanceof Expense ? $model->id : null, 'project_id' => $model instanceof Project ? $model->id : null, 'contract_id' => $model instanceof Contract ? $model->id : null, 'baseline_revision' => (int) $model->revision, 'baseline_fingerprint' => ProposalSourceSnapshot::fingerprint($snapshot), 'baseline' => $snapshot, 'result' => $snapshot['plan_baseline'], 'readiness_state' => ProposalReadinessState::ToReview, 'readiness_reasons' => [['code' => ProposalReadinessReason::NewSource->value, 'message' => ProposalReadinessReason::NewSource->message()]], 'read_only_source' => $source['read_only'], 'last_aligned_at' => null, 'last_aligned_by_id' => null]);
            }
            $locked->unsetRelation('items');
            $locked->load(['exercise', 'items.expense', 'items.project', 'items.contract', 'items.actions', 'actions']);
            foreach ($locked->items as $item) {
                $assessment = $this->readiness->assessItem($item);
                $item->update(['readiness_state' => $assessment['state'], 'readiness_reasons' => $assessment['reasons']]);
            }
            $locked->increment('revision');
            $review = $this->readiness->assessProposal($locked->fresh(['exercise', 'items.expense', 'items.project', 'items.contract', 'items.actions', 'actions']));
            AuditEvent::query()->create([
                'operation_id' => $operationId,
                'company_id' => $locked->company_id,
                'actor_id' => $actor->id,
                'event_type' => AuditEventType::ProposalReadinessReviewed,
                'subject_type' => Proposal::class,
                'subject_id' => $locked->id,
                'affected_exercise_ids' => collect($review['impacts'])->pluck('exercise_id')->all(),
                'effective_from' => now($locked->company->timezone)->toDateString(),
                'previous_value' => ['items' => $previousReadiness],
                'new_value' => ['ready' => $review['ready'], 'membership_keys' => $review['membership_keys'], 'blocks' => $review['blocks'], 'impacts' => $review['impacts']],
                'allocated_impact_by_exercise' => collect($review['impacts'])->mapWithKeys(fn (array $impact): array => [(string) $impact['exercise_id'] => $impact['allocation_delta']])->all(),
                'actual_impact_by_exercise' => collect($review['impacts'])->mapWithKeys(fn (array $impact): array => [(string) $impact['exercise_id'] => '0.00'])->all(),
            ]);

            return $locked->fresh(['exercise', 'items', 'actions']);
        });
    }
}
