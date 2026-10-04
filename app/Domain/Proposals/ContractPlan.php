<?php

namespace App\Domain\Proposals;

use App\Domain\Contracts\ContractCycleType;
use App\Domain\Contracts\ContractEconomicChangePlan;
use App\Domain\Contracts\ContractLifecycleRules;
use App\Domain\Contracts\ContractState;
use App\Domain\Contracts\ContractStateTimeline;
use App\Domain\Expenses\ManualExpenseLine;
use App\Models\ContractCondition;
use App\Models\CostCenter;
use App\Models\Exercise;
use App\Models\ProposalItem;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;

final class ContractPlan
{
    /** @param array<string, mixed> $context */
    public static function assertManualActivity(ProposalItem $item, array $context, Exercise $exercise): void
    {
        $result = $item->result;
        $facts = [...ProposalPlanData::rows($result['lifecycle_facts'] ?? null, 'lifecycle_facts'),
            ...array_map(fn (array $fact): array => [...$fact, 'state_change_date' => $fact['effective_date'], 'annulled_at' => null], ProposalPlanData::rows($result['planned_lifecycle'] ?? null, 'planned_lifecycle'))];
        $state = ContractStateTimeline::stateAtDate((string) $result['contractual_start_date'], $facts,
            now($item->proposal->company->timezone)->toDateString(), $result['renewal_configurations'] ?? []);
        $reactivates = collect(ProposalPlanData::rows($result['planned_lifecycle'] ?? null, 'planned_lifecycle'))->contains(fn (array $fact): bool => $fact['type'] === 'reactivation' && $fact['effective_date'] <= $exercise->year.'-12-31');
        if (filled($result['archived_at'] ?? null) && ! $reactivates) {
            throw ValidationException::withMessages(['contract_id' => 'Ripristinare il Contratto prima della nuova pianificazione.']);
        }
        if (in_array($state, [ContractState::Cessated, ContractState::Cancelled], true) && ! $reactivates
            && (! ($context['residual_estimate'] ?? false) || blank($context['activity_note'] ?? null))) {
            throw ValidationException::withMessages(['residual_estimate' => 'Dichiarare il costo residuo e la Nota per il Contratto terminale.']);
        }
    }

    public static function validateForApproval(ProposalItem $item): void
    {
        $result = $item->result;
        foreach ($result['expense_plan'] ?? [] as $expense) {
            if (! isset($expense['origin'])) {
                throw ValidationException::withMessages(['expense_plan' => 'Riallineare la Bozza: l’origine delle Spese non è disponibile nella baseline.']);
            }
        }
        foreach ($result['expense_plan'] ?? [] as $expense) {
            if (! ($expense['estimate_lines_changed'] ?? false)) {
                continue;
            }
            $exercise = Exercise::query()->where('company_id', $item->company_id)->find($expense['exercise_id']);
            if ($exercise === null || ! $exercise->isOpen()) {
                throw ValidationException::withMessages(['exercise_id' => 'La pianificazione richiede un Esercizio Aperto.']);
            }
            self::assertManualActivity($item, $expense, $exercise);
        }
        $conditions = collect(self::projectedConditions($result))
            ->filter(fn (array $condition): bool => ! filled($condition['annulled_at'] ?? null))
            ->sortBy('valid_from')->values();
        foreach ($conditions as $index => $condition) {
            if (! is_numeric($condition['amount'] ?? null) || bccomp((string) $condition['amount'], '0', 2) < 0) {
                throw ValidationException::withMessages(['conditions' => 'Condizione economica incompleta o non valida.']);
            }
            self::dateOrder((string) $condition['valid_from'], $condition['valid_to'] ?? null, 'valid_to');
            $next = $conditions->get($index + 1);
            if ($next !== null && (blank($condition['valid_to'] ?? null) || Carbon::parse((string) $condition['valid_to'])->gte(Carbon::parse((string) $next['valid_from'])))) {
                throw ValidationException::withMessages(['conditions' => 'Le condizioni valide dello stesso Contratto non possono sovrapporsi.']);
            }
        }
        $facts = collect(ProposalPlanData::rows($result['lifecycle_facts'] ?? null, 'lifecycle_facts'))->map(fn (array $fact): array => [
            'id' => $fact['id'] ?? 0, 'type' => $fact['type'], 'state_change_date' => $fact['state_change_date'] ?? null, 'annulled_at' => $fact['annulled_at'] ?? null,
        ])->concat(collect(ProposalPlanData::rows($result['planned_lifecycle'] ?? null, 'planned_lifecycle'))->map(fn (array $fact): array => [
            'id' => 0, 'type' => $fact['type'], 'state_change_date' => $fact['effective_date'], 'annulled_at' => null,
        ]))->all();
        try {
            ContractLifecycleRules::validate((string) $result['contractual_start_date'], $facts);
        } catch (\DomainException $exception) {
            throw ValidationException::withMessages(['lifecycle' => $exception->getMessage()]);
        }
        foreach ($result['planned_conditions'] ?? [] as $condition) {
            if ($condition['valid_from'] < now($item->proposal->company->timezone)->toDateString()
                || ContractStateTimeline::stateAtDate((string) $result['contractual_start_date'], $facts, $condition['valid_from'], $result['renewal_configurations'] ?? []) !== ContractState::Active) {
                throw ValidationException::withMessages(['valid_from' => 'La decorrenza deve essere corrente o futura, in un periodo Attivo del Contratto.']);
            }
        }
        if ($item->contract?->isArchived() && collect(ProposalPlanData::rows($result['planned_lifecycle'] ?? null, 'planned_lifecycle'))->doesntContain(fn (array $fact): bool => ($fact['type'] ?? null) === 'reactivation')) {
            throw ValidationException::withMessages(['archived' => 'Il Contratto Archiviato richiede una riattivazione esplicita.']);
        }
        if (array_key_exists('cost_center_id', $result)) {
            self::classification($item, $result, ['exercise_id' => $result['exercise_id'] ?? $item->proposal->exercise_id, 'cost_center_id' => $result['cost_center_id']]);
        }
        $item->loadMissing('proposal.company');
        $confirmationDate = now($item->proposal->company->timezone)->toDateString();
        foreach ($item->actions->where('action_type', ProposalActionType::ChangeContractEconomics) as $action) {
            $recalculated = self::prepareEconomicChange($item, $action->payload, $confirmationDate, false);
            foreach (['minimum_date', 'effective_date', 'delay_reason', 'no_prorata', 'future_replacement', 'exercise_impacts', 'effective_date_confirmed'] as $key) {
                $changed = $key === 'exercise_impacts'
                    ? ($recalculated[$key] ?? null) != ($action->payload[$key] ?? null)
                    : ($recalculated[$key] ?? null) !== ($action->payload[$key] ?? null);
                if ($changed) {
                    throw ValidationException::withMessages(['economic_change' => 'Il dato economico «'.$key.'» deve essere ricalcolato e riconfermato.']);
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
        self::dateOrder((string) $payload['contractual_start_date'], $payload['next_expiry_date'] ?? null, 'next_expiry_date');
        if (($payload['automatic_renewal'] ?? false) && filled($payload['next_expiry_date'] ?? null)
            && (! is_numeric($payload['renewal_duration_months'] ?? null) || (int) $payload['renewal_duration_months'] < 1)) {
            throw ValidationException::withMessages(['renewal_duration_months' => 'La durata del rinnovo è obbligatoria e positiva con rinnovo automatico e scadenza definita.']);
        }

        return [...$payload, 'planned_conditions' => [], 'planned_lifecycle' => [], 'renewal_configurations' => [[
            'effective_from' => $payload['contractual_start_date'],
            'expiry_anchor_date' => $payload['next_expiry_date'] ?? null,
            'automatic_renewal' => $payload['automatic_renewal'] ?? false,
            'renewal_duration_months' => $payload['renewal_duration_months'] ?? null,
            'notice_days' => $payload['notice_days'] ?? null,
        ]], 'prorata_applied' => false, 'start_state' => ContractState::Planned->value];
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public static function prepareEconomicChange(ProposalItem $item, array $payload, string $confirmationDate, bool $rejectExisting = true): array
    {
        $contract = $item->contract;
        if ($contract === null) {
            throw ValidationException::withMessages(['condition_id' => 'La modifica richiede una condizione Contratto viva.']);
        }
        $condition = ContractCondition::query()->where('contract_id', $contract->id)->find($payload['condition_id']);
        if ($condition === null) {
            throw ValidationException::withMessages(['condition_id' => 'Condizione non disponibile nel Contratto.']);
        }
        if ($rejectExisting && collect(ProposalPlanData::rows($item->result['planned_condition_changes'] ?? null, 'planned_condition_changes'))->contains(fn (array $change): bool => (int) $change['condition_id'] === $condition->id)) {
            throw ValidationException::withMessages(['condition_id' => 'Questa condizione è già sostituita da una modifica economica della Proposta.']);
        }
        $exercises = Exercise::query()->where('company_id', $contract->company_id)->orderBy('year')->get();
        $plan = ContractEconomicChangePlan::forChange($contract, $condition, [
            'amount' => (string) $payload['amount'], 'cycle' => (string) $payload['cycle'], 'attribution_mode' => (string) $payload['attribution_mode'],
        ], (string) $payload['requested_date'], $confirmationDate, $exercises);
        if ((string) $payload['confirmed_effective_date'] !== $plan->effectiveDate) {
            throw ValidationException::withMessages(['confirmed_effective_date' => 'La Data efficace applicabile è '.$plan->effectiveDate.'. Ricalcolare l’impatto e confermarla esplicitamente.']);
        }

        return [
            ...$payload,
            'minimum_date' => $plan->minimumDate,
            'effective_date' => $plan->effectiveDate,
            'delay_reason' => $plan->delayReason,
            'no_prorata' => true,
            'future_replacement' => $plan->futureReplacement,
            'exercise_impacts' => $plan->exerciseImpacts,
            'effective_date_confirmed' => true,
        ];
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public static function apply(ProposalItem $item, ProposalActionType $type, array $payload): array
    {
        if ($item->source_type !== ProposalSourceType::Contract) {
            throw ValidationException::withMessages(['item' => 'L’Elemento non è un Contratto.']);
        }
        $result = $item->result;

        return match ($type) {
            ProposalActionType::AddContractCondition => self::condition($item, $result, $payload),
            ProposalActionType::PlanContractChildExpenses => self::manualExpenses($item, $result, $payload),
            ProposalActionType::ChangeContractEconomics => self::economicChange($result, $payload),
            ProposalActionType::PlanContractLifecycle => self::lifecycle($item, $result, $payload),
            ProposalActionType::SetContractRenewal => self::renewal($item, $result, $payload),
            ProposalActionType::SetContractCostCenter => self::classification($item, $result, $payload),
            default => throw ValidationException::withMessages(['action_type' => 'Azione Contratto non valida.']),
        };
    }

    /**
     * @param  array<string, mixed>  $result
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private static function manualExpenses(ProposalItem $item, array $result, array $payload): array
    {
        $plans = collect(ProposalPlanData::rows($result['expense_plan'] ?? null, 'expense_plan'))->keyBy('expense_id');
        $updates = collect(ProposalPlanData::rows($payload['existing_expenses'] ?? null, 'existing_expenses'));
        if ($updates->pluck('expense_id')->duplicates()->isNotEmpty()) {
            throw ValidationException::withMessages(['existing_expenses' => 'Una Spesa può essere pianificata una sola volta.']);
        }
        foreach ($updates as $update) {
            $expense = $plans->get($update['expense_id']);
            if ($expense === null || ($expense['origin'] ?? null) !== 'manual'
                || (int) ($expense['contract_id'] ?? 0) !== (int) $item->contract_id) {
                throw ValidationException::withMessages(['existing_expenses' => 'La Spesa deve essere una manuale della baseline del Contratto. Riallineare le Bozze precedenti.']);
            }
            $exercise = Exercise::query()->where('company_id', $item->company_id)->find($expense['exercise_id']);
            if ($exercise === null || ! $exercise->isOpen() || filled($expense['reversed_at'] ?? null)) {
                throw ValidationException::withMessages(['existing_expenses' => 'La Spesa deve essere Attiva in un Esercizio Aperto.']);
            }
            self::assertManualActivity($item, $payload, $exercise);
            $oldLines = collect(ProposalPlanData::rows($expense['estimate_lines'], 'estimate_lines'))->keyBy(fn (array $line): int => (int) ($line['line_id'] ?? $line['id']));
            $ids = collect(ProposalPlanData::rows($update['estimate_lines'], 'estimate_lines'))->pluck('line_id')->filter();
            if ($ids->duplicates()->isNotEmpty() || $ids->diff($oldLines->keys())->isNotEmpty()) {
                throw ValidationException::withMessages(['estimate_lines' => 'Righe duplicate o estranee alla Spesa.']);
            }
            $lines = [];
            foreach ($update['estimate_lines'] as $line) {
                $before = isset($line['line_id']) ? $oldLines->get($line['line_id']) : [];
                $merged = array_replace($before, $line);
                unset($merged['id']);
                $merged['annulled_at'] = $line['annulled'] ? ($before['annulled_at'] ?? now()->toDateTimeString()) : null;
                $validated = ManualExpenseLine::validate([...$merged, 'type' => 'estimate'], $item->proposal->company, $exercise, $before === []);
                unset($validated['type']);
                $lines[] = [...$merged, ...$validated];
            }
            $expense['estimate_lines'] = $lines;
            $expense['estimate_lines_changed'] = true;
            $expense['residual_estimate'] = $payload['residual_estimate'] ?? false;
            $expense['activity_note'] = $payload['activity_note'] ?? null;
            $plans->put($update['expense_id'], $expense);
        }

        return [...$result, 'expense_plan' => $plans->values()->all()];
    }

    /**
     * @param  array<string, mixed>  $result
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private static function condition(ProposalItem $item, array $result, array $payload): array
    {
        if (ContractCycleType::tryFrom($payload['cycle']) === null || ! in_array($payload['attribution_mode'], ['cycle_start', 'cycle_end'], true) || ! is_numeric($payload['amount']) || bccomp((string) $payload['amount'], '0', 2) < 0) {
            throw ValidationException::withMessages(['condition' => 'Condizione economica non valida.']);
        }
        if ($payload['valid_from'] < $result['contractual_start_date'] || $payload['valid_from'] < now($item->proposal->company->timezone)->toDateString()) {
            throw ValidationException::withMessages(['valid_from' => 'La Proposta non può introdurre una decorrenza precedente all’accordo o già trascorsa.']);
        }
        self::dateOrder((string) $payload['valid_from'], $payload['valid_to'] ?? null, 'valid_to');
        $conditions = self::projectedConditions($result);
        foreach ($conditions as $existing) {
            if (($existing['annulled_at'] ?? null) !== null) {
                continue;
            }
            $from = Carbon::parse((string) $payload['valid_from']);
            $to = filled($payload['valid_to'] ?? null) ? Carbon::parse((string) $payload['valid_to']) : null;
            $otherFrom = Carbon::parse((string) $existing['valid_from']);
            $otherTo = filled($existing['valid_to'] ?? null) ? Carbon::parse((string) $existing['valid_to']) : null;
            if (! ($to !== null && $to->lt($otherFrom)) && ! ($otherTo !== null && $from->gt($otherTo))) {
                throw ValidationException::withMessages(['valid_from' => 'Le condizioni valide dello stesso Contratto non possono sovrapporsi.']);
            }
        }

        return [...$result, 'planned_conditions' => [...($result['planned_conditions'] ?? []), [...$payload, 'prorata_applied' => false]]];
    }

    /**
     * @param  array<string, mixed>  $result
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private static function economicChange(array $result, array $payload): array
    {
        if (($payload['effective_date_confirmed'] ?? false) !== true || ($payload['no_prorata'] ?? false) !== true) {
            throw ValidationException::withMessages(['effective_date_confirmed' => 'Confermare esplicitamente la Data efficace canonica senza prorata.']);
        }

        return [...$result, 'planned_condition_changes' => [...($result['planned_condition_changes'] ?? []), [...$payload, 'prorata_applied' => false]]];
    }

    /**
     * @param  array<string, mixed>  $result
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private static function lifecycle(ProposalItem $item, array $result, array $payload): array
    {
        if (! in_array($payload['type'], ['cessation', 'reactivation'], true) || blank($payload['reason'] ?? null)) {
            throw ValidationException::withMessages(['type' => 'La Proposta supporta soltanto cessazione o riattivazione con motivazione.']);
        }
        $declared = Carbon::parse((string) $payload['declared_contractual_date'])->startOfDay();
        $effective = Carbon::parse((string) $payload['effective_date'])->startOfDay();
        if ($payload['type'] === 'cessation' && ! $effective->equalTo($declared->copy()->addDay())) {
            throw ValidationException::withMessages(['effective_date' => 'La cessazione diventa efficace il giorno successivo all’ultimo giorno Attivo.']);
        }
        if ($payload['type'] === 'reactivation' && ! $effective->equalTo($declared)) {
            throw ValidationException::withMessages(['effective_date' => 'La riattivazione è efficace dalla nuova data di inizio.']);
        }
        $facts = collect(ProposalPlanData::rows($result['lifecycle_facts'] ?? null, 'lifecycle_facts'))->map(fn (array $fact): array => ['id' => $fact['id'] ?? 0, 'type' => $fact['type'], 'state_change_date' => $fact['state_change_date'] ?? null, 'annulled_at' => $fact['annulled_at'] ?? null])->concat(collect(ProposalPlanData::rows($result['planned_lifecycle'] ?? null, 'planned_lifecycle'))->map(fn (array $fact): array => ['id' => 0, 'type' => $fact['type'], 'state_change_date' => $fact['effective_date'], 'annulled_at' => null]))->push(['id' => 0, 'type' => $payload['type'], 'state_change_date' => $payload['effective_date'], 'annulled_at' => null])->all();
        try {
            ContractLifecycleRules::validate((string) $result['contractual_start_date'], $facts);
        } catch (\DomainException $exception) {
            throw ValidationException::withMessages(['effective_date' => $exception->getMessage()]);
        }

        return [...$result, 'planned_lifecycle' => [...($result['planned_lifecycle'] ?? []), $payload]];
    }

    /**
     * @param  array<string, mixed>  $result
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private static function renewal(ProposalItem $item, array $result, array $payload): array
    {
        $exercise = Exercise::query()->where('company_id', $item->company_id)->where('year', Carbon::parse((string) $payload['effective_from'])->year)->first();
        if ($exercise !== null && ! $exercise->isOpen()) {
            throw ValidationException::withMessages(['effective_from' => 'La configurazione di rinnovo non può modificare un Esercizio Chiuso.']);
        }
        $expiry = $payload['expiry_anchor_date'] ?? null;
        $automatic = (bool) $payload['automatic_renewal'];
        $duration = $payload['renewal_duration_months'] ?? null;
        $notice = $payload['notice_days'] ?? null;
        if ($expiry !== null && Carbon::parse((string) $expiry)->lt(Carbon::parse((string) $result['contractual_start_date']))) {
            throw ValidationException::withMessages(['expiry_anchor_date' => 'La prossima scadenza non può precedere l’inizio contrattuale.']);
        }
        if ($automatic && $expiry !== null && (! is_numeric($duration) || (int) $duration < 1)) {
            throw ValidationException::withMessages(['renewal_duration_months' => 'La durata del rinnovo è obbligatoria e positiva con rinnovo automatico e scadenza definita.']);
        }
        if ($notice !== null && (! is_numeric($notice) || (int) $notice < 0)) {
            throw ValidationException::withMessages(['notice_days' => 'Il preavviso deve essere maggiore o uguale a zero.']);
        }

        return [...$result, 'automatic_renewal' => $automatic, 'next_expiry_date' => $expiry, 'renewal_anchor_date' => $expiry, 'renewal_duration_months' => $duration, 'notice_days' => $notice, 'renewal_configurations' => [...($result['renewal_configurations'] ?? []), $payload]];
    }

    /**
     * @param  array<string, mixed>  $result
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private static function classification(ProposalItem $item, array $result, array $payload): array
    {
        if ((bool) data_get($item->baseline, 'actual_context.has_actuals', false)) {
            throw ValidationException::withMessages(['cost_center_id' => 'Il Contratto possiede Effettivi nell’Esercizio e non può essere riclassificato dalla Proposta.']);
        }
        $exercise = Exercise::query()->where('company_id', $item->company_id)->find($payload['exercise_id'] ?? null);
        if ($exercise === null || ! $exercise->isOpen()) {
            throw ValidationException::withMessages(['exercise_id' => 'La classificazione richiede un Esercizio Aperto della stessa Azienda.']);
        }
        if (filled($payload['cost_center_id'] ?? null) && CostCenter::query()->where('company_id', $item->company_id)->whereNull('archived_at')->whereKey($payload['cost_center_id'])->doesntExist()) {
            throw ValidationException::withMessages(['cost_center_id' => 'Centro di Costo attivo non disponibile nella stessa Azienda.']);
        }

        return [...$result, 'exercise_id' => $payload['exercise_id'] ?? null, 'cost_center_id' => $payload['cost_center_id'] ?? null];
    }

    private static function dateOrder(string $from, mixed $to, string $field): void
    {
        if ($to !== null && Carbon::parse((string) $to)->lt(Carbon::parse($from))) {
            throw ValidationException::withMessages([$field => 'La data finale non può precedere quella iniziale.']);
        }
    }

    /**
     * @param  array<string, mixed>  $result
     * @return array<int, array<string, mixed>>
     */
    private static function projectedConditions(array $result): array
    {
        $conditions = collect(ProposalPlanData::rows($result['conditions'] ?? null, 'conditions'));
        foreach (ProposalPlanData::rows($result['planned_condition_changes'] ?? null, 'planned_condition_changes') as $change) {
            $conditions = $conditions->flatMap(function (array $condition) use ($change): array {
                if ((int) ($condition['id'] ?? 0) !== (int) $change['condition_id']) {
                    return [$condition];
                }
                $replacement = [...$condition, 'id' => 0, 'amount' => $change['amount'], 'cycle' => $change['cycle'], 'attribution_mode' => $change['attribution_mode'], 'valid_from' => $change['effective_date'], 'annulled_at' => null];
                if ($change['future_replacement'] ?? false) {
                    return [$replacement];
                }

                return [[...$condition, 'valid_to' => Carbon::parse((string) $change['effective_date'])->subDay()->toDateString()], $replacement];
            });
        }

        return $conditions->concat(ProposalPlanData::rows($result['planned_conditions'] ?? null, 'planned_conditions'))->values()->all();
    }
}
