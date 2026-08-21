<?php

namespace App\Domain\Proposals;

use App\Domain\Projects\ProjectState;
use App\Domain\Projects\ProjectStateTimeline;
use App\Models\CostCenter;
use App\Models\Exercise;
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
        throw ValidationException::withMessages(['action_type' => 'Azione Progetto non valida.']);
    }
}
