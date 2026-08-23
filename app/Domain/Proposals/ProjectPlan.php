<?php

namespace App\Domain\Proposals;

use App\Domain\Expenses\Decimal;
use App\Domain\Expenses\ExpenseLineType;
use App\Domain\Projects\ProjectDeferralMode;
use App\Domain\Projects\ProjectDeferralValues;
use App\Domain\Projects\ProjectState;
use App\Domain\Projects\ProjectStateTimeline;
use App\Models\CostCenter;
use App\Models\Exercise;
use App\Models\ExpenseLine;
use App\Models\Project;
use App\Models\ProjectDeferral;
use App\Models\Proposal;
use App\Models\ProposalItem;
use Illuminate\Validation\ValidationException;

final class ProjectPlan
{
    public static function validateForApproval(ProposalItem $item): void
    {
        $result = $item->result;
        $timeline = collect(ProposalPlanData::rows($result['transitions'] ?? null, 'transitions'))->map(fn (array $transition): array => [
            ...$transition, 'annulled_at' => $transition['annulled_at'] ?? null,
        ])->concat(collect(ProposalPlanData::rows($result['planned_transitions'] ?? null, 'planned_transitions'))->map(fn (array $transition): array => [
            ...$transition, 'annulled_at' => null,
        ]))->all();
        try {
            ProjectStateTimeline::validate(ProjectState::from((string) $result['initial_state']), (string) $result['initial_effective_date'], $timeline);
        } catch (\DomainException $exception) {
            throw ValidationException::withMessages(['transitions' => $exception->getMessage()]);
        }
        if ($item->project === null && ProjectState::from((string) $result['initial_state']) !== ProjectState::Planned) {
            throw ValidationException::withMessages(['initial_state' => 'Un nuovo Progetto della Proposta deve essere Pianificato.']);
        }
        if ($item->project?->isArchived() && collect(ProposalPlanData::rows($result['planned_transitions'] ?? null, 'planned_transitions'))->doesntContain(
            fn (array $transition): bool => in_array($transition['to_state'] ?? null, ['planned', 'open'], true),
        )) {
            throw ValidationException::withMessages(['archived' => 'Il Progetto Archiviato richiede una riapertura esplicita.']);
        }
        $exercise = Exercise::query()->where('company_id', $item->company_id)->find($result['exercise_id'] ?? $item->proposal->exercise_id);
        if ($exercise === null || ! $exercise->isOpen()) {
            throw ValidationException::withMessages(['exercise_id' => 'La pianificazione del Progetto richiede un Esercizio Aperto della stessa Azienda.']);
        }
        if (filled($result['cost_center_id'] ?? null) && CostCenter::query()->where('company_id', $item->company_id)->whereNull('archived_at')->whereKey($result['cost_center_id'])->doesntExist()) {
            throw ValidationException::withMessages(['cost_center_id' => 'Centro di Costo attivo non disponibile nella stessa Azienda.']);
        }
        if ((bool) data_get($item->baseline, 'actual_context.has_actuals', false)
            && array_key_exists('cost_center_id', $result)
            && ($result['cost_center_id'] ?? null) !== data_get($item->baseline, 'plan_baseline.classification.0.cost_center_id')) {
            throw ValidationException::withMessages(['cost_center_id' => 'Il Progetto possiede Effettivi e non può essere riclassificato dalla Proposta.']);
        }
        foreach (ProposalPlanData::rows($result['expense_plan'] ?? null, 'expense_plan') as $expense) {
            foreach (ProposalPlanData::rows($expense['estimate_lines'] ?? null, 'estimate_lines') as $line) {
                if (! is_numeric($line['amount'] ?? null) || bccomp((string) $line['amount'], '0', 2) < 0) {
                    throw ValidationException::withMessages(['expense_plan' => 'Le Stime delle Spese figlie devono essere complete e non negative.']);
                }
            }
        }

        self::validateDeferralForApproval($item, $timeline);
    }

    /** @param array<int, array<string, mixed>> $timeline */
    private static function validateDeferralForApproval(ProposalItem $item, array $timeline): void
    {
        $action = $item->actions->where('action_type', ProposalActionType::PlanProjectDeferral)->sortByDesc('sequence')->first();
        if ($action === null) {
            return;
        }
        $payload = ProposalActionPayload::validate($action->action_type, $action->payload);
        $source = Exercise::query()->where('company_id', $item->company_id)->find($payload['source_exercise_id']);
        $destination = Exercise::query()->where('company_id', $item->company_id)->find($payload['destination_exercise_id']);
        if ($source === null || $destination === null || $destination->id !== $item->proposal->exercise_id || $destination->year !== $source->year + 1
            || ! $source->isOpen() || ! $destination->isOpen()) {
            throw ValidationException::withMessages(['exercise_id' => 'Il rinvio richiede due Esercizi consecutivi e Aperti.']);
        }
        $project = Project::query()->where('company_id', $item->company_id)->find($item->project_id);
        if ($project === null) {
            throw ValidationException::withMessages(['project' => 'Progetto del rinvio non disponibile.']);
        }
        $totals = $project->annualTotals()[$source->id] ?? ['allocation' => '0.00', 'actual' => '0.00'];
        $liveDeferral = ProjectDeferral::query()
            ->where('project_id', $project->id)
            ->where('source_exercise_id', $source->id)
            ->where('destination_exercise_id', $destination->id)
            ->first();
        $availabilityAllocation = $liveDeferral?->mode === ProjectDeferralMode::Reprogramming
            && $payload['mode'] === ProjectDeferralMode::Carryover->value
                ? Decimal::add($totals['allocation'], (string) $liveDeferral->reprogrammed_amount)
                : $totals['allocation'];
        $maximum = ProjectDeferralValues::maximumTransferable($availabilityAllocation, $totals['actual']);

        $mode = ProjectDeferralMode::from($payload['mode']);
        if (($mode !== ProjectDeferralMode::None || self::liveMode($project, $source, $destination) !== ProjectDeferralMode::None)
            && blank($action->reason)) {
            throw ValidationException::withMessages(['reason' => 'La motivazione del rinvio è obbligatoria.']);
        }
        $state = ProjectStateTimeline::stateAtDate(
            ProjectState::from((string) $item->result['initial_state']),
            (string) $item->result['initial_effective_date'],
            $timeline,
            $source->year.'-12-31',
        );
        if ($mode !== ProjectDeferralMode::None && in_array($state, [ProjectState::Closed, ProjectState::Cancelled], true)) {
            throw ValidationException::withMessages(['transitions' => 'Un Progetto terminale al 31 dicembre richiede modalità Nessuna.']);
        }

        if ($mode === ProjectDeferralMode::Carryover) {
            if (Decimal::compare((string) $payload['carryover_amount'], $maximum) > 0) {
                throw ValidationException::withMessages(['carryover_amount' => 'Il Riporto supera il limite disponibile.']);
            }
        } elseif ($mode === ProjectDeferralMode::Reprogramming) {
            if (Decimal::compare((string) $payload['reprogrammed_amount'], $maximum) > 0) {
                throw ValidationException::withMessages(['reprogrammed_amount' => 'La Riprogrammazione supera l’importo disponibile.']);
            }
            if (! ExpensePlan::plannedProjectAcceptsExpense($item->result, $destination)) {
                throw ValidationException::withMessages(['transitions' => 'Il Progetto destinazione non può ricevere nuova pianificazione.']);
            }
            self::validateReprogrammingBalance($project, $source, $payload);
        }

        if (isset($payload['active_reprogramming_operation_id'], $payload['active_reprogramming_fingerprint'])) {
            $live = ProjectDeferral::query()
                ->where('project_id', $project->id)
                ->where('source_exercise_id', $source->id)
                ->where('destination_exercise_id', $destination->id)
                ->first();
            $fingerprint = $live === null ? null : ProposalSourceSnapshot::fingerprint([
                'operation_id' => $live->reprogramming_operation_id,
                'effects' => $live->reprogramming_effects,
            ]);
            if ($live?->mode !== ProjectDeferralMode::Reprogramming
                || $live->reprogramming_operation_id !== $payload['active_reprogramming_operation_id']
                || $fingerprint !== $payload['active_reprogramming_fingerprint']) {
                throw ValidationException::withMessages(['source' => 'La Riprogrammazione attiva è cambiata: riallineare l’intera sorgente.']);
            }
        }
    }

    /** @param array<string, mixed> $payload */
    private static function validateReprogrammingBalance(Project $project, Exercise $source, array $payload): void
    {
        $reductionTotal = '0.00';
        $destinationTotal = '0.00';
        foreach ($payload['source_estimate_reductions'] as $reduction) {
            $line = ExpenseLine::query()->with('expense')->find($reduction['source_line_id'] ?? null);
            if ($line === null || $line->expense?->company_id !== $project->company_id
                || $line->expense->project_id !== $project->id || $line->expense->exercise_id !== $source->id
                || $line->expense->isReversed() || $line->lineType() !== ExpenseLineType::Estimate || $line->isAnnulled()
                || (int) $line->expense->revision !== (int) $reduction['source_expense_revision']
                || (int) $line->revision !== (int) $reduction['source_line_revision']
                || Decimal::compare((string) $line->amount, (string) $reduction['source_amount']) !== 0
                || Decimal::compare((string) $reduction['reduction_amount'], '0.00') <= 0
                || Decimal::compare((string) $reduction['reduction_amount'], (string) $line->amount) > 0) {
                throw ValidationException::withMessages(['source' => 'Una Riga Stima origine della Riprogrammazione è cambiata.']);
            }
            $reductionTotal = Decimal::add($reductionTotal, (string) $reduction['reduction_amount']);
        }
        foreach ($payload['destination_plans'] as $plan) {
            foreach ($plan['estimate_lines'] ?? [] as $line) {
                $destinationTotal = Decimal::add($destinationTotal, (string) ($line['amount'] ?? '0'));
            }
        }
        if (Decimal::compare($reductionTotal, (string) $payload['reprogrammed_amount']) !== 0
            || Decimal::compare($destinationTotal, (string) $payload['reprogrammed_amount']) !== 0) {
            throw ValidationException::withMessages(['reprogramming_balance' => 'La Riprogrammazione non è bilanciata.']);
        }
    }

    private static function liveMode(Project $project, Exercise $source, Exercise $destination): ProjectDeferralMode
    {
        $mode = ProjectDeferral::query()
            ->where('project_id', $project->id)
            ->where('source_exercise_id', $source->id)
            ->where('destination_exercise_id', $destination->id)
            ->value('mode');

        if ($mode instanceof ProjectDeferralMode) {
            return $mode;
        }

        return $mode === null ? ProjectDeferralMode::None : ProjectDeferralMode::from((string) $mode);
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public static function create(array $payload): array
    {
        if (ProjectState::from((string) $payload['initial_state']) !== ProjectState::Planned) {
            throw ValidationException::withMessages(['initial_state' => 'Un nuovo Progetto della Proposta deve essere Pianificato.']);
        }

        return [...$payload, 'planned_transitions' => [], 'child_item_ids' => []];
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public static function apply(Proposal $proposal, ProposalItem $item, ProposalActionType $type, array $payload): array
    {
        if ($item->source_type !== ProposalSourceType::Project) {
            throw ValidationException::withMessages(['item' => 'L’Elemento non è un Progetto.']);
        }
        $result = $item->result;
        if ($type === ProposalActionType::PlanProjectChildExpenses) {
            foreach ($payload['child_item_ids'] ?? [] as $id) {
                $child = ProposalItemReference::item($proposal, (string) $id, 'expense');
                if (($child->result['project_item_id'] ?? null) !== $item->proposal_item_id) {
                    throw ValidationException::withMessages(['child_item_ids' => 'La Spesa figlia non riferisce questo Progetto.']);
                }
            }
            $expensePlan = collect(ProposalPlanData::rows($result['expense_plan'] ?? null, 'expense_plan'))->keyBy('origin_key');
            foreach (ProposalPlanData::rows($payload['existing_expenses'] ?? null, 'existing_expenses') as $expense) {
                $key = 'expense:'.(int) $expense['expense_id'];
                if (! $expensePlan->has($key)) {
                    throw ValidationException::withMessages(['existing_expenses' => 'La Spesa non appartiene al piano di questo Progetto.']);
                }
                $current = $expensePlan->get($key);
                $expensePlan->put($key, [...$current, 'estimate_lines' => $expense['estimate_lines'], 'estimate_lines_changed' => true]);
            }

            return [...$result, 'expense_plan' => $expensePlan->values()->all(), 'child_item_ids' => array_values($payload['child_item_ids'] ?? [])];
        }
        if ($type === ProposalActionType::SetProjectCostCenter) {
            if ((bool) data_get($item->baseline, 'actual_context.has_actuals', false)) {
                throw ValidationException::withMessages(['cost_center_id' => 'Il Progetto possiede Effettivi nell’Esercizio e non può essere riclassificato dalla Proposta.']);
            }
            $exercise = Exercise::query()->where('company_id', $proposal->company_id)->find($payload['exercise_id'] ?? $proposal->exercise_id);
            if ($exercise === null || ! $exercise->isOpen()) {
                throw ValidationException::withMessages(['exercise_id' => 'Il Centro di Costo può cambiare soltanto in un Esercizio Aperto della stessa Azienda.']);
            }
            if (filled($payload['cost_center_id'] ?? null) && CostCenter::query()->where('company_id', $proposal->company_id)->whereNull('archived_at')->whereKey($payload['cost_center_id'])->doesntExist()) {
                throw ValidationException::withMessages(['cost_center_id' => 'Centro di Costo attivo non disponibile nella stessa Azienda.']);
            }

            return [...$result, 'exercise_id' => $payload['exercise_id'] ?? $proposal->exercise_id, 'cost_center_id' => $payload['cost_center_id'] ?? null];
        }
        if ($type === ProposalActionType::PlanProjectTransition) {
            $from = ProjectState::from((string) $payload['from_state']);
            $to = ProjectState::from((string) $payload['to_state']);
            $allowed = match ($from) {
                ProjectState::Planned => [ProjectState::Open, ProjectState::Cancelled], ProjectState::Open => [ProjectState::Closed, ProjectState::Cancelled], ProjectState::Closed => [ProjectState::Open], ProjectState::Cancelled => [ProjectState::Planned, ProjectState::Open]
            };
            if (! in_array($to, $allowed, true)) {
                throw ValidationException::withMessages(['to_state' => 'Transizione Progetto non ammessa.']);
            }
            $planned = [...ProposalPlanData::rows($result['planned_transitions'] ?? null, 'planned_transitions'), $payload];
            $timeline = collect(ProposalPlanData::rows($result['transitions'] ?? null, 'transitions'))->map(fn (array $transition): array => [
                'from_state' => $transition['from_state'], 'to_state' => $transition['to_state'], 'effective_date' => $transition['effective_date'], 'annulled_at' => $transition['annulled_at'] ?? null,
            ])->concat(collect($planned)->map(fn (array $transition): array => [...$transition, 'annulled_at' => null]))->all();
            try {
                ProjectStateTimeline::validate(ProjectState::from((string) $result['initial_state']), (string) $result['initial_effective_date'], $timeline);
            } catch (\DomainException $exception) {
                throw ValidationException::withMessages(['effective_date' => $exception->getMessage()]);
            }

            return [...$result, 'planned_transitions' => $planned];
        }
        if ($type === ProposalActionType::PlanProjectDeferral) {
            return [
                ...$result,
                'incoming_deferral' => [
                    'source_exercise_id' => $payload['source_exercise_id'],
                    'destination_exercise_id' => $payload['destination_exercise_id'],
                    'mode' => $payload['mode'],
                    'carryover_amount' => $payload['carryover_amount'],
                    'carryover_state' => $payload['mode'] === 'carryover' ? 'provisional' : null,
                    'reprogrammed_amount' => $payload['reprogrammed_amount'],
                    'reprogramming_operation_id' => null,
                    'source_estimate_reductions' => $payload['source_estimate_reductions'],
                    'destination_plans' => $payload['destination_plans'],
                    'active_reprogramming_operation_id' => $payload['active_reprogramming_operation_id'] ?? null,
                    'active_reprogramming_fingerprint' => $payload['active_reprogramming_fingerprint'] ?? null,
                ],
            ];
        }
        throw ValidationException::withMessages(['action_type' => 'Azione Progetto non valida.']);
    }
}
