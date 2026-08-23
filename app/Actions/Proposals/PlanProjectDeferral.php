<?php

namespace App\Actions\Proposals;

use App\Domain\Company\AuditEventType;
use App\Domain\Expenses\Decimal;
use App\Domain\Expenses\ExpenseLineType;
use App\Domain\Projects\ProjectDeferralMode;
use App\Domain\Projects\ProjectDeferralValues;
use App\Domain\Projects\ProjectState;
use App\Domain\Projects\ProjectStateTimeline;
use App\Domain\Proposals\ExpensePlan;
use App\Domain\Proposals\ProjectPlan;
use App\Domain\Proposals\ProposalActionPayload;
use App\Domain\Proposals\ProposalActionType;
use App\Domain\Proposals\ProposalImpactPlan;
use App\Domain\Proposals\ProposalPlanData;
use App\Domain\Proposals\ProposalSourceSnapshot;
use App\Domain\Proposals\ProposalSourceType;
use App\Models\AuditEvent;
use App\Models\Company;
use App\Models\Exercise;
use App\Models\Expense;
use App\Models\ExpenseLine;
use App\Models\Project;
use App\Models\ProjectDeferral;
use App\Models\Proposal;
use App\Models\ProposalAction;
use App\Models\ProposalItem;
use App\Models\Supplier;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

final class PlanProjectDeferral
{
    /** @param array<string, mixed> $input */
    public function execute(User $actor, Proposal $proposal, ProposalItem $item, array $input, ?string $reason, string $operationId, int $expectedRevision): ProposalAction
    {
        if (! Str::isUuid($operationId)) {
            throw ValidationException::withMessages(['operation_id' => 'Identificativo operazione non valido.']);
        }

        return DB::transaction(function () use ($actor, $proposal, $item, $input, $reason, $operationId, $expectedRevision): ProposalAction {
            $company = Company::query()->lockForUpdate()->findOrFail($proposal->company_id);
            $lockedProposal = Proposal::query()->with('company')->lockForUpdate()->findOrFail($proposal->id);
            Gate::forUser($actor)->authorize('update', $lockedProposal);

            $existing = ProposalAction::query()->where('operation_id', $operationId)->first();
            if ($existing !== null) {
                if ($existing->proposal_id !== $lockedProposal->id || $existing->action_type !== ProposalActionType::PlanProjectDeferral) {
                    throw ValidationException::withMessages(['operation_id' => 'Identificativo operazione già utilizzato.']);
                }

                return $existing;
            }
            if ($lockedProposal->revision !== $expectedRevision) {
                throw ValidationException::withMessages(['revision' => 'La Proposta è cambiata: ricaricare prima di continuare.']);
            }

            $lockedItem = ProposalItem::query()->where('proposal_id', $lockedProposal->id)->lockForUpdate()->find($item->id);
            if ($lockedItem === null || $lockedItem->source_type !== ProposalSourceType::Project || $lockedItem->project_id === null) {
                throw ValidationException::withMessages(['item' => 'Elemento Progetto non disponibile in questa Proposta.']);
            }
            $project = Project::query()->where('company_id', $company->id)->lockForUpdate()->findOrFail($lockedItem->project_id);
            $destinationSnapshot = ProposalSourceSnapshot::project($project, $lockedProposal->exercise_id);
            if ((int) $project->revision !== $lockedItem->baseline_revision
                || ProposalSourceSnapshot::fingerprint($destinationSnapshot) !== $lockedItem->baseline_fingerprint) {
                throw ValidationException::withMessages(['source' => 'La realtà del Progetto è cambiata: riallineare l’intera sorgente.']);
            }
            $source = Exercise::query()->where('company_id', $company->id)->lockForUpdate()->find($input['source_exercise_id'] ?? null);
            $destination = Exercise::query()->where('company_id', $company->id)->lockForUpdate()->find($input['destination_exercise_id'] ?? $lockedProposal->exercise_id);
            if ($source === null || $destination === null || $destination->id !== $lockedProposal->exercise_id || $destination->year !== $source->year + 1) {
                throw ValidationException::withMessages(['destination_exercise_id' => 'Il rinvio richiede l’Esercizio immediatamente precedente a quello della Proposta.']);
            }
            if (! $source->isOpen() || ! $destination->isOpen()) {
                throw ValidationException::withMessages(['exercise_id' => 'Gli Esercizi origine e destinazione devono essere Aperti.']);
            }

            $deferral = ProjectDeferral::query()
                ->where('project_id', $project->id)
                ->where('source_exercise_id', $source->id)
                ->where('destination_exercise_id', $destination->id)
                ->lockForUpdate()
                ->first();
            $currentMode = $deferral === null ? ProjectDeferralMode::None : $deferral->mode;
            $mode = ProjectDeferralMode::tryFrom((string) ($input['mode'] ?? ''));
            if ($mode === null) {
                throw ValidationException::withMessages(['mode' => 'Modalità di rinvio non valida.']);
            }
            $reason = filled($reason) ? trim((string) $reason) : null;
            if (($mode !== ProjectDeferralMode::None || $currentMode !== ProjectDeferralMode::None) && $reason === null) {
                throw ValidationException::withMessages(['reason' => 'La motivazione del rinvio è obbligatoria.']);
            }
            if ($currentMode === ProjectDeferralMode::Reprogramming && $mode === ProjectDeferralMode::Reprogramming) {
                throw ValidationException::withMessages(['mode' => 'Una Riprogrammazione già applicata deve essere prima sostituita o rimossa.']);
            }
            if ($mode !== ProjectDeferralMode::None && in_array($this->resultingStateAt($lockedItem->result, $source->year.'-12-31'), [ProjectState::Closed, ProjectState::Cancelled], true)) {
                throw ValidationException::withMessages(['mode' => 'Un Progetto terminale al 31 dicembre può usare soltanto Nessuna.']);
            }

            $sourceExpenses = Expense::query()
                ->where('company_id', $company->id)
                ->where('project_id', $project->id)
                ->where('exercise_id', $source->id)
                ->orderBy('id')
                ->lockForUpdate()
                ->get();
            $sourceLines = ExpenseLine::query()
                ->whereIn('expense_id', $sourceExpenses->pluck('id'))
                ->orderBy('id')
                ->lockForUpdate()
                ->get();
            $project->unsetRelation('expenses');
            $project->unsetRelation('deferrals');
            $sourceSnapshot = ProposalSourceSnapshot::project($project, $source->id);
            $totals = $project->annualTotals()[$source->id] ?? ['allocation' => '0.00', 'actual' => '0.00'];
            $availabilityAllocation = $deferral?->mode === ProjectDeferralMode::Reprogramming && $mode === ProjectDeferralMode::Carryover
                ? Decimal::add($totals['allocation'], (string) $deferral->reprogrammed_amount)
                : $totals['allocation'];
            $maximum = ProjectDeferralValues::maximumTransferable($availabilityAllocation, $totals['actual']);

            $reductions = [];
            $destinationPlans = [];
            $carryover = '0.00';
            $reprogrammed = '0.00';
            if ($mode === ProjectDeferralMode::Carryover) {
                $carryover = Decimal::money((string) ($input['carryover_amount'] ?? '0'));
                if (Decimal::compare($carryover, '0.00') <= 0 || Decimal::compare($carryover, $maximum) > 0) {
                    throw ValidationException::withMessages(['carryover_amount' => 'Il Riporto deve essere positivo e non superiore al massimo corrente.']);
                }
            } elseif ($mode === ProjectDeferralMode::Reprogramming) {
                if (! ExpensePlan::plannedProjectAcceptsExpense($lockedItem->result, $destination)) {
                    throw ValidationException::withMessages(['project' => 'Il Progetto destinazione deve poter ricevere nuova pianificazione.']);
                }
                [$reductions, $destinationPlans, $reprogrammed] = $this->buildReprogramming(
                    $company,
                    $sourceExpenses->keyBy('id'),
                    $sourceLines->keyBy('id'),
                    $input['source_estimate_reductions'] ?? null,
                );
                $declared = Decimal::money((string) ($input['reprogrammed_amount'] ?? $reprogrammed));
                if (Decimal::compare($declared, $reprogrammed) !== 0) {
                    throw ValidationException::withMessages(['reprogrammed_amount' => 'La Riprogrammazione non è bilanciata.']);
                }
                if (Decimal::compare($reprogrammed, '0.00') <= 0 || Decimal::compare($reprogrammed, $maximum) > 0) {
                    throw ValidationException::withMessages(['reprogrammed_amount' => 'La Riprogrammazione supera l’importo disponibile.']);
                }
            }

            $referenced = collect($reductions)->map(fn (array $reduction): array => [
                'expense_id' => $reduction['source_expense_id'],
                'expense_revision' => $reduction['source_expense_revision'],
                'expense_line_id' => $reduction['source_line_id'],
                'line_revision' => $reduction['source_line_revision'],
                'amount' => $reduction['source_amount'],
                'annulled' => $reduction['source_annulled'],
            ])->all();
            $payload = ProposalActionPayload::validate(ProposalActionType::PlanProjectDeferral, [
                'source_exercise_id' => $source->id,
                'destination_exercise_id' => $destination->id,
                'mode' => $mode->value,
                'carryover_amount' => $carryover,
                'reprogrammed_amount' => $reprogrammed,
                'source_estimate_reductions' => $reductions,
                'destination_plans' => $destinationPlans,
                'source_context' => [
                    'source_exercise_id' => $source->id,
                    'project_revision' => (int) $project->revision,
                    'project_fingerprint' => ProposalSourceSnapshot::fingerprint($sourceSnapshot),
                    'allocation' => $availabilityAllocation,
                    'actual' => $totals['actual'],
                    'maximum_transferable' => $maximum,
                    'referenced_estimates' => $referenced,
                ],
                ...($deferral?->mode === ProjectDeferralMode::Reprogramming ? [
                    'active_reprogramming_operation_id' => $deferral->reprogramming_operation_id,
                    'active_reprogramming_fingerprint' => ProposalSourceSnapshot::fingerprint([
                        'operation_id' => $deferral->reprogramming_operation_id,
                        'effects' => $deferral->reprogramming_effects,
                    ]),
                ] : []),
            ]);

            $previous = $lockedItem->result;
            $lockedItem->update(['result' => ProjectPlan::apply($lockedProposal, $lockedItem, ProposalActionType::PlanProjectDeferral, $payload)]);
            $sequence = ((int) $lockedProposal->actionHistory()->max('sequence')) + 1;
            $action = $lockedProposal->actions()->create([
                'company_id' => $company->id,
                'proposal_item_id' => $lockedItem->id,
                'sequence' => $sequence,
                'action_type' => ProposalActionType::PlanProjectDeferral,
                'payload_version' => 1,
                'payload' => $payload,
                'reason' => $reason,
                'created_by_id' => $actor->id,
                'operation_id' => $operationId,
            ]);
            $lockedProposal->increment('revision');
            $impacts = ProposalImpactPlan::build($lockedProposal->fresh(['company', 'exercise', 'items.actions', 'items.expense', 'items.project', 'items.contract']));
            $exerciseIds = collect($impacts)->pluck('exercise_id')->map(fn (mixed $id): int => (int) $id)->all();
            AuditEvent::query()->create([
                'operation_id' => $operationId,
                'company_id' => $company->id,
                'actor_id' => $actor->id,
                'event_type' => AuditEventType::ProposalActionPlanned,
                'subject_type' => Proposal::class,
                'subject_id' => $lockedProposal->id,
                'affected_exercise_ids' => $exerciseIds,
                'effective_from' => now($company->timezone)->toDateString(),
                'previous_value' => ['proposal_item_id' => $lockedItem->proposal_item_id, 'plan' => $previous],
                'new_value' => ['proposal_item_id' => $lockedItem->proposal_item_id, 'action_type' => ProposalActionType::PlanProjectDeferral->value, 'payload_version' => 1, 'payload' => $payload, 'result' => $lockedItem->refresh()->result],
                'allocated_impact_by_exercise' => collect($impacts)->mapWithKeys(fn (array $impact): array => [(string) $impact['exercise_id'] => $impact['allocation_delta']])->all(),
                'actual_impact_by_exercise' => collect($exerciseIds)->mapWithKeys(fn (int $id): array => [(string) $id => '0.00'])->all(),
                'reason' => $reason,
            ]);

            return $action;
        }, 3);
    }

    /**
     * @param  Collection<int, Expense>  $expenses
     * @param  Collection<int, ExpenseLine>  $lines
     * @return array{list<array<string, mixed>>, list<array<string, mixed>>, string}
     */
    public function buildReprogramming(Company $company, $expenses, $lines, mixed $input): array
    {
        if (! is_array($input) || $input === []) {
            throw ValidationException::withMessages(['source_estimate_reductions' => 'Selezionare almeno una Riga Stima origine.']);
        }

        $reductions = [];
        $groups = [];
        $seen = [];
        foreach ($input as $index => $requested) {
            if (! is_array($requested) || ! isset($requested['source_line_id']) || ! array_key_exists('reduction_amount', $requested)) {
                throw ValidationException::withMessages(["source_estimate_reductions.$index" => 'Riduzione origine non valida.']);
            }
            $lineId = (int) $requested['source_line_id'];
            if (isset($seen[$lineId])) {
                throw ValidationException::withMessages(['source_estimate_reductions' => 'Ogni Riga Stima origine può essere selezionata una sola volta.']);
            }
            $seen[$lineId] = true;
            $line = $lines->get($lineId);
            $expense = $line === null ? null : $expenses->get((int) $line->expense_id);
            if ($line === null || $expense === null || $expense->isReversed() || $line->lineType() !== ExpenseLineType::Estimate || $line->isAnnulled()) {
                throw ValidationException::withMessages(["source_estimate_reductions.$index" => 'La Riga Stima origine non è attiva e riducibile.']);
            }
            $amount = Decimal::money((string) $requested['reduction_amount']);
            if (Decimal::compare($amount, '0.00') <= 0 || Decimal::compare($amount, (string) $line->amount) > 0) {
                throw ValidationException::withMessages(["source_estimate_reductions.$index.reduction_amount" => 'La riduzione deve essere positiva e non superiore alla Stima origine.']);
            }

            $supplierChoiceProvided = array_key_exists('destination_supplier_id', $requested);
            $supplierId = $supplierChoiceProvided ? $requested['destination_supplier_id'] : $expense->supplier_id;
            if ($expense->supplier_id !== null && $expense->supplier?->isArchived() && ! $supplierChoiceProvided) {
                throw ValidationException::withMessages(["source_estimate_reductions.$index.destination_supplier_id" => 'Confermare Nessun Fornitore oppure scegliere un Fornitore attivo.']);
            }
            if (filled($supplierId) && Supplier::query()->where('company_id', $company->id)->whereNull('archived_at')->whereKey($supplierId)->doesntExist()) {
                throw ValidationException::withMessages(["source_estimate_reductions.$index.destination_supplier_id" => 'Fornitore attivo non disponibile nella stessa Azienda.']);
            }
            $expenseId = (int) $expense->id;
            if (isset($groups[$expenseId]) && $groups[$expenseId]['supplier_id'] !== ($supplierId === null ? null : (int) $supplierId)) {
                throw ValidationException::withMessages(['source_estimate_reductions' => 'Usare una sola scelta Fornitore per ogni Spesa origine.']);
            }
            $groups[$expenseId] ??= [
                'proposal_destination_id' => (string) Str::uuid(),
                'copied_from_origin_key' => $expense->originKey(),
                'description' => $expense->description,
                'notes' => $expense->notes,
                'supplier_id' => $supplierId === null ? null : (int) $supplierId,
                'estimate_lines' => [],
            ];
            $proposalLineId = (string) Str::uuid();
            $groups[$expenseId]['estimate_lines'][] = [
                'proposal_line_id' => $proposalLineId,
                'source_line_id' => $line->id,
                'amount' => $amount,
                'note' => $line->note,
            ];
            $reductions[] = [
                'source_expense_id' => $expense->id,
                'source_expense_origin_key' => $expense->originKey(),
                'source_expense_revision' => (int) $expense->revision,
                'source_line_id' => $line->id,
                'source_line_revision' => (int) $line->revision,
                'source_amount' => (string) $line->amount,
                'source_annulled' => false,
                'reduction_amount' => $amount,
            ];
        }

        return [$reductions, array_values($groups), Decimal::sum(collect($reductions)->pluck('reduction_amount'))];
    }

    /** @param array<string, mixed> $result */
    private function resultingStateAt(array $result, string $date): ?ProjectState
    {
        $transitions = [
            ...ProposalPlanData::rows($result['transitions'] ?? null, 'transitions'),
            ...collect(ProposalPlanData::rows($result['planned_transitions'] ?? null, 'planned_transitions'))
                ->map(fn (array $transition): array => [...$transition, 'annulled_at' => null])
                ->all(),
        ];

        return ProjectStateTimeline::stateAtDate(
            ProjectState::from((string) $result['initial_state']),
            (string) $result['initial_effective_date'],
            $transitions,
            $date,
        );
    }
}
