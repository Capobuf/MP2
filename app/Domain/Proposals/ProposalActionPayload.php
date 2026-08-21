<?php

namespace App\Domain\Proposals;

use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

final class ProposalActionPayload
{
    /** @var array<string, list<string>> */
    private const ALLOWED = [
        'create_expense' => ['description', 'notes', 'exercise_id', 'supplier_id', 'cost_center_id', 'project_id', 'project_item_id', 'estimate_lines'],
        'copy_expense' => ['source_expense_id', 'target_exercise_id', 'description', 'notes', 'supplier_id', 'cost_center_id', 'estimate_lines'],
        'set_expense_estimates' => ['estimate_lines'], 'set_expense_owner' => ['exercise_id', 'project_id', 'project_item_id'],
        'set_expense_supplier' => ['supplier_id'], 'set_expense_cost_center' => ['cost_center_id'], 'reverse_expense' => ['reason'], 'restore_expense' => ['reason'],
        'create_project' => ['title', 'description', 'notes', 'initial_state', 'initial_effective_date', 'exercise_id', 'cost_center_id'],
        'plan_project_child_expenses' => ['child_item_ids', 'existing_expenses'], 'set_project_cost_center' => ['exercise_id', 'cost_center_id'],
        'plan_project_transition' => ['from_state', 'to_state', 'effective_date', 'reason'],
        'create_contract' => ['title', 'notes', 'supplier_id', 'contractual_start_date', 'next_expiry_date', 'automatic_renewal', 'renewal_duration_months', 'notice_days', 'exercise_id', 'cost_center_id'],
        'add_contract_condition' => ['cycle', 'attribution_mode', 'amount', 'valid_from', 'valid_to', 'reason'],
        'change_contract_economics' => ['condition_id', 'amount', 'cycle', 'attribution_mode', 'requested_date', 'confirmed_effective_date', 'minimum_date', 'effective_date', 'delay_reason', 'no_prorata', 'future_replacement', 'exercise_impacts', 'effective_date_confirmed', 'reason'],
        'plan_contract_lifecycle' => ['type', 'declared_contractual_date', 'effective_date', 'next_expiry_date', 'reason'],
        'set_contract_renewal' => ['effective_from', 'expiry_anchor_date', 'automatic_renewal', 'renewal_duration_months', 'notice_days'],
        'set_contract_cost_center' => ['exercise_id', 'cost_center_id'],
        'link_project_contract' => ['project_origin_key', 'project_item_id', 'contract_origin_key', 'contract_item_id', 'restore_link_id'],
    ];

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public static function validate(ProposalActionType $type, array $payload): array
    {
        BudgetPayloadGuard::assertPlanOnly($payload);
        $allowed = self::ALLOWED[$type->value];
        $unknown = array_diff(array_keys($payload), $allowed);
        if ($unknown !== []) {
            throw ValidationException::withMessages(['payload' => 'Campi non ammessi: '.implode(', ', $unknown).'.']);
        }

        match ($type) {
            ProposalActionType::CreateExpense => self::require($payload, ['description', 'exercise_id', 'estimate_lines']),
            ProposalActionType::CopyExpense => self::require($payload, ['source_expense_id', 'target_exercise_id', 'description', 'estimate_lines']),
            ProposalActionType::SetExpenseEstimates => self::require($payload, ['estimate_lines']),
            ProposalActionType::CreateProject => self::require($payload, ['title', 'initial_state', 'initial_effective_date', 'exercise_id']),
            ProposalActionType::PlanProjectTransition => self::require($payload, ['from_state', 'to_state', 'effective_date']),
            ProposalActionType::CreateContract => self::require($payload, ['title', 'supplier_id', 'contractual_start_date', 'exercise_id']),
            ProposalActionType::AddContractCondition => self::require($payload, ['cycle', 'attribution_mode', 'amount', 'valid_from']),
            ProposalActionType::ChangeContractEconomics => self::require($payload, ['condition_id', 'amount', 'cycle', 'attribution_mode', 'requested_date', 'confirmed_effective_date']),
            ProposalActionType::PlanContractLifecycle => self::require($payload, ['type', 'declared_contractual_date', 'effective_date']),
            ProposalActionType::LinkProjectContract => self::validateRelation($payload),
            default => null,
        };
        if (isset($payload['estimate_lines'])) {
            self::validateEstimateLines($payload['estimate_lines']);
        }
        if (isset($payload['existing_expenses'])) {
            if (! is_array($payload['existing_expenses'])) {
                throw ValidationException::withMessages(['existing_expenses' => 'Le Spese figlie devono essere un elenco.']);
            }
            foreach ($payload['existing_expenses'] as $index => $expense) {
                if (! is_array($expense) || array_diff(array_keys($expense), ['expense_id', 'estimate_lines']) !== [] || ! isset($expense['expense_id'])) {
                    throw ValidationException::withMessages(["existing_expenses.$index" => 'Forma Spesa figlia non valida.']);
                }
                self::validateEstimateLines($expense['estimate_lines'] ?? null);
            }
        }
        foreach (['project_item_id', 'project_item_ids', 'contract_item_id', 'child_item_ids'] as $key) {
            if (! isset($payload[$key])) {
                continue;
            }
            $values = is_array($payload[$key]) ? $payload[$key] : [$payload[$key]];
            foreach ($values as $uuid) {
                if ((! is_string($uuid) && ! $uuid instanceof \Stringable) || ! Str::isUuid((string) $uuid)) {
                    throw ValidationException::withMessages([$key => 'ProposalItemID non valido.']);
                }
            }
            $payload[$key] = is_array($payload[$key]) ? array_map('strval', $values) : (string) $payload[$key];
        }

        return $payload;
    }

    /**
     * @param  array<string, mixed>  $payload
     * @param  list<string>  $keys
     */
    private static function require(array $payload, array $keys): void
    {
        foreach ($keys as $key) {
            if (! array_key_exists($key, $payload) || $payload[$key] === '' || $payload[$key] === null) {
                throw ValidationException::withMessages([$key => 'Campo obbligatorio.']);
            }
        }
    }

    private static function validateEstimateLines(mixed $lines): void
    {
        if (! is_array($lines)) {
            throw ValidationException::withMessages(['estimate_lines' => 'Le Righe Stima devono essere un elenco.']);
        }
        foreach ($lines as $index => $line) {
            if (! is_array($line) || array_diff(array_keys($line), ['proposal_line_id', 'line_id', 'amount', 'note', 'annulled']) !== []) {
                throw ValidationException::withMessages(["estimate_lines.$index" => 'Forma Riga Stima non valida.']);
            }
            self::require($line, ['proposal_line_id', 'amount', 'annulled']);
            if (! Str::isUuid((string) $line['proposal_line_id']) || ! is_numeric($line['amount']) || bccomp((string) $line['amount'], '0', 2) < 0) {
                throw ValidationException::withMessages(["estimate_lines.$index" => 'Riga Stima non valida.']);
            }
        }
    }

    /** @param array<string, mixed> $payload */
    private static function validateRelation(array $payload): void
    {
        if ((isset($payload['project_origin_key']) ? 1 : 0) + (isset($payload['project_item_id']) ? 1 : 0) !== 1 || (isset($payload['contract_origin_key']) ? 1 : 0) + (isset($payload['contract_item_id']) ? 1 : 0) !== 1) {
            throw ValidationException::withMessages(['payload' => 'Indicare esattamente un riferimento Progetto e uno Contratto.']);
        }
    }
}
