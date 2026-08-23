<?php

namespace App\Domain\Proposals;

use App\Domain\Projects\ProjectState;
use App\Domain\Projects\ProjectStateTimeline;
use App\Models\CostCenter;
use App\Models\Exercise;
use App\Models\Project;
use App\Models\Proposal;
use App\Models\ProposalItem;
use App\Models\Supplier;
use Illuminate\Validation\ValidationException;

final class ExpensePlan
{
    public static function validateForApproval(ProposalItem $item): void
    {
        self::validateResult($item->proposal, $item, $item->result, null);
        $baseline = (array) data_get($item->baseline, 'plan_baseline', []);
        if ((bool) data_get($item->baseline, 'actual_context.has_actuals', false)) {
            foreach (['exercise_id', 'project_id', 'contract_id', 'supplier_id', 'cost_center_id'] as $key) {
                if (($item->result[$key] ?? null) !== ($baseline[$key] ?? null)) {
                    throw ValidationException::withMessages([$key => 'La Proposta modificherebbe una classificazione di una Spesa con Effettivi.']);
                }
            }
            if ((bool) ($item->result['reversed'] ?? filled($item->result['reversed_at'] ?? null)) !== filled($baseline['reversed_at'] ?? null)) {
                throw ValidationException::withMessages(['reversed' => 'La Proposta modificherebbe lo Storno di una Spesa con Effettivi.']);
            }
        }
        foreach ($item->result['estimate_lines'] ?? [] as $line) {
            if (! is_array($line) || ! is_numeric($line['amount'] ?? null) || bccomp((string) $line['amount'], '0', 2) < 0) {
                throw ValidationException::withMessages(['estimate_lines' => 'Le Righe Stima devono essere complete e non negative.']);
            }
        }
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public static function apply(ProposalItem $item, ProposalActionType $type, array $payload): array
    {
        if ($item->source_type !== ProposalSourceType::Expense) {
            throw ValidationException::withMessages(['item' => 'L’Elemento non è una Spesa.']);
        }
        $hasActuals = (bool) data_get($item->baseline, 'actual_context.has_actuals', false);
        if ($hasActuals && in_array($type, [ProposalActionType::SetExpenseOwner, ProposalActionType::SetExpenseSupplier, ProposalActionType::SetExpenseCostCenter, ProposalActionType::ReverseExpense, ProposalActionType::RestoreExpense], true)) {
            throw ValidationException::withMessages(['action_type' => 'La Spesa contiene Effettivi: contenitore, Esercizio, Fornitore, Centro di Costo e Storno non sono modificabili dalla Proposta.']);
        }
        $current = $item->result;
        $result = match ($type) {
            ProposalActionType::SetExpenseEstimates => [...$current, 'estimate_lines' => $payload['estimate_lines']],
            ProposalActionType::SetExpenseOwner => [...$current, 'exercise_id' => $payload['exercise_id'] ?? $current['exercise_id'] ?? null, 'project_id' => $payload['project_id'] ?? null, 'project_item_id' => $payload['project_item_id'] ?? null, 'contract_id' => null, 'cost_center_id' => filled($payload['project_id'] ?? null) || filled($payload['project_item_id'] ?? null) ? null : ($current['cost_center_id'] ?? null)],
            ProposalActionType::SetExpenseSupplier => [...$current, 'supplier_id' => $payload['supplier_id'] ?? null],
            ProposalActionType::SetExpenseCostCenter => [...$current, 'cost_center_id' => $payload['cost_center_id'] ?? null],
            ProposalActionType::ReverseExpense => [...$current, 'reversed' => true],
            ProposalActionType::RestoreExpense => [...$current, 'reversed' => false],
            default => throw ValidationException::withMessages(['action_type' => 'Azione Spesa non valida per un elemento esistente.']),
        };

        self::validateResult($item->proposal, $item, $result, $type);

        return $result;
    }

    /** @param array<string, mixed> $result */
    public static function validateNew(Proposal $proposal, array $result, ProposalActionType $type = ProposalActionType::CreateExpense): void
    {
        self::validateResult($proposal, null, $result, $type);

        $projectId = filled($result['project_id'] ?? null) ? (int) $result['project_id'] : null;
        $projectItemId = filled($result['project_item_id'] ?? null) ? (string) $result['project_item_id'] : null;
        if ($type === ProposalActionType::CreateExpense && $projectId !== null) {
            throw ValidationException::withMessages(['project_id' => 'Per una nuova Spesa di un Progetto già vivo usare Nuova allocazione.']);
        }
        if ($type === ProposalActionType::CreateProjectAllocation
            && ($projectId === null || $projectItemId !== null || (int) ($result['exercise_id'] ?? $proposal->exercise_id) !== $proposal->exercise_id)) {
            throw ValidationException::withMessages(['project_id' => 'Nuova allocazione richiede un Progetto già vivo nell’Esercizio principale della Proposta.']);
        }
    }

    /** @param array<string, mixed> $result */
    private static function validateResult(Proposal $proposal, ?ProposalItem $item, array $result, ?ProposalActionType $type): void
    {
        $exercise = Exercise::query()
            ->where('company_id', $proposal->company_id)
            ->find($result['exercise_id'] ?? $proposal->exercise_id);
        if ($exercise === null || ! $exercise->isOpen()) {
            throw ValidationException::withMessages(['exercise_id' => 'La Spesa pianificata richiede un Esercizio Aperto della stessa Azienda.']);
        }

        $projectId = filled($result['project_id'] ?? null) ? (int) $result['project_id'] : null;
        $projectItemId = filled($result['project_item_id'] ?? null) ? (string) $result['project_item_id'] : null;
        if ($projectId !== null && $projectItemId !== null) {
            throw ValidationException::withMessages(['project_id' => 'Indicare un solo Progetto di destinazione.']);
        }
        if ($projectItemId !== null) {
            $projectItem = ProposalItemReference::item($proposal, $projectItemId, 'project');
            if (! self::plannedProjectAcceptsExpense($projectItem->result, $exercise)) {
                throw ValidationException::withMessages(['project_item_id' => 'Il Progetto proposto deve essere Pianificato o Aperto nell’Esercizio della Spesa.']);
            }
        }
        if ($projectId !== null) {
            $project = Project::query()->where('company_id', $proposal->company_id)->find($projectId);
            $projectItem = $proposal->items()->where('source_type', 'project')->where('project_id', $projectId)->first();
            if ($project === null || ($project->isArchived() && $projectItem === null)) {
                throw ValidationException::withMessages(['project_id' => 'Progetto di destinazione non disponibile nella stessa Azienda.']);
            }
            $yearStart = $exercise->year.'-01-01';
            $yearEnd = $exercise->year.'-12-31';
            $acceptsPlan = $projectItem !== null
                ? self::plannedProjectAcceptsExpense($projectItem->result, $exercise)
                : in_array($project->stateAtDate($yearStart), [ProjectState::Planned, ProjectState::Open], true)
                    || in_array($project->stateAtDate($yearEnd), [ProjectState::Planned, ProjectState::Open], true);
            if (! $acceptsPlan) {
                throw ValidationException::withMessages(['project_id' => 'Il Progetto deve essere Pianificato o Aperto nell’Esercizio.']);
            }
        }

        if ($item !== null && $type === ProposalActionType::SetExpenseOwner) {
            $sourceExerciseId = (int) data_get($item->baseline, 'plan_baseline.exercise_id', $proposal->exercise_id);
            $sourceProjectId = data_get($item->baseline, 'plan_baseline.project_id');
            $sourceContractId = data_get($item->baseline, 'plan_baseline.contract_id');
            if ($sourceContractId !== null) {
                throw ValidationException::withMessages(['item' => 'Il cambio contenitore di una Spesa di Contratto non è rappresentato in S6.']);
            }
            if ($sourceExerciseId !== $exercise->id && ($sourceProjectId !== null || $projectId !== null || $projectItemId !== null)) {
                throw ValidationException::withMessages(['exercise_id' => 'Il cambio Esercizio è ammesso soltanto per una Spesa autonoma; per il Progetto serve la Riprogrammazione S8.']);
            }
            $sourceExercise = Exercise::query()->where('company_id', $proposal->company_id)->find($sourceExerciseId);
            if ($sourceExercise === null || ! $sourceExercise->isOpen()) {
                throw ValidationException::withMessages(['exercise_id' => 'Gli Esercizi origine e destinazione devono essere Aperti.']);
            }
        }

        if (filled($result['supplier_id'] ?? null)
            && Supplier::query()->where('company_id', $proposal->company_id)->whereNull('archived_at')->whereKey($result['supplier_id'])->doesntExist()) {
            throw ValidationException::withMessages(['supplier_id' => 'Fornitore attivo non disponibile nella stessa Azienda.']);
        }
        if (filled($result['cost_center_id'] ?? null)
            && CostCenter::query()->where('company_id', $proposal->company_id)->whereNull('archived_at')->whereKey($result['cost_center_id'])->doesntExist()) {
            throw ValidationException::withMessages(['cost_center_id' => 'Centro di Costo attivo non disponibile nella stessa Azienda.']);
        }
        if (($projectId !== null || $projectItemId !== null) && filled($result['cost_center_id'] ?? null)) {
            throw ValidationException::withMessages(['cost_center_id' => 'Il Centro di Costo diretto è ammesso soltanto per una Spesa autonoma.']);
        }
        if ($type === ProposalActionType::SetExpenseCostCenter
            && (data_get($item?->baseline, 'plan_baseline.project_id') !== null || data_get($item?->baseline, 'plan_baseline.contract_id') !== null)) {
            throw ValidationException::withMessages(['cost_center_id' => 'Il Centro di Costo diretto è modificabile soltanto per una Spesa autonoma.']);
        }
        if ($type === ProposalActionType::SetExpenseSupplier && data_get($item?->baseline, 'plan_baseline.contract_id') !== null) {
            throw ValidationException::withMessages(['supplier_id' => 'Il Fornitore di una Spesa di Contratto è derivato dal Contratto.']);
        }

        if ($item !== null && $type !== null && in_array($type, [ProposalActionType::ReverseExpense, ProposalActionType::RestoreExpense], true)) {
            $currentlyReversed = (bool) ($item->result['reversed'] ?? filled($item->result['reversed_at'] ?? null));
            if ($type === ProposalActionType::ReverseExpense && $currentlyReversed) {
                throw ValidationException::withMessages(['action_type' => 'La Spesa è già Stornata.']);
            }
            if ($type === ProposalActionType::RestoreExpense && ! $currentlyReversed) {
                throw ValidationException::withMessages(['action_type' => 'La Spesa è già Attiva.']);
            }
        }
    }

    /** @param array<string, mixed> $result */
    public static function plannedProjectAcceptsExpense(array $result, Exercise $exercise): bool
    {
        $start = $exercise->year.'-01-01';
        $end = $exercise->year.'-12-31';
        $transitions = [...ProposalPlanData::rows($result['transitions'] ?? null, 'transitions'), ...collect(ProposalPlanData::rows($result['planned_transitions'] ?? null, 'planned_transitions'))->map(fn (array $transition): array => [...$transition, 'annulled_at' => null])->all()];
        $dates = collect([$start, $end, $result['initial_effective_date'] ?? null])->merge(collect($transitions)->pluck('effective_date'))->filter(
            fn (mixed $date): bool => is_string($date) && $date >= $start && $date <= $end,
        )->unique();

        return $dates->contains(fn (string $date): bool => in_array(ProjectStateTimeline::stateAtDate(
            ProjectState::from((string) $result['initial_state']), (string) $result['initial_effective_date'], $transitions, $date,
        ), [ProjectState::Planned, ProjectState::Open], true));
    }
}
