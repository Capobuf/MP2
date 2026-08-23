<?php

namespace App\Actions\Closing;

use App\Domain\Closing\ClosingReview;
use App\Domain\Company\ClosingUnclassifiedPolicy;
use App\Domain\Contracts\ContractAnnualAllocation;
use App\Domain\Contracts\ContractRenewalSchedule;
use App\Domain\Contracts\ContractState;
use App\Domain\Expenses\Decimal;
use App\Domain\Projects\ProjectDeferralMode;
use App\Domain\Projects\ProjectDeferralValues;
use App\Domain\Projects\ProjectState;
use App\Domain\Projects\ProjectStateTimeline;
use App\Domain\Proposals\ProposalStatus;
use App\Models\BudgetSnapshot;
use App\Models\Company;
use App\Models\Contract;
use App\Models\ContractCondition;
use App\Models\Exercise;
use App\Models\Expense;
use App\Models\Project;
use App\Models\ProjectDeferral;
use App\Models\Proposal;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Gate;

final class ReviewExerciseClosing
{
    /**
     * @param  array<string, mixed>  $input
     */
    public function execute(User $actor, Exercise $exercise, array $input = []): ClosingReview
    {
        $exercise->loadMissing('company');
        Gate::forUser($actor)->authorize('close', $exercise);

        $company = $exercise->company;
        $yearStart = CarbonImmutable::create($exercise->year, 1, 1, 0, 0, 0, $company->timezone);
        $yearEnd = CarbonImmutable::create($exercise->year, 12, 31, 0, 0, 0, $company->timezone);
        $today = CarbonImmutable::now($company->timezone)->startOfDay();
        $blocks = [];
        $warnings = [];

        if (! $exercise->isOpen()) {
            $blocks[] = $this->issue('exercise_not_open', 'L’Esercizio non è Aperto.', 'exercise', $exercise->id);
        }
        if (! $today->greaterThan($yearEnd)) {
            $blocks[] = $this->issue('exercise_year_not_finished', 'L’Esercizio non può essere Chiuso prima della fine dell’anno solare.', 'exercise', $exercise->id);
        }

        $previousOpen = Exercise::query()
            ->where('company_id', $company->id)
            ->where('year', '<', $exercise->year)
            ->open()
            ->orderBy('year')
            ->get(['id', 'year', 'revision']);
        foreach ($previousOpen as $previous) {
            $blocks[] = $this->issue('previous_exercise_open', 'Chiudere prima l’Esercizio '.$previous->year.'.', 'exercise', $previous->id);
        }

        $drafts = Proposal::query()
            ->where('company_id', $company->id)
            ->where('exercise_id', $exercise->id)
            ->where('status', ProposalStatus::Draft->value)
            ->orderBy('id')
            ->get(['id', 'revision']);
        foreach ($drafts as $draft) {
            $blocks[] = $this->issue('draft_proposal_open', 'La Proposta in Bozza dell’Esercizio deve essere risolta prima della Chiusura.', 'proposal', $draft->id);
        }

        $budgets = BudgetSnapshot::query()
            ->where('company_id', $company->id)
            ->where('exercise_id', $exercise->id)
            ->with('rows')
            ->orderBy('version')
            ->get();
        $initialBudget = $budgets->first();
        $currentBudget = $budgets->last();

        $nextExercise = Exercise::query()
            ->where('company_id', $company->id)
            ->where('year', $exercise->year + 1)
            ->first();
        $managementContinues = $this->nullableBool($input['management_continues'] ?? null);
        if ($nextExercise === null && $managementContinues === null) {
            $blocks[] = $this->issue('next_exercise_decision_required', 'Indicare se la gestione continua nell’anno successivo.', 'exercise', $exercise->id);
        }

        $submittedDecisions = $this->projectDecisionInput($input['projects'] ?? []);
        $projects = Project::query()
            ->where('company_id', $company->id)
            ->with([
                'transitions',
                'classifications.costCenter',
                'deferrals.sourceExercise',
                'deferrals.destinationExercise',
                'expenses.lines',
            ])
            ->orderBy('id')
            ->get();
        $projectRows = [];
        $projectAllocationDelta = '0.00';
        $transferRequested = false;

        foreach ($projects as $project) {
            $row = $this->projectDecision(
                $project,
                $exercise,
                $yearStart,
                $yearEnd,
                $submittedDecisions[$project->id] ?? null,
                $nextExercise,
                $managementContinues,
                $blocks,
                $warnings,
            );
            $projectRows[] = $row;
            $projectAllocationDelta = Decimal::add($projectAllocationDelta, $row['source_allocation_delta']);
            $transferRequested = $transferRequested || in_array($row['final_mode'], [
                ProjectDeferralMode::Carryover->value,
                ProjectDeferralMode::Reprogramming->value,
            ], true);
        }

        if ($nextExercise === null && $managementContinues === false && $transferRequested) {
            $blocks[] = $this->issue('management_termination_has_transfer', 'La gestione terminata richiede Riporti e trasferimenti pari a zero.', 'exercise', $exercise->id);
        }

        $standaloneExpenses = Expense::query()
            ->where('company_id', $company->id)
            ->where('exercise_id', $exercise->id)
            ->whereNull('project_id')
            ->whereNull('contract_id')
            ->with(['lines', 'directCostCenter'])
            ->orderBy('id')
            ->get();

        $contracts = Contract::query()
            ->where('company_id', $company->id)
            ->with([
                'conditions',
                'lifecycleFacts',
                'renewalConfigurations',
                'classifications.costCenter',
                'expenses.lines',
            ])
            ->orderBy('id')
            ->get();

        $budgetOriginKeys = $budgets->flatMap->rows->pluck('origin_key')->unique()->flip();
        $this->addStandaloneWarnings($standaloneExpenses, $budgetOriginKeys, $company, $blocks, $warnings);
        $this->addProjectWarnings($projectRows, $budgetOriginKeys, $company, $exercise, $currentBudget, $nextExercise, $blocks, $warnings);
        $this->addContractWarnings($contracts, $budgetOriginKeys, $company, $exercise, $yearStart, $yearEnd, $blocks, $warnings);

        $currentAllocation = $exercise->allocation();
        $finalAllocation = Decimal::add($currentAllocation, $projectAllocationDelta);
        $actual = $exercise->actual();
        $variance = Decimal::subtract($actual, $finalAllocation);

        $affectedExerciseIds = [$exercise->id];
        if ($transferRequested && $nextExercise !== null && $nextExercise->isOpen()) {
            $affectedExerciseIds[] = $nextExercise->id;
        }
        $affectedExerciseIds = array_values(array_unique($affectedExerciseIds));
        sort($affectedExerciseIds);
        $affectedExercises = Exercise::query()
            ->where('company_id', $company->id)
            ->whereIn('id', $affectedExerciseIds)
            ->orderBy('year')
            ->get(['id', 'year', 'status', 'revision'])
            ->map(fn (Exercise $item): array => [
                'exercise_id' => $item->id,
                'year' => $item->year,
                'status' => $item->status()->value,
                'revision' => $item->revision,
                'allocation_delta' => $item->id === $exercise->id ? $projectAllocationDelta : '0.00',
            ])->all();

        return new ClosingReview(
            exerciseId: $exercise->id,
            exerciseYear: $exercise->year,
            totals: [
                'current_allocation' => $currentAllocation,
                'final_allocation' => $finalAllocation,
                'closing_actual' => $actual,
                'operational_variance' => $variance,
            ],
            blocks: $blocks,
            warnings: $warnings,
            projectDecisions: $projectRows,
            affectedExercises: $affectedExercises,
            budget: [
                'initial_budget_id' => $initialBudget?->id,
                'initial_budget_version' => $initialBudget?->version,
                'current_budget_id' => $currentBudget?->id,
                'current_budget_version' => $currentBudget?->version,
                'approved_budget_absent' => $currentBudget === null,
            ],
            nextExercise: [
                'exercise_id' => $nextExercise?->id,
                'year' => $exercise->year + 1,
                'exists' => $nextExercise !== null,
                'status' => $nextExercise?->status()->value,
                'management_continues' => $managementContinues,
                'disposition' => $nextExercise !== null
                    ? 'already_existed'
                    : ($managementContinues === false ? 'not_created_management_terminated' : 'created'),
            ],
            appliedSettings: [
                'timezone' => $company->timezone,
                'overspend_note_required' => (bool) $company->overspend_note_required,
                'unclassified_closing_policy' => $company->closingUnclassifiedPolicy()->value,
            ],
            sourceState: $this->sourceState($exercise, $projects, $contracts, $standaloneExpenses, $drafts, $budgets, $nextExercise, $submittedDecisions, $managementContinues),
        );
    }

    /**
     * @param  array<string, mixed>|null  $decision
     * @param  list<array<string, mixed>>  $blocks
     * @param  list<array<string, mixed>>  $warnings
     * @return array<string, mixed>
     */
    private function projectDecision(
        Project $project,
        Exercise $exercise,
        CarbonImmutable $yearStart,
        CarbonImmutable $yearEnd,
        ?array $decision,
        ?Exercise $nextExercise,
        ?bool $managementContinues,
        array &$blocks,
        array &$warnings,
    ): array {
        $currentState = $project->stateAtDate($yearEnd->toDateString());
        $totals = $project->annualTotals()[$exercise->id] ?? [
            'allocation' => '0.00',
            'actual' => '0.00',
            'has_actuals' => false,
        ];
        $currentDeferral = $project->deferrals->firstWhere('source_exercise_id', $exercise->id);
        $currentMode = $currentDeferral?->mode ?? ProjectDeferralMode::None;
        $required = in_array($currentState, [ProjectState::Planned, ProjectState::Open], true);
        $finalState = $currentState;
        $requestedState = is_string($decision['final_state'] ?? null) ? $decision['final_state'] : null;

        if ($required && $decision === null) {
            $blocks[] = $this->issue('project_decision_required', 'Confermare stato e rinvio del Progetto alla Chiusura.', 'project', $project->id);
        }

        if ($required && $requestedState !== null) {
            $allowed = $currentState === ProjectState::Planned
                ? [ProjectState::Planned, ProjectState::Cancelled]
                : [ProjectState::Open, ProjectState::Closed, ProjectState::Cancelled];
            $candidate = ProjectState::tryFrom($requestedState);
            if ($candidate === null || ! in_array($candidate, $allowed, true)) {
                $blocks[] = $this->issue('project_final_state_invalid', 'Lo stato finale scelto non è ammesso per il Progetto.', 'project', $project->id);
            } else {
                $finalState = $candidate;
                if ($candidate !== $currentState) {
                    $this->validateClosingTransition($project, $currentState, $candidate, $yearEnd, $decision, $blocks);
                }
            }
        } elseif ($required && $decision !== null) {
            $blocks[] = $this->issue('project_final_state_missing', 'Selezionare lo stato del Progetto al 31 dicembre.', 'project', $project->id);
        }

        $terminal = in_array($finalState, [ProjectState::Closed, ProjectState::Cancelled], true);
        $requestedMode = is_string($decision['mode'] ?? null) ? ProjectDeferralMode::tryFrom($decision['mode']) : null;
        $finalMode = $terminal ? ProjectDeferralMode::None : ($requestedMode ?? $currentMode);

        if ($required && ! $terminal && $requestedMode === null) {
            $blocks[] = $this->issue('project_deferral_mode_required', 'Scegliere Nessuna, Riporto o Riprogrammazione per il Progetto.', 'project', $project->id);
        }
        if ($terminal && $requestedMode !== null && $requestedMode !== ProjectDeferralMode::None) {
            $blocks[] = $this->issue('terminal_project_has_deferral', 'Un Progetto Chiuso o Cancellato deve avere modalità Nessuna.', 'project', $project->id);
        }
        if (! $required && in_array($currentState, [ProjectState::Closed, ProjectState::Cancelled], true) && $currentMode !== ProjectDeferralMode::None) {
            $blocks[] = $this->issue('terminal_project_live_deferral', 'Un Progetto terminale possiede ancora un rinvio incompatibile.', 'project', $project->id);
        }

        $reason = $this->nullableTrim($decision['reason'] ?? null);
        if ($required && (
            ($finalState !== $currentState && $currentState?->transitionRequiresReason($finalState) === true)
            || in_array($finalMode, [ProjectDeferralMode::Carryover, ProjectDeferralMode::Reprogramming], true)
            || ($currentMode !== $finalMode)
        ) && $reason === null) {
            $blocks[] = $this->issue('project_closing_reason_required', 'La Nota è obbligatoria per questa decisione di Chiusura del Progetto.', 'project', $project->id);
        }

        $allocationBeforeDecision = (string) $totals['allocation'];
        $sourceAllocationDelta = '0.00';
        if ($currentMode === ProjectDeferralMode::Reprogramming && $finalMode !== ProjectDeferralMode::Reprogramming) {
            $sourceAllocationDelta = Decimal::add($sourceAllocationDelta, (string) $currentDeferral->reprogrammed_amount);
        }
        $allocationBeforeTransfer = Decimal::add($allocationBeforeDecision, $sourceAllocationDelta);
        $maximum = ProjectDeferralValues::maximumTransferable($allocationBeforeTransfer, (string) $totals['actual']);
        $carryoverAmount = '0.00';
        $reprogrammedAmount = '0.00';

        if ($finalMode === ProjectDeferralMode::Carryover) {
            $carryoverAmount = $this->amount($decision['carryover_amount'] ?? null) ?? '0.00';
            if (Decimal::compare($carryoverAmount, '0.00') <= 0) {
                $blocks[] = $this->issue('carryover_amount_required', 'Il Riporto deve essere maggiore di zero; per non trasferire usare Nessuna.', 'project', $project->id);
            } elseif (Decimal::compare($carryoverAmount, $maximum) > 0) {
                $blocks[] = $this->issue('carryover_above_limit', 'Il Riporto supera la disponibilità massima riportabile alla Chiusura.', 'project', $project->id);
            }
        } elseif ($finalMode === ProjectDeferralMode::Reprogramming) {
            if ($currentMode === ProjectDeferralMode::Reprogramming) {
                $reprogrammedAmount = (string) $currentDeferral->reprogrammed_amount;
                $requested = $this->amount($decision['reprogrammed_amount'] ?? null);
                if ($requested !== null && Decimal::compare($requested, $reprogrammedAmount) !== 0) {
                    $blocks[] = $this->issue('executed_reprogramming_changed', 'La Riprogrammazione già eseguita non può essere riscritta in-place alla Chiusura.', 'project', $project->id);
                }
                if (! $this->executedReprogrammingLooksIntact($project, $exercise, $currentDeferral)) {
                    $blocks[] = $this->issue('executed_reprogramming_changed_independently', 'Gli effetti della Riprogrammazione già eseguita non coincidono più con gli ID e valori registrati.', 'project', $project->id);
                }
            } else {
                $reprogrammedAmount = $this->amount($decision['reprogrammed_amount'] ?? null) ?? '0.00';
                if (Decimal::compare($reprogrammedAmount, '0.00') <= 0) {
                    $blocks[] = $this->issue('reprogramming_amount_required', 'L’Importo Riprogrammato deve essere maggiore di zero.', 'project', $project->id);
                } elseif (Decimal::compare($reprogrammedAmount, $maximum) > 0) {
                    $blocks[] = $this->issue('reprogramming_above_available', 'La Riprogrammazione supera la disponibilità pre-operazione.', 'project', $project->id);
                }
                $sourceTotal = Decimal::sum(collect($decision['source_estimate_reductions'] ?? [])->map(
                    fn (mixed $item): string => is_array($item) ? ($this->amount($item['reduction_amount'] ?? null) ?? '0.00') : '0.00',
                ));
                $destinationTotal = Decimal::sum(collect($decision['destination_plans'] ?? [])->flatMap(
                    fn (mixed $plan): array => is_array($plan) && is_array($plan['estimate_lines'] ?? null) ? $plan['estimate_lines'] : [],
                )->map(fn (mixed $line): string => is_array($line) ? ($this->amount($line['amount'] ?? null) ?? '0.00') : '0.00'));
                if (Decimal::compare($sourceTotal, $reprogrammedAmount) !== 0 || Decimal::compare($destinationTotal, $reprogrammedAmount) !== 0) {
                    $blocks[] = $this->issue('reprogramming_unbalanced', 'Riduzione origine, incremento destinazione e Importo Riprogrammato devono coincidere.', 'project', $project->id);
                } else {
                    $sourceAllocationDelta = Decimal::subtract($sourceAllocationDelta, $reprogrammedAmount);
                }
            }
        }

        if ($nextExercise === null && $managementContinues === false && $finalMode !== ProjectDeferralMode::None) {
            $blocks[] = $this->issue('project_transfer_requires_next_exercise', 'Un rinvio non è compatibile con Gestione terminata.', 'project', $project->id);
        }

        $finalAllocation = Decimal::add($allocationBeforeDecision, $sourceAllocationDelta);
        $finalVariance = Decimal::subtract((string) $totals['actual'], $finalAllocation);

        return [
            'project_id' => $project->id,
            'origin_key' => $project->originKey(),
            'title' => $project->title,
            'required' => $required,
            'current_state' => $currentState?->value,
            'final_state' => $finalState?->value,
            'current_mode' => $currentMode->value,
            'final_mode' => $finalMode->value,
            'current_carryover' => $currentMode === ProjectDeferralMode::Carryover ? (string) $currentDeferral?->carryover_amount : '0.00',
            'carryover_amount' => $carryoverAmount,
            'reprogrammed_amount' => $reprogrammedAmount,
            'allocation_before_decision' => $allocationBeforeDecision,
            'source_allocation_delta' => $sourceAllocationDelta,
            'final_allocation' => $finalAllocation,
            'actual' => (string) $totals['actual'],
            'has_actuals' => (bool) ($totals['has_actuals'] ?? false),
            'operational_variance' => $finalVariance,
            'maximum_transferable' => $maximum,
            'reason' => $reason,
            'was_ever_open_in_exercise' => $this->projectWasOpenInExercise($project, $yearStart, $yearEnd),
            'project_revision' => $project->revision,
            'deferral_id' => $currentDeferral?->id,
            'deferral_updated_at' => $currentDeferral?->updated_at?->toISOString(),
            'decision_payload' => $decision,
        ];
    }

    /**
     * @param  array<string, mixed>  $decision
     * @param  list<array<string, mixed>>  $blocks
     */
    private function validateClosingTransition(Project $project, ProjectState $currentState, ProjectState $finalState, CarbonImmutable $yearEnd, array $decision, array &$blocks): void
    {
        $rows = $project->transitions->map(fn ($transition): array => [
            'from_state' => $transition->from_state,
            'to_state' => $transition->to_state,
            'effective_date' => $transition->effectiveDate()->toDateString(),
            'annulled_at' => $transition->annulledAt()?->toISOString(),
        ])->all();
        $rows[] = [
            'from_state' => $currentState,
            'to_state' => $finalState,
            'effective_date' => $yearEnd->toDateString(),
            'annulled_at' => null,
        ];

        try {
            ProjectStateTimeline::validate(
                $project->initialState(),
                $project->initialEffectiveDate()->toDateString(),
                $rows,
            );
        } catch (\DomainException $exception) {
            $blocks[] = $this->issue('project_transition_incompatible', $exception->getMessage(), 'project', $project->id);
        }
    }

    private function executedReprogrammingLooksIntact(Project $project, Exercise $source, ProjectDeferral $deferral): bool
    {
        $effects = $deferral->reprogramming_effects;
        if (! is_array($effects) || $deferral->reprogramming_operation_id === null) {
            return false;
        }

        foreach ($effects['source_lines'] ?? [] as $expected) {
            if (! is_array($expected)) {
                return false;
            }
            $line = \App\Models\ExpenseLine::query()->with('expense')->find($expected['expense_line_id'] ?? null);
            if ($line === null || $line->expense === null
                || $line->expense->company_id !== $project->company_id
                || $line->expense->project_id !== $project->id
                || $line->expense->exercise_id !== $source->id
                || $line->expense->originKey() !== ($expected['source_expense_origin_key'] ?? null)
                || $line->expense->isReversed() !== (bool) ($expected['expense_reversed_after'] ?? false)
                || (int) $line->revision !== (int) ($expected['line_revision_after'] ?? -1)
                || Decimal::compare((string) $line->amount, (string) ($expected['amount_after'] ?? '0.00')) !== 0
                || $line->isAnnulled() !== (bool) ($expected['annulled_after'] ?? false)) {
                return false;
            }
        }

        foreach ($effects['destination_expenses'] ?? [] as $expectedExpense) {
            if (! is_array($expectedExpense)) {
                return false;
            }
            $expense = Expense::query()->find($expectedExpense['expense_id'] ?? null);
            if ($expense === null || $expense->company_id !== $project->company_id
                || $expense->project_id !== $project->id
                || $expense->isReversed() !== (bool) ($expectedExpense['reversed'] ?? false)
                || $expense->copied_from_origin_key !== ($expectedExpense['copied_from_origin_key'] ?? null)) {
                return false;
            }
            foreach ($expectedExpense['estimate_lines'] ?? [] as $expectedLine) {
                if (! is_array($expectedLine)) {
                    return false;
                }
                $line = $expense->lines()->find($expectedLine['expense_line_id'] ?? null);
                if ($line === null
                    || (int) $line->revision !== (int) ($expectedLine['line_revision_after'] ?? -1)
                    || Decimal::compare((string) $line->amount, (string) ($expectedLine['amount'] ?? '0.00')) !== 0
                    || $line->isAnnulled() !== (bool) ($expectedLine['annulled'] ?? false)) {
                    return false;
                }
            }
        }

        return true;
    }

    /**
     * @param  Collection<int, Expense>  $expenses
     * @param  Collection<string, int>  $budgetOriginKeys
     * @param  list<array<string, mixed>>  $blocks
     * @param  list<array<string, mixed>>  $warnings
     */
    private function addStandaloneWarnings(Collection $expenses, Collection $budgetOriginKeys, Company $company, array &$blocks, array &$warnings): void
    {
        foreach ($expenses as $expense) {
            $included = Decimal::compare($expense->allocation(), '0.00') !== 0
                || $expense->hasActuals()
                || $budgetOriginKeys->has($expense->originKey());
            if (! $included) {
                continue;
            }
            if (Decimal::compare($expense->allocation(), '0.00') > 0 && ! $expense->hasActuals()) {
                $warnings[] = $this->issue('allocated_without_actuals', 'Allocato presente, nessun Effettivo registrato.', 'expense', $expense->id);
            }
            if ($expense->direct_cost_center_id === null) {
                $this->addClassificationIssue($company, 'expense', $expense->id, $blocks, $warnings);
            }
        }
    }

    /**
     * @param  list<array<string, mixed>>  $projectRows
     * @param  Collection<string, int>  $budgetOriginKeys
     * @param  list<array<string, mixed>>  $blocks
     * @param  list<array<string, mixed>>  $warnings
     */
    private function addProjectWarnings(array $projectRows, Collection $budgetOriginKeys, Company $company, Exercise $exercise, ?BudgetSnapshot $currentBudget, ?Exercise $nextExercise, array &$blocks, array &$warnings): void
    {
        foreach ($projectRows as $row) {
            $included = Decimal::compare($row['final_allocation'], '0.00') !== 0
                || $row['has_actuals']
                || $budgetOriginKeys->has($row['origin_key'])
                || in_array($row['final_state'], [ProjectState::Planned->value, ProjectState::Open->value], true);
            if (! $included) {
                continue;
            }
            if (Decimal::compare($row['final_allocation'], '0.00') > 0 && ! $row['has_actuals']) {
                $warnings[] = $this->issue('allocated_without_actuals', 'Allocato presente, nessun Effettivo registrato.', 'project', $row['project_id']);
            }
            if ($row['final_state'] === ProjectState::Planned->value && ! $row['was_ever_open_in_exercise']) {
                $warnings[] = $this->issue('planned_project_never_opened', 'Progetto Pianificato ma mai Aperto nell’Esercizio.', 'project', $row['project_id']);
            }
            $classified = \App\Models\ProjectExerciseClassification::query()
                ->where('project_id', $row['project_id'])
                ->where('exercise_id', $exercise->id)
                ->whereNotNull('cost_center_id')
                ->exists();
            if (! $classified) {
                $this->addClassificationIssue($company, 'project', $row['project_id'], $blocks, $warnings);
            }

            if ($nextExercise !== null) {
                $nextBudget = BudgetSnapshot::query()
                    ->where('company_id', $company->id)
                    ->where('exercise_id', $nextExercise->id)
                    ->latest('version')
                    ->first();
                $budgetRow = $nextBudget?->rows()->where('origin_key', $row['origin_key'])->first();
                if ($budgetRow !== null && $budgetRow->carryover_state === 'provisional'
                    && Decimal::compare((string) $budgetRow->approved_carryover, (string) $row['maximum_transferable']) !== 0) {
                    $warnings[] = $this->issue('approved_provisional_carryover_differs', 'Il Riporto provvisorio nel Budget corrente di N+1 differisce dal massimo consolidabile.', 'project', $row['project_id']);
                }
            }
        }
    }

    /**
     * @param  Collection<int, Contract>  $contracts
     * @param  Collection<string, int>  $budgetOriginKeys
     * @param  list<array<string, mixed>>  $blocks
     * @param  list<array<string, mixed>>  $warnings
     */
    private function addContractWarnings(Collection $contracts, Collection $budgetOriginKeys, Company $company, Exercise $exercise, CarbonImmutable $yearStart, CarbonImmutable $yearEnd, array &$blocks, array &$warnings): void
    {
        foreach ($contracts as $contract) {
            if (! $this->contractConditionsValid($contract)) {
                $blocks[] = $this->issue('invalid_contract_conditions', 'Le condizioni economiche del Contratto non sono valide.', 'contract', $contract->id);
                continue;
            }

            $annual = ContractAnnualAllocation::forYear(
                $contract->conditions,
                $exercise->year,
                fn (string $date) => $contract->stateAtDate($date),
            );
            $totals = $contract->annualTotals()[$exercise->id] ?? [
                'allocation' => $annual->amount,
                'actual' => '0.00',
                'has_actuals' => false,
            ];
            $stateStart = $contract->stateAtDate($yearStart->toDateString());
            $stateEnd = $contract->stateAtDate($yearEnd->toDateString());
            $included = Decimal::compare((string) $totals['allocation'], '0.00') !== 0
                || (bool) ($totals['has_actuals'] ?? false)
                || $budgetOriginKeys->has($contract->originKey())
                || in_array($stateEnd, [ContractState::Planned, ContractState::Active], true)
                || $contract->conditions->contains(fn (ContractCondition $condition): bool => $this->conditionOverlapsExercise($condition, $yearStart, $yearEnd));
            if (! $included) {
                continue;
            }

            if (Decimal::compare((string) $totals['allocation'], '0.00') > 0 && ! (bool) ($totals['has_actuals'] ?? false)) {
                $warnings[] = $this->issue('allocated_without_actuals', 'Allocato presente, nessun Effettivo registrato.', 'contract', $contract->id);
            }
            $classified = $contract->classifications->contains(
                fn ($classification): bool => $classification->exercise_id === $exercise->id && $classification->cost_center_id !== null,
            );
            if (! $classified) {
                $this->addClassificationIssue($company, 'contract', $contract->id, $blocks, $warnings);
            }

            if (in_array($stateStart, [ContractState::Planned, ContractState::Active], true)
                || in_array($stateEnd, [ContractState::Planned, ContractState::Active], true)) {
                if ($annual->composition === []) {
                    $warnings[] = $this->issue('contract_without_applicable_condition', 'Contratto Attivo o Pianificato senza condizione economica Valida applicabile nell’Esercizio.', 'contract', $contract->id);
                }
            }

            $expiry = $contract->nextExpiryDate()?->toDateString();
            if ($expiry !== null && $expiry <= $yearEnd->toDateString()) {
                $configuration = ContractRenewalSchedule::configurationAtDate($contract->renewalConfigurations, $expiry);
                if ($configuration !== null && (bool) $configuration->automatic_renewal
                    && ContractRenewalSchedule::hasRenewalWithoutCondition($contract->conditions, $expiry)) {
                    $warnings[] = $this->issue('renewal_without_condition', 'Rinnovo senza condizione economica Valida dopo la scadenza.', 'contract', $contract->id);
                }
            }
        }
    }

    /**
     * @param  list<array<string, mixed>>  $blocks
     * @param  list<array<string, mixed>>  $warnings
     */
    private function addClassificationIssue(Company $company, string $sourceType, int $sourceId, array &$blocks, array &$warnings): void
    {
        $issue = $this->issue('unclassified_source', 'Sorgente Non classificata alla Chiusura.', $sourceType, $sourceId);
        if ($company->closingUnclassifiedPolicy() === ClosingUnclassifiedPolicy::Blocking) {
            $blocks[] = $issue;
        } else {
            $warnings[] = $issue;
        }
    }

    private function contractConditionsValid(Contract $contract): bool
    {
        $active = $contract->conditions->reject(fn (ContractCondition $condition): bool => $condition->isAnnulled())->values();
        foreach ($active as $index => $condition) {
            if ($condition->validFrom()->lessThan($contract->contractualStartDate())) {
                return false;
            }
            if ($condition->validTo() !== null && $condition->validTo()->lessThan($condition->validFrom())) {
                return false;
            }
            for ($otherIndex = $index + 1; $otherIndex < $active->count(); $otherIndex++) {
                $other = $active[$otherIndex];
                $endsBefore = $condition->validTo() !== null && $condition->validTo()->lessThan($other->validFrom());
                $startsAfter = $other->validTo() !== null && $condition->validFrom()->greaterThan($other->validTo());
                if (! $endsBefore && ! $startsAfter) {
                    return false;
                }
            }
        }

        return true;
    }

    private function conditionOverlapsExercise(ContractCondition $condition, CarbonImmutable $yearStart, CarbonImmutable $yearEnd): bool
    {
        if ($condition->isAnnulled()) {
            return false;
        }

        return ! $condition->validFrom()->greaterThan($yearEnd)
            && ($condition->validTo() === null || ! $condition->validTo()->lessThan($yearStart));
    }

    private function projectWasOpenInExercise(Project $project, CarbonImmutable $yearStart, CarbonImmutable $yearEnd): bool
    {
        if ($project->stateAtDate($yearStart->toDateString()) === ProjectState::Open) {
            return true;
        }

        foreach ($project->transitions as $transition) {
            if ($transition->annulledAt() !== null) {
                continue;
            }
            $date = CarbonImmutable::parse($transition->effectiveDate()->toDateString());
            if ($date->betweenIncluded($yearStart, $yearEnd) && $transition->to_state === ProjectState::Open) {
                return true;
            }
        }

        return false;
    }

    /** @param mixed $input
     * @return array<int, array<string, mixed>>
     */
    private function projectDecisionInput(mixed $input): array
    {
        if (! is_array($input)) {
            return [];
        }

        $result = [];
        foreach ($input as $key => $row) {
            if (! is_array($row)) {
                continue;
            }
            $id = isset($row['project_id']) ? (int) $row['project_id'] : (is_numeric($key) ? (int) $key : 0);
            if ($id > 0) {
                $result[$id] = $row;
            }
        }
        ksort($result);

        return $result;
    }

    private function amount(mixed $value): ?string
    {
        $normalized = Decimal::normalizeInput($value);
        if ((! is_string($normalized) && ! is_int($normalized) && ! is_float($normalized)) || ! is_numeric((string) $normalized)) {
            return null;
        }

        return Decimal::money((string) $normalized);
    }

    private function nullableBool(mixed $value): ?bool
    {
        if ($value === null || $value === '') {
            return null;
        }
        if (is_bool($value)) {
            return $value;
        }
        if (in_array($value, [1, '1', 'true'], true)) {
            return true;
        }
        if (in_array($value, [0, '0', 'false'], true)) {
            return false;
        }

        return null;
    }

    private function nullableTrim(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }
        $value = trim($value);

        return $value === '' ? null : $value;
    }

    /** @return array<string, mixed> */
    private function issue(string $code, string $message, string $sourceType, int $sourceId): array
    {
        return [
            'code' => $code,
            'message' => $message,
            'source_type' => $sourceType,
            'source_id' => $sourceId,
        ];
    }

    /**
     * @param  Collection<int, Project>  $projects
     * @param  Collection<int, Contract>  $contracts
     * @param  Collection<int, Expense>  $expenses
     * @param  Collection<int, Proposal>  $drafts
     * @param  Collection<int, BudgetSnapshot>  $budgets
     * @param  array<int, array<string, mixed>>  $submittedDecisions
     * @return array<string, mixed>
     */
    private function sourceState(Exercise $exercise, Collection $projects, Collection $contracts, Collection $expenses, Collection $drafts, Collection $budgets, ?Exercise $nextExercise, array $submittedDecisions, ?bool $managementContinues): array
    {
        return [
            'exercise_revision' => $exercise->revision,
            'exercise_status' => $exercise->status()->value,
            'next_exercise' => $nextExercise === null ? null : [
                'id' => $nextExercise->id,
                'revision' => $nextExercise->revision,
                'status' => $nextExercise->status()->value,
            ],
            'projects' => $projects->map(fn (Project $project): array => [
                'id' => $project->id,
                'revision' => $project->revision,
                'updated_at' => $project->updated_at?->toISOString(),
                'deferrals' => $project->deferrals->map(fn (ProjectDeferral $deferral): array => [
                    'id' => $deferral->id,
                    'source_exercise_id' => $deferral->source_exercise_id,
                    'destination_exercise_id' => $deferral->destination_exercise_id,
                    'mode' => $deferral->mode->value,
                    'carryover_amount' => (string) $deferral->carryover_amount,
                    'carryover_state' => $deferral->carryover_state,
                    'reprogrammed_amount' => (string) $deferral->reprogrammed_amount,
                    'reprogramming_operation_id' => $deferral->reprogramming_operation_id,
                    'updated_at' => $deferral->updated_at?->toISOString(),
                ])->values()->all(),
            ])->all(),
            'contracts' => $contracts->map(fn (Contract $contract): array => [
                'id' => $contract->id,
                'revision' => $contract->revision,
                'updated_at' => $contract->updated_at?->toISOString(),
                'next_expiry_date' => $contract->nextExpiryDate()?->toDateString(),
            ])->all(),
            'standalone_expenses' => $expenses->map(fn (Expense $expense): array => [
                'id' => $expense->id,
                'revision' => $expense->revision,
                'updated_at' => $expense->updated_at?->toISOString(),
            ])->all(),
            'draft_proposals' => $drafts->map(fn (Proposal $proposal): array => ['id' => $proposal->id, 'revision' => $proposal->revision])->all(),
            'budgets' => $budgets->map(fn (BudgetSnapshot $budget): array => ['id' => $budget->id, 'version' => $budget->version])->all(),
            'submitted_project_decisions' => $submittedDecisions,
            'management_continues' => $managementContinues,
        ];
    }
}
