<?php

namespace App\BusinessBackup\V1;

final class BusinessBackupContract
{
    public const FORMAT_VERSION = '1';

    public const MANIFEST = '_MP2_manifest';

    public const LONG_PAYLOADS = '_MP2_long_payloads';

    /** @var array<string, list<string>> */
    public const SCHEMAS = [
        '_MP2_company' => ['company_ref', 'name', 'timezone', 'overspend_note_required', 'unclassified_closing_policy'],
        '_MP2_suppliers' => ['supplier_ref', 'legal_name', 'vat_number', 'notes', 'archived_at'],
        '_MP2_supplier_contacts' => ['contact_ref', 'supplier_ref', 'first_name', 'last_name', 'phone', 'email', 'notes', 'role_tags_json'],
        '_MP2_cost_centers' => ['cost_center_ref', 'name', 'archived_at'],
        '_MP2_exercises' => ['exercise_ref', 'year', 'status'],
        '_MP2_projects' => ['project_ref', 'title', 'description', 'notes', 'initial_state', 'initial_effective_date', 'archived_at'],
        '_MP2_project_transitions' => ['transition_ref', 'project_ref', 'from_state', 'to_state', 'effective_date', 'reason', 'annulled_at', 'annulment_reason'],
        '_MP2_project_classes' => ['classification_ref', 'project_ref', 'exercise_ref', 'cost_center_ref'],
        '_MP2_contracts' => ['contract_ref', 'supplier_ref', 'title', 'notes', 'contractual_start_date', 'next_expiry_date', 'renewal_anchor_date', 'automatic_renewal', 'renewal_duration_months', 'notice_days', 'archived_at'],
        '_MP2_contract_renewals' => ['renewal_ref', 'contract_ref', 'effective_from', 'automatic_renewal', 'expiry_anchor_date', 'renewal_duration_months', 'notice_days'],
        '_MP2_contract_lifecycle' => ['lifecycle_ref', 'contract_ref', 'type', 'declared_contractual_date', 'state_change_date', 'renewed_expiry_date', 'renewal_ref', 'reason', 'annulled_at', 'annulment_reason'],
        '_MP2_contract_conditions' => ['condition_ref', 'contract_ref', 'cycle', 'attribution_mode', 'amount', 'valid_from', 'valid_to', 'reason', 'annulled_at'],
        '_MP2_contract_classes' => ['classification_ref', 'contract_ref', 'exercise_ref', 'cost_center_ref'],
        '_MP2_project_contract_links' => ['link_ref', 'project_ref', 'contract_ref', 'note', 'archived_at'],
        '_MP2_expenses' => ['expense_ref', 'exercise_ref', 'project_ref', 'contract_ref', 'supplier_ref', 'direct_cost_center_ref', 'origin', 'copied_from_expense_ref', 'description', 'notes', 'reversed_at'],
        '_MP2_expense_lines' => ['line_ref', 'expense_ref', 'type', 'amount', 'quantity', 'unit_amount', 'unit_of_measure', 'note', 'annulled_at'],
        '_MP2_project_deferrals' => ['deferral_ref', 'project_ref', 'source_exercise_ref', 'destination_exercise_ref', 'mode', 'carryover_amount', 'carryover_state', 'reprogrammed_amount', 'reprogramming_effects_json'],
        '_MP2_budgets' => ['budget_ref', 'exercise_ref', 'version', 'purpose', 'approved_at', 'previous_budget_ref', 'total', 'affected_exercises_json'],
        '_MP2_budget_rows' => ['budget_row_ref', 'budget_ref', 'source_type', 'source_ref', 'copied_from_source_ref', 'label', 'summary', 'supplier_ref', 'supplier_label', 'cost_center_ref', 'cost_center_label', 'approved_estimates', 'approved_carryover', 'carryover_state', 'approved_allocation', 'start_state', 'end_state', 'detail_version', 'detail_json'],
        '_MP2_budget_evidence' => ['evidence_ref', 'budget_ref', 'external_subject', 'external_venue', 'reason', 'attachment_ref', 'original_name', 'media_type', 'size', 'sha256'],
        '_MP2_closings' => ['closing_ref', 'exercise_ref', 'company_name', 'exercise_year', 'closed_at', 'initial_budget_ref', 'current_budget_ref', 'total_final_allocation', 'total_closing_actual', 'total_operational_variance', 'total_consolidated_carryover', 'warnings_json', 'settings_json', 'next_exercise_disposition', 'next_exercise_ref'],
        '_MP2_closing_rows' => ['closing_row_ref', 'closing_ref', 'source_type', 'source_ref', 'copied_from_source_ref', 'label', 'summary', 'supplier_ref', 'supplier_label', 'cost_center_ref', 'cost_center_label', 'end_state', 'has_actuals', 'final_estimates', 'received_carryover', 'final_allocation', 'closing_actual', 'operational_variance', 'detail_version', 'detail_json'],
        '_MP2_late_corrections' => ['correction_ref', 'exercise_ref', 'closing_ref', 'expense_ref', 'line_ref', 'original_line_ref', 'recorded_at', 'reason', 'belongs_to_closed_exercise', 'source_type', 'source_ref', 'label', 'owner_context_json', 'supplier_context_json'],
        '_MP2_error_annotations' => ['annotation_ref', 'exercise_ref', 'closing_ref', 'recorded_at', 'kind', 'reason', 'versions_json', 'facts_json', 'affected_source_refs_json'],
        '_MP2_attachments' => ['attachment_ref', 'owner_type', 'owner_ref', 'original_name', 'media_type', 'size', 'sha256', 'state'],
        self::LONG_PAYLOADS => ['payload_ref', 'target_sheet', 'target_row_ref', 'target_column', 'chunk_index', 'chunk_count', 'chunk_sha256', 'chunk_text'],
    ];

    /** @var array<string, string> */
    public const PREFIXES = [
        'company' => 'COM', 'supplier' => 'SUP', 'contact' => 'CON', 'cost_center' => 'CDC',
        'exercise' => 'EXE', 'project' => 'PRJ', 'project_transition' => 'PTR', 'project_classification' => 'PCL',
        'contract' => 'CTR', 'contract_renewal' => 'RCF', 'contract_lifecycle' => 'LCF', 'contract_condition' => 'CCN',
        'contract_classification' => 'CCL', 'project_contract_link' => 'PCLN', 'expense' => 'EXP', 'expense_line' => 'LIN',
        'project_deferral' => 'DEF', 'budget' => 'BUD', 'budget_row' => 'BUR', 'budget_evidence' => 'BEV',
        'closing' => 'CLS', 'closing_row' => 'CLR', 'late_correction' => 'LCR', 'annotation' => 'ANN',
        'attachment' => 'ATT', 'payload' => 'PAY',
    ];

    /** @var list<string> */
    public const VISIBLE_SHEETS = [
        'Informazioni', 'Riepilogo Esercizi', 'Budget', 'Spese', 'Progetti', 'Contratti',
        'Fornitori', 'Centri di Costo', 'Chiusure', 'Correzioni', 'Allegati',
    ];

    /** @var array<string, list<string>> */
    public const ENUMS = [
        '_MP2_company.unclassified_closing_policy' => ['warning', 'block'],
        '_MP2_exercises.status' => ['open', 'closed'],
        '_MP2_projects.initial_state' => ['planned', 'open', 'closed', 'cancelled'],
        '_MP2_project_transitions.from_state' => ['planned', 'open', 'closed', 'cancelled'],
        '_MP2_project_transitions.to_state' => ['planned', 'open', 'closed', 'cancelled'],
        '_MP2_contract_lifecycle.type' => ['activation', 'cessation', 'expiry_cessation', 'reactivation', 'cancellation', 'renewal'],
        '_MP2_contract_conditions.cycle' => ['monthly', 'quarterly', 'semiannual', 'annual'],
        '_MP2_contract_conditions.attribution_mode' => ['cycle_start', 'cycle_end'],
        '_MP2_expenses.origin' => ['manual', 'system'],
        '_MP2_expense_lines.type' => ['estimate', 'actual'],
        '_MP2_project_deferrals.mode' => ['none', 'carryover', 'reprogramming'],
        '_MP2_project_deferrals.carryover_state' => ['provisional', 'consolidated'],
        '_MP2_budgets.purpose' => ['initial_budget', 'revision'],
        '_MP2_budget_rows.source_type' => ['expense', 'project', 'contract'],
        '_MP2_closing_rows.source_type' => ['expense', 'project', 'contract'],
        '_MP2_closings.next_exercise_disposition' => ['created', 'already_existed', 'not_created'],
        '_MP2_late_corrections.source_type' => ['expense', 'project', 'contract'],
        '_MP2_error_annotations.kind' => ['cost_center', 'supplier', 'project', 'contract', 'container', 'exercise', 'historical_state', 'carryover', 'accidental_closing'],
        '_MP2_attachments.owner_type' => ['contract', 'expense', 'expense_line', 'historical_error_annotation'],
        '_MP2_attachments.state' => ['active', 'detached'],
    ];

    /** @var array<string, int> */
    public const DECIMALS = [
        '_MP2_contract_conditions.amount' => 2,
        '_MP2_expense_lines.amount' => 2,
        '_MP2_expense_lines.quantity' => 6,
        '_MP2_expense_lines.unit_amount' => 6,
        '_MP2_project_deferrals.carryover_amount' => 2,
        '_MP2_project_deferrals.reprogrammed_amount' => 2,
        '_MP2_budgets.total' => 2,
        '_MP2_budget_rows.approved_estimates' => 2,
        '_MP2_budget_rows.approved_carryover' => 2,
        '_MP2_budget_rows.approved_allocation' => 2,
        '_MP2_closings.total_final_allocation' => 2,
        '_MP2_closings.total_closing_actual' => 2,
        '_MP2_closings.total_operational_variance' => 2,
        '_MP2_closings.total_consolidated_carryover' => 2,
        '_MP2_closing_rows.final_estimates' => 2,
        '_MP2_closing_rows.received_carryover' => 2,
        '_MP2_closing_rows.final_allocation' => 2,
        '_MP2_closing_rows.closing_actual' => 2,
        '_MP2_closing_rows.operational_variance' => 2,
    ];

    /** @return list<string> */
    public static function machineSheets(): array
    {
        return array_keys(self::SCHEMAS);
    }
}
