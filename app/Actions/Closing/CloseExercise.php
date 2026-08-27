<?php

namespace App\Actions\Closing;

use App\Actions\Operations\CreateExercise;
use App\Actions\Operations\ProcessContractRenewals;
use App\Actions\Operations\RecalculateContractEstimates;
use App\Actions\Proposals\MarkProposalItemsToRealign;
use App\Domain\Closing\ClosingSnapshotPayload;
use App\Domain\Company\AuditEventType;
use App\Domain\Expenses\Decimal;
use App\Domain\Expenses\ExerciseStatus;
use App\Domain\Projects\ProjectDeferralValues;
use App\Domain\Projects\ProjectState;
use App\Models\AuditEvent;
use App\Models\BudgetSnapshot;
use App\Models\ClosingSnapshot;
use App\Models\Company;
use App\Models\Contract;
use App\Models\ContractCondition;
use App\Models\ContractExerciseClassification;
use App\Models\ContractLifecycleFact;
use App\Models\ContractRenewalConfiguration;
use App\Models\Exercise;
use App\Models\Expense;
use App\Models\ExpenseLine;
use App\Models\Project;
use App\Models\ProjectDeferral;
use App\Models\ProjectExerciseClassification;
use App\Models\ProjectTransition;
use App\Models\Proposal;
use App\Models\ProposalItem;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Throwable;

final class CloseExercise
{
    public function __construct(
        private readonly ReviewExerciseClosing $review,
        private readonly ProcessContractRenewals $renewals,
        private readonly RecalculateContractEstimates $recalculate,
        private readonly CreateExercise $createExercise,
        private readonly ApplyProjectClosingDeferral $applyDeferral,
        private readonly ApplyProjectClosingTransition $applyTransition,
        private readonly MarkProposalItemsToRealign $markToRealign,
    ) {}

    /** @param array<string, mixed> $input */
    public function execute(User $actor, Exercise $exercise, array $input, string $operationId): ClosingSnapshot
    {
        if (! Str::isUuid($operationId)) {
            throw ValidationException::withMessages(['operation_id' => 'Identificativo operazione non valido.']);
        }
        $exercise->loadMissing('company');
        Gate::forUser($actor)->authorize('close', $exercise);

        $existingSnapshot = ClosingSnapshot::query()->where('operation_id', $operationId)->first();
        if ($existingSnapshot !== null) {
            if ($existingSnapshot->exercise_id !== $exercise->id || $existingSnapshot->company_id !== $exercise->company_id) {
                throw ValidationException::withMessages(['operation_id' => 'Identificativo operazione già utilizzato.']);
            }

            return $existingSnapshot->load('rows');
        }

        $this->ensureStartedAudit($actor, $exercise, $operationId);

        try {
            return DB::transaction(function () use ($actor, $exercise, $input, $operationId): ClosingSnapshot {
                $company = Company::query()->lockForUpdate()->findOrFail($exercise->company_id);
                $lockedExercise = Exercise::query()->where('company_id', $company->id)->lockForUpdate()->findOrFail($exercise->id);
                Gate::forUser($actor)->authorize('close', $lockedExercise);

                $existingSnapshot = ClosingSnapshot::query()->where('operation_id', $operationId)->lockForUpdate()->first();
                if ($existingSnapshot !== null) {
                    if ($existingSnapshot->exercise_id !== $lockedExercise->id) {
                        throw ValidationException::withMessages(['operation_id' => 'Identificativo operazione già utilizzato.']);
                    }

                    return $existingSnapshot->load('rows');
                }

                $sequence = ((int) AuditEvent::query()->where('operation_id', $operationId)->max('event_sequence')) + 1;
                $this->lockClosingState($company);

                $authoritativeReview = $this->review->execute($actor, $lockedExercise, $input);
                $expectedFingerprint = is_string($input['review_fingerprint'] ?? null) ? $input['review_fingerprint'] : '';
                if ($expectedFingerprint === '' || ! hash_equals($authoritativeReview->fingerprint(), $expectedFingerprint)) {
                    throw ValidationException::withMessages([
                        'review' => 'La situazione di Chiusura è cambiata: ricaricare il riepilogo prima di confermare.',
                    ]);
                }
                if (! $authoritativeReview->canClose()) {
                    throw ValidationException::withMessages([
                        'closing' => 'La Chiusura contiene controlli bloccanti da risolvere.',
                    ]);
                }
                if (! filter_var($input['confirmed'] ?? false, FILTER_VALIDATE_BOOL)) {
                    throw ValidationException::withMessages([
                        'confirmed' => 'Confermare esplicitamente la Chiusura irreversibile dell’Esercizio.',
                    ]);
                }
                if ($authoritativeReview->warnings !== []
                    && ! filter_var($input['warnings_acknowledged'] ?? false, FILTER_VALIDATE_BOOL)) {
                    throw ValidationException::withMessages([
                        'warnings_acknowledged' => 'Prendere visione degli avvisi di Chiusura prima di confermare.',
                    ]);
                }

                $this->audit(
                    actor: $actor,
                    type: AuditEventType::ExerciseClosingConfirmed,
                    subject: $lockedExercise,
                    operationId: $operationId,
                    sequence: $sequence++,
                    affectedExerciseIds: $this->existingAffectedExerciseIds($authoritativeReview->affectedExercises),
                    effectiveFrom: $lockedExercise->year.'-12-31',
                    newValue: [
                        'review_fingerprint' => $authoritativeReview->fingerprint(),
                        'totals' => $authoritativeReview->totals,
                        'project_decisions' => $authoritativeReview->projectDecisions,
                        'accepted_warnings' => $authoritativeReview->warnings,
                        'next_exercise' => $authoritativeReview->nextExercise,
                    ],
                );

                $openExercises = Exercise::query()
                    ->where('company_id', $company->id)
                    ->open()
                    ->orderBy('year')
                    ->lockForUpdate()
                    ->get();
                $changedContractIds = [];
                $contracts = Contract::query()->where('company_id', $company->id)->orderBy('id')->lockForUpdate()->get();
                foreach ($contracts as $contract) {
                    $renewalChanged = $this->renewals->processThroughWithinTransaction(
                        actor: $actor,
                        contract: $contract,
                        cutoffDate: $lockedExercise->year.'-12-31',
                        operationId: $operationId,
                        sequence: $sequence,
                        openExercises: $openExercises,
                        authorize: false,
                        recalculate: false,
                    );
                    $contract->unsetRelation('conditions')->unsetRelation('lifecycleFacts')->unsetRelation('renewalConfigurations');
                    $impacts = $this->recalculate->recalculateWithinTransaction(
                        $actor,
                        $contract,
                        $openExercises,
                        $operationId,
                        $sequence,
                    );
                    $allocationChanged = collect($impacts)->contains(
                        fn (array $impact): bool => Decimal::compare($impact['before'], $impact['after']) !== 0,
                    );
                    if ($renewalChanged || $allocationChanged) {
                        $changedContractIds[] = $contract->id;
                        if ($allocationChanged && ! $renewalChanged) {
                            $contract->increment('revision');
                        }
                    }
                }

                $nextExercise = Exercise::query()
                    ->where('company_id', $company->id)
                    ->where('year', $lockedExercise->year + 1)
                    ->lockForUpdate()
                    ->first();
                $createdNextExercise = false;
                if ($nextExercise === null && ($authoritativeReview->nextExercise['create_next_exercise'] ?? null) === true) {
                    $nextExercise = $this->createExercise->createWithinTransaction(
                        actor: $actor,
                        company: $company,
                        year: $lockedExercise->year + 1,
                        operationId: $operationId,
                        sequence: $sequence,
                    );
                    $createdNextExercise = true;
                    $changedContractIds = array_merge($changedContractIds, $contracts->pluck('id')->map(fn (mixed $id): int => (int) $id)->all());
                } elseif ($nextExercise === null) {
                    $this->audit(
                        actor: $actor,
                        type: AuditEventType::NextExerciseNotCreated,
                        subject: $lockedExercise,
                        operationId: $operationId,
                        sequence: $sequence++,
                        affectedExerciseIds: [$lockedExercise->id],
                        effectiveFrom: $lockedExercise->year.'-12-31',
                        newValue: [
                            'year' => $lockedExercise->year + 1,
                            'reason' => 'next_exercise_not_requested',
                        ],
                    );
                }

                $changedProjectIds = [];
                $projectDecisionMap = collect($authoritativeReview->projectDecisions)->keyBy('project_id');
                foreach ($authoritativeReview->projectDecisions as $decision) {
                    if (! ($decision['required'] ?? false)) {
                        continue;
                    }
                    $project = Project::query()->where('company_id', $company->id)->lockForUpdate()->findOrFail($decision['project_id']);
                    $deferralResult = $this->applyDeferral->executeWithinTransaction(
                        actor: $actor,
                        project: $project,
                        source: $lockedExercise,
                        destination: $nextExercise,
                        decision: $decision,
                        operationId: $operationId,
                        sequence: $sequence,
                    );
                    if ($deferralResult['changed']) {
                        $changedProjectIds[] = $project->id;
                    }

                    $finalState = ProjectState::from((string) $decision['final_state']);
                    $transition = $this->applyTransition->executeWithinTransaction(
                        actor: $actor,
                        project: $project,
                        exercise: $lockedExercise,
                        finalState: $finalState,
                        reason: is_string($decision['reason'] ?? null) ? $decision['reason'] : null,
                        operationId: $operationId,
                        sequence: $sequence,
                        openExercises: Exercise::query()->where('company_id', $company->id)->open()->orderBy('year')->get(),
                    );
                    if ($transition !== null) {
                        $changedProjectIds[] = $project->id;
                    }
                }

                if ($createdNextExercise) {
                    $changedProjectIds = array_merge($changedProjectIds, Project::query()->where('company_id', $company->id)->pluck('id')->map(fn (mixed $id): int => (int) $id)->all());
                }
                $changedProjectIds = array_values(array_unique(array_map('intval', $changedProjectIds)));
                $changedContractIds = array_values(array_unique(array_map('intval', $changedContractIds)));

                $proposalIdsToAudit = $this->proposalIdsToRealign($company->id, $changedProjectIds, $changedContractIds);
                $this->markToRealign->execute($company->id, projectIds: $changedProjectIds, contractIds: $changedContractIds);
                foreach ($proposalIdsToAudit as $proposalId) {
                    $proposal = Proposal::query()->find($proposalId);
                    if ($proposal === null) {
                        continue;
                    }
                    $this->audit(
                        actor: $actor,
                        type: AuditEventType::ProposalMarkedToRealign,
                        subject: $proposal,
                        operationId: $operationId,
                        sequence: $sequence++,
                        affectedExerciseIds: [$proposal->exercise_id],
                        effectiveFrom: $lockedExercise->year.'-12-31',
                        newValue: ['readiness_state' => 'to_realign', 'caused_by_closing_exercise_id' => $lockedExercise->id],
                    );
                }

                $this->writeProjectBalanceEvents($actor, $lockedExercise, $projectDecisionMap, $operationId, $sequence);

                $eventReferences = $this->eventReferences($company->id, $operationId);
                $payload = ClosingSnapshotPayload::build($lockedExercise, $authoritativeReview->projectDecisions, $eventReferences);
                if (Decimal::compare($payload['total_final_allocation'], $authoritativeReview->totals['final_allocation']) !== 0
                    || Decimal::compare($payload['total_closing_actual'], $authoritativeReview->totals['closing_actual']) !== 0) {
                    throw ValidationException::withMessages([
                        'closing' => 'I valori finali applicati non coincidono con il riepilogo confermato.',
                    ]);
                }

                $budgets = BudgetSnapshot::query()
                    ->where('company_id', $company->id)
                    ->where('exercise_id', $lockedExercise->id)
                    ->orderBy('version')
                    ->lockForUpdate()
                    ->get();
                $initialBudget = $budgets->first();
                $currentBudget = $budgets->last();
                $nextDisposition = $nextExercise === null
                    ? 'not_created'
                    : ($createdNextExercise ? 'created' : 'already_existed');
                $snapshot = ClosingSnapshot::query()->create([
                    'company_id' => $company->id,
                    'company_name' => $company->name,
                    'exercise_id' => $lockedExercise->id,
                    'exercise_year' => $lockedExercise->year,
                    'closed_at' => now(),
                    'closed_by_id' => $actor->id,
                    'initial_budget_id' => $initialBudget?->id,
                    'current_budget_id' => $currentBudget?->id,
                    'total_final_allocation' => $payload['total_final_allocation'],
                    'total_closing_actual' => $payload['total_closing_actual'],
                    'total_operational_variance' => $payload['total_operational_variance'],
                    'total_consolidated_carryover' => $payload['total_consolidated_carryover'],
                    'accepted_warnings' => $authoritativeReview->warnings,
                    'applied_settings' => $authoritativeReview->appliedSettings,
                    'next_exercise_disposition' => $nextDisposition,
                    'next_exercise_id' => $nextExercise?->id,
                    'operation_id' => $operationId,
                ]);
                foreach ($payload['rows'] as $row) {
                    $snapshot->rows()->create($row);
                }

                $lockedExercise->update(['status' => ExerciseStatus::Closed]);
                $affectedIds = $this->existingAffectedExerciseIds($authoritativeReview->affectedExercises);
                if ($nextExercise !== null && ! in_array($nextExercise->id, $affectedIds, true)) {
                    $affectedIds[] = $nextExercise->id;
                    sort($affectedIds);
                }
                $this->audit(
                    actor: $actor,
                    type: AuditEventType::ExerciseClosed,
                    subject: $lockedExercise,
                    operationId: $operationId,
                    sequence: $sequence,
                    affectedExerciseIds: $affectedIds,
                    effectiveFrom: $lockedExercise->year.'-12-31',
                    previousValue: ['status' => ExerciseStatus::Open->value],
                    newValue: [
                        'status' => ExerciseStatus::Closed->value,
                        'closing_snapshot_id' => $snapshot->id,
                        'totals' => [
                            'allocation' => $payload['total_final_allocation'],
                            'actual' => $payload['total_closing_actual'],
                            'operational_variance' => $payload['total_operational_variance'],
                            'consolidated_carryover' => $payload['total_consolidated_carryover'],
                        ],
                        'next_exercise_disposition' => $nextDisposition,
                        'next_exercise_id' => $nextExercise?->id,
                    ],
                    referenceType: ClosingSnapshot::class,
                    referenceId: $snapshot->id,
                );

                return $snapshot->load('rows');
            }, 3);
        } catch (Throwable $exception) {
            $this->recordFailureAudit($actor, $exercise, $operationId);
            throw $exception;
        }
    }

    private function ensureStartedAudit(User $actor, Exercise $exercise, string $operationId): void
    {
        $existing = AuditEvent::query()->where('operation_id', $operationId)->where('event_sequence', 0)->first();
        if ($existing !== null) {
            if ($existing->eventType() !== AuditEventType::ExerciseClosingStarted
                || $existing->subject_type !== Exercise::class
                || $existing->subject_id !== $exercise->id
                || $existing->company_id !== $exercise->company_id) {
                throw ValidationException::withMessages(['operation_id' => 'Identificativo operazione già utilizzato.']);
            }

            return;
        }

        $this->audit(
            actor: $actor,
            type: AuditEventType::ExerciseClosingStarted,
            subject: $exercise,
            operationId: $operationId,
            sequence: 0,
            affectedExerciseIds: [$exercise->id],
            effectiveFrom: $exercise->year.'-12-31',
            newValue: ['status' => 'started'],
        );
    }

    private function recordFailureAudit(User $actor, Exercise $exercise, string $operationId): void
    {
        if (ClosingSnapshot::query()->where('operation_id', $operationId)->exists()) {
            return;
        }
        $sequence = ((int) AuditEvent::query()->where('operation_id', $operationId)->max('event_sequence')) + 1;
        $this->audit(
            actor: $actor,
            type: AuditEventType::ExerciseClosingFailed,
            subject: $exercise,
            operationId: $operationId,
            sequence: $sequence,
            affectedExerciseIds: [$exercise->id],
            effectiveFrom: $exercise->year.'-12-31',
            newValue: ['status' => 'failed', 'economic_effects_committed' => false],
        );
    }

    private function lockClosingState(Company $company): void
    {
        $exerciseIds = Exercise::query()->where('company_id', $company->id)->orderBy('id')->lockForUpdate()->pluck('id');
        Proposal::query()->where('company_id', $company->id)->orderBy('id')->lockForUpdate()->get();
        BudgetSnapshot::query()->where('company_id', $company->id)->orderBy('id')->lockForUpdate()->get();

        $projectIds = Project::query()->where('company_id', $company->id)->orderBy('id')->lockForUpdate()->pluck('id');
        ProjectTransition::query()->whereIn('project_id', $projectIds)->orderBy('id')->lockForUpdate()->get();
        ProjectExerciseClassification::query()->whereIn('project_id', $projectIds)->orderBy('id')->lockForUpdate()->get();
        ProjectDeferral::query()->whereIn('project_id', $projectIds)->orderBy('id')->lockForUpdate()->get();

        $contractIds = Contract::query()->where('company_id', $company->id)->orderBy('id')->lockForUpdate()->pluck('id');
        ContractRenewalConfiguration::query()->whereIn('contract_id', $contractIds)->orderBy('id')->lockForUpdate()->get();
        ContractLifecycleFact::query()->whereIn('contract_id', $contractIds)->orderBy('id')->lockForUpdate()->get();
        ContractCondition::query()->whereIn('contract_id', $contractIds)->orderBy('id')->lockForUpdate()->get();
        ContractExerciseClassification::query()->whereIn('contract_id', $contractIds)->orderBy('id')->lockForUpdate()->get();

        $expenseIds = Expense::query()->where('company_id', $company->id)->whereIn('exercise_id', $exerciseIds)->orderBy('id')->lockForUpdate()->pluck('id');
        ExpenseLine::query()->whereIn('expense_id', $expenseIds)->orderBy('id')->lockForUpdate()->get();
    }

    /** @param list<int> $projectIds
     * @param  list<int>  $contractIds
     * @return list<int>
     */
    private function proposalIdsToRealign(int $companyId, array $projectIds, array $contractIds): array
    {
        if ($projectIds === [] && $contractIds === []) {
            return [];
        }

        return ProposalItem::query()
            ->where('company_id', $companyId)
            ->where('readiness_state', '!=', 'to_realign')
            ->whereHas('proposal', fn ($query) => $query->where('status', 'draft'))
            ->where(function ($query) use ($projectIds, $contractIds): void {
                if ($projectIds !== []) {
                    $query->orWhereIn('project_id', $projectIds);
                }
                if ($contractIds !== []) {
                    $query->orWhereIn('contract_id', $contractIds);
                }
            })
            ->pluck('proposal_id')
            ->map(fn (mixed $id): int => (int) $id)
            ->unique()
            ->sort()
            ->values()
            ->all();
    }

    /** @param Collection<int, array<string, mixed>> $decisions */
    private function writeProjectBalanceEvents(User $actor, Exercise $exercise, Collection $decisions, string $operationId, int &$sequence): void
    {
        foreach ($decisions as $decision) {
            if (! isset($decision['project_id'], $decision['final_state'])) {
                continue;
            }
            $state = ProjectState::tryFrom((string) $decision['final_state']);
            if (! in_array($state, [ProjectState::Closed, ProjectState::Cancelled], true)) {
                continue;
            }
            $project = Project::query()->find((int) $decision['project_id']);
            if ($project === null) {
                continue;
            }
            $totals = $project->annualTotals()[$exercise->id] ?? ['allocation' => '0.00', 'actual' => '0.00'];
            $balance = ProjectDeferralValues::residual((string) $totals['allocation'], (string) $totals['actual']);
            if (Decimal::compare($balance, '0.00') <= 0) {
                continue;
            }
            $type = $state === ProjectState::Closed
                ? AuditEventType::ProjectClosedWithSaving
                : AuditEventType::ProjectCancelledWithUnusedAllocation;
            $field = $state === ProjectState::Closed ? 'saving' : 'unused_allocation';
            $this->audit(
                actor: $actor,
                type: $type,
                subject: $project,
                operationId: $operationId,
                sequence: $sequence++,
                affectedExerciseIds: [$exercise->id],
                effectiveFrom: $exercise->year.'-12-31',
                newValue: [
                    'project_id' => $project->id,
                    'allocation' => (string) $totals['allocation'],
                    'actual' => (string) $totals['actual'],
                    $field => $balance,
                ],
                referenceType: Exercise::class,
                referenceId: $exercise->id,
            );
        }
    }

    /** @return array<string, list<array{operation_id: string, event_sequence: int}>> */
    private function eventReferences(int $companyId, string $operationId): array
    {
        $events = AuditEvent::query()
            ->where('company_id', $companyId)
            ->where('operation_id', $operationId)
            ->orderBy('event_sequence')
            ->get();
        $references = [];
        foreach ($events as $event) {
            $key = null;
            if ($event->reference_type === Project::class && $event->reference_id !== null) {
                $key = 'project:'.$event->reference_id;
            } elseif ($event->reference_type === Contract::class && $event->reference_id !== null) {
                $key = 'contract:'.$event->reference_id;
            } elseif ($event->subject_type === Project::class) {
                $key = 'project:'.$event->subject_id;
            } elseif ($event->subject_type === Contract::class) {
                $key = 'contract:'.$event->subject_id;
            }
            if ($key === null) {
                continue;
            }
            $references[$key][] = [
                'operation_id' => $operationId,
                'event_sequence' => (int) $event->event_sequence,
            ];
        }

        return $references;
    }

    /** @param list<array<string, mixed>> $affectedExercises
     * @return list<int>
     */
    private function existingAffectedExerciseIds(array $affectedExercises): array
    {
        $ids = [];
        foreach ($affectedExercises as $impact) {
            $id = $impact['exercise_id'] ?? null;
            if (is_int($id) || (is_string($id) && ctype_digit($id))) {
                $ids[] = (int) $id;
            }
        }
        $ids = array_values(array_unique($ids));
        sort($ids);

        return $ids;
    }

    /**
     * @param  list<int>  $affectedExerciseIds
     * @param  array<string, mixed>|null  $previousValue
     * @param  array<string, mixed>|null  $newValue
     */
    private function audit(
        User $actor,
        AuditEventType $type,
        object $subject,
        string $operationId,
        int $sequence,
        array $affectedExerciseIds,
        string $effectiveFrom,
        ?array $previousValue = null,
        ?array $newValue = null,
        ?string $reason = null,
        ?string $referenceType = null,
        ?int $referenceId = null,
    ): void {
        $companyId = (int) $subject->company_id;
        AuditEvent::query()->create([
            'operation_id' => $operationId,
            'event_sequence' => $sequence,
            'company_id' => $companyId,
            'actor_id' => $actor->id,
            'event_type' => $type,
            'subject_type' => $subject::class,
            'subject_id' => (int) $subject->id,
            'affected_exercise_ids' => $affectedExerciseIds,
            'effective_from' => $effectiveFrom,
            'previous_value' => $previousValue,
            'new_value' => $newValue,
            'allocated_impact_by_exercise' => array_fill_keys(array_map('strval', $affectedExerciseIds), '0.00'),
            'actual_impact_by_exercise' => array_fill_keys(array_map('strval', $affectedExerciseIds), '0.00'),
            'reason' => $reason,
            'reference_type' => $referenceType,
            'reference_id' => $referenceId,
        ]);
    }
}
