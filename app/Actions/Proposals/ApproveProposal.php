<?php

namespace App\Actions\Proposals;

use App\Domain\Company\AuditEventType;
use App\Domain\Company\Capability;
use App\Domain\Proposals\ProposalActionType;
use App\Domain\Proposals\ProposalImpactPlan;
use App\Domain\Proposals\ProposalPurpose;
use App\Domain\Proposals\ProposalReadiness;
use App\Domain\Proposals\ProposalReadinessReason;
use App\Domain\Proposals\ProposalSourceType;
use App\Models\Attachment;
use App\Models\AuditEvent;
use App\Models\BudgetSnapshot;
use App\Models\Company;
use App\Models\Contract;
use App\Models\ContractCondition;
use App\Models\ContractExerciseClassification;
use App\Models\ContractLifecycleFact;
use App\Models\ContractRenewalConfiguration;
use App\Models\CostCenter;
use App\Models\Exercise;
use App\Models\Expense;
use App\Models\ExpenseLine;
use App\Models\Project;
use App\Models\ProjectContractLink;
use App\Models\ProjectExerciseClassification;
use App\Models\ProjectTransition;
use App\Models\Proposal;
use App\Models\ProposalAction;
use App\Models\ProposalItem;
use App\Models\Supplier;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

final class ApproveProposal
{
    public function __construct(private ProposalReadiness $readiness, private ApplyProjectPlan $projects, private ApplyContractPlan $contracts, private ApplyExpensePlan $expenses, private ApplyProposalRelations $relations, private MaterializeBudgetSnapshot $materialize) {}

    /**
     * @param  array<string, mixed>  $evidence
     * @param  list<int>  $attachmentIds
     * @param  (callable(string): void)|null  $checkpoint
     */
    public function execute(User $actor, Proposal $proposal, string $operationId, array $evidence = [], array $attachmentIds = [], ?callable $checkpoint = null): BudgetSnapshot
    {
        if (! Str::isUuid($operationId)) {
            throw ValidationException::withMessages(['operation_id' => 'Identificativo operazione non valido.']);
        }
        try {
            return DB::transaction(function () use ($actor, $proposal, $operationId, $evidence, $attachmentIds, $checkpoint): BudgetSnapshot {
                $company = Company::query()->lockForUpdate()->findOrFail($proposal->company_id);
                $locked = Proposal::query()->lockForUpdate()->findOrFail($proposal->id);
                if ($locked->status->value === 'approved') {
                    if ($locked->approval_operation_id === $operationId && $actor->hasCapability($company, Capability::ApproveBudget)) {
                        return BudgetSnapshot::query()->where('proposal_id', $locked->id)->sole();
                    }
                    throw ValidationException::withMessages(['proposal' => 'La Proposta è già terminale.']);
                }
                Gate::forUser($actor)->authorize('approve', $locked);
                if (BudgetSnapshot::query()->where('operation_id', $operationId)->exists()) {
                    throw ValidationException::withMessages(['operation_id' => 'Identificativo operazione già utilizzato.']);
                }

                $preliminary = ProposalItem::query()->where('proposal_id', $locked->id)->with(['actions', 'expense', 'project', 'contract'])->orderBy('id')->get();
                $locked->setRelation('items', $preliminary);
                $exerciseIds = collect(ProposalImpactPlan::affectedExerciseIds($locked));
                $exercises = Exercise::query()->whereIn('id', $exerciseIds)->orderBy('id')->lockForUpdate()->get();
                if ($exercises->count() !== $exerciseIds->count() || $exercises->contains(fn (Exercise $exercise): bool => $exercise->company_id !== $company->id)) {
                    throw ValidationException::withMessages(['exercises' => 'Tutti gli Esercizi interessati devono appartenere alla stessa Azienda.']);
                }
                if (! $exercises->firstWhere('id', $locked->exercise_id)?->isOpen()) {
                    throw ValidationException::withMessages(['exercise' => 'L’Esercizio principale deve essere Aperto.']);
                }
                $budgets = BudgetSnapshot::query()->where('exercise_id', $locked->exercise_id)->orderBy('version')->lockForUpdate()->get();
                $latestBudget = $budgets->last();
                if ($locked->purpose === ProposalPurpose::Revision) {
                    if (blank($evidence['reason'] ?? null)) {
                        throw ValidationException::withMessages(['reason' => 'La motivazione della Revisione è obbligatoria.']);
                    }
                    if ($latestBudget === null || $locked->reference_budget_id !== $latestBudget->id) {
                        throw ValidationException::withMessages(['reference_budget' => 'Il Budget di riferimento non è più l’ultima versione approvata.']);
                    }
                } elseif ($latestBudget !== null) {
                    throw ValidationException::withMessages(['budget' => 'La Proposta iniziale non può creare una seconda versione.']);
                }
                $copySourceIds = $preliminary->flatMap(fn (ProposalItem $item): array => $item->actions
                    ->where('action_type', ProposalActionType::CopyExpense)
                    ->pluck('payload.source_expense_id')->filter()->map(fn (mixed $id): int => (int) $id)->all());
                $expenseIds = $preliminary->pluck('expense_id')->filter()->merge($copySourceIds)->unique()->sort()->values();
                $projectIds = $preliminary->pluck('project_id')->filter()->sort()->values();
                $contractIds = $preliminary->pluck('contract_id')->filter()->sort()->values();
                Expense::query()->whereIn('id', $expenseIds)->orderBy('id')->lockForUpdate()->get();
                Project::query()->whereIn('id', $projectIds)->orderBy('id')->lockForUpdate()->get();
                Contract::query()->whereIn('id', $contractIds)->orderBy('id')->lockForUpdate()->get();
                ExpenseLine::query()->whereIn('expense_id', $expenseIds)->orderBy('id')->lockForUpdate()->get();
                ProjectTransition::query()->whereIn('project_id', $projectIds)->orderBy('id')->lockForUpdate()->get();
                ProjectExerciseClassification::query()->whereIn('project_id', $projectIds)->orderBy('id')->lockForUpdate()->get();
                ContractCondition::query()->whereIn('contract_id', $contractIds)->orderBy('id')->lockForUpdate()->get();
                ContractLifecycleFact::query()->whereIn('contract_id', $contractIds)->orderBy('id')->lockForUpdate()->get();
                ContractRenewalConfiguration::query()->whereIn('contract_id', $contractIds)->orderBy('id')->lockForUpdate()->get();
                ContractExerciseClassification::query()->whereIn('contract_id', $contractIds)->orderBy('id')->lockForUpdate()->get();
                ProjectContractLink::query()->where(fn ($query) => $query->whereIn('project_id', $projectIds)->orWhereIn('contract_id', $contractIds))->orderBy('id')->lockForUpdate()->get();
                $items = ProposalItem::query()->where('proposal_id', $locked->id)->with(['expense', 'project', 'contract', 'actions'])->orderBy('id')->lockForUpdate()->get();
                $actions = ProposalAction::query()->where('proposal_id', $locked->id)->where('status', 'active')->with('item')->orderBy('sequence')->lockForUpdate()->get();
                Supplier::query()->whereIn('id', $items->pluck('result')->map(fn (array $result) => $result['supplier_id'] ?? null)->filter())->orderBy('id')->lockForUpdate()->get();
                CostCenter::query()->whereIn('id', $items->pluck('result')->map(fn (array $result) => $result['cost_center_id'] ?? $result['direct_cost_center_id'] ?? null)->filter())->orderBy('id')->lockForUpdate()->get();
                Attachment::query()->whereIn('id', $attachmentIds)->orderBy('id')->lockForUpdate()->get();
                $locked->setRelation('company', $company);
                $locked->setRelation('exercise', $exercises->firstWhere('id', $locked->exercise_id));
                $locked->setRelation('items', $items);
                $locked->setRelation('actions', $actions);
                $review = $this->readiness->assessProposal($locked);
                if (! $review['ready']) {
                    throw ValidationException::withMessages(['proposal' => collect($review['blocks'])->pluck('message')->all()]);
                }

                $identities = [];
                foreach ($items->where('source_type', ProposalSourceType::Project) as $item) {
                    $identities[$item->proposal_item_id] = $this->projects->execute($item, $actor);
                    self::checkpoint($checkpoint, 'after_project');
                }
                foreach ($items->where('source_type', ProposalSourceType::Contract) as $item) {
                    $identities[$item->proposal_item_id] = $this->contracts->execute($item, $actor);
                    self::checkpoint($checkpoint, 'after_contract');
                }
                foreach ($items->where('source_type', ProposalSourceType::Expense) as $item) {
                    $identities[$item->proposal_item_id] = $this->expenses->execute($item, $identities, $actor);
                    self::checkpoint($checkpoint, 'after_expense');
                }
                $this->relations->execute($locked, $identities);
                self::checkpoint($checkpoint, 'after_live_apply');
                $this->markCompetingDraftsStale($locked, $items);

                return $this->materialize->execute($locked, $identities, $actor, $operationId, $evidence, $attachmentIds, $checkpoint, $review['impacts']);
            }, 3);
        } catch (\Throwable $exception) {
            $this->recordFailedApproval($actor, $proposal, $operationId, $exception);
            throw $exception;
        }
    }

    /** @param Collection<int, ProposalItem> $items */
    private function markCompetingDraftsStale(Proposal $proposal, $items): void
    {
        $query = ProposalItem::query()->where('company_id', $proposal->company_id)->where('proposal_id', '!=', $proposal->id)
            ->whereHas('proposal', fn ($builder) => $builder->where('status', 'draft'))
            ->where(function ($builder) use ($items): void {
                foreach (['expense_id', 'project_id', 'contract_id'] as $column) {
                    $ids = $items->pluck($column)->filter();
                    if ($ids->isNotEmpty()) {
                        $builder->orWhereIn($column, $ids);
                    }
                }
            });
        $proposalIds = (clone $query)->pluck('proposal_id')->unique();
        if ($proposalIds->isEmpty()) {
            return;
        }
        $query->update([
            'readiness_state' => 'to_realign',
            'readiness_reasons' => json_encode([['code' => ProposalReadinessReason::SourceChanged->value, 'message' => ProposalReadinessReason::SourceChanged->message()]], JSON_THROW_ON_ERROR),
            'updated_at' => now(),
        ]);
        Proposal::query()->whereIn('id', $proposalIds)->increment('revision');
    }

    private function recordFailedApproval(User $actor, Proposal $proposal, string $operationId, \Throwable $exception): void
    {
        try {
            $reason = $exception instanceof ValidationException
                ? (string) (collect($exception->errors())->flatten()->first() ?? 'Approvazione non valida.')
                : ($exception instanceof AuthorizationException ? 'Autorizzazione all’approvazione negata.' : 'Approvazione atomica non completata; nessun effetto è stato applicato.');
            AuditEvent::query()->firstOrCreate(
                ['operation_id' => $operationId, 'event_sequence' => 4294967295],
                [
                    'company_id' => $proposal->company_id, 'actor_id' => $actor->id,
                    'event_type' => AuditEventType::ProposalApprovalFailed,
                    'subject_type' => Proposal::class, 'subject_id' => $proposal->id,
                    'affected_exercise_ids' => [$proposal->exercise_id],
                    'effective_from' => now($proposal->company->timezone)->toDateString(),
                    'previous_value' => ['status' => $proposal->status->value],
                    'new_value' => ['status' => $proposal->status->value, 'rolled_back' => true, 'attempted_operation_id' => $operationId],
                    'allocated_impact_by_exercise' => [(string) $proposal->exercise_id => '0.00'],
                    'actual_impact_by_exercise' => [(string) $proposal->exercise_id => '0.00'],
                    'reason' => $reason,
                ],
            );
        } catch (\Throwable) {
            // The original approval failure remains authoritative if audit persistence is unavailable.
        }
    }

    /** @param (callable(string): void)|null $checkpoint */
    private static function checkpoint(?callable $checkpoint, string $name): void
    {
        if ($checkpoint !== null) {
            $checkpoint($name);
        }
    }
}
