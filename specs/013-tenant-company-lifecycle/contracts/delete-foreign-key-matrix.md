# Contract: Foreign-key actions for whole-Tenant deletion

This matrix is the authoritative implementation inventory for `2026_08_26_000200_enable_tenant_company_deletion.php`. Constraint names come from the current migrations; implementation must compare them with MySQL `information_schema.REFERENTIAL_CONSTRAINTS` before altering. A missing, renamed or additional tenant-owned constraint stops the migration work for explicit reconciliation.

All listed constraints currently use `RESTRICT` and change to `CASCADE`, except the two rows explicitly marked `SET NULL`. The migration `down()` restores `RESTRICT`. Constraints pointing to global `users` are listed separately and never altered.

## Direct dependency on Company

| Child table | Constraint | New `ON DELETE` |
|---|---|---|
| `company_capabilities` | `company_capabilities_company_id_foreign` | CASCADE |
| `audit_events` | `audit_events_company_id_foreign` | CASCADE |
| `suppliers` | `suppliers_company_id_foreign` | CASCADE |
| `cost_centers` | `cost_centers_company_id_foreign` | CASCADE |
| `exercises` | `exercises_company_id_foreign` | CASCADE |
| `expenses` | `expenses_company_id_foreign` | CASCADE |
| `projects` | `projects_company_id_foreign` | CASCADE |
| `project_transitions` | `project_transitions_company_id_foreign` | CASCADE |
| `project_exercise_classifications` | `project_exercise_classifications_company_id_foreign` | CASCADE |
| `contracts` | `contracts_company_id_foreign` | CASCADE |
| `contract_renewal_configurations` | `contract_renewal_configurations_company_id_foreign` | CASCADE |
| `contract_lifecycle_facts` | `contract_lifecycle_facts_company_id_foreign` | CASCADE |
| `contract_conditions` | `contract_conditions_company_id_foreign` | CASCADE |
| `contract_exercise_classifications` | `contract_exercise_classifications_company_id_foreign` | CASCADE |
| `project_contract_links` | `project_contract_links_company_id_foreign` | CASCADE |
| `attachments` | `attachments_company_id_foreign` | CASCADE |
| `proposals` | `proposals_company_id_foreign` | CASCADE |
| `budget_snapshots` | `budget_snapshots_company_id_foreign` | CASCADE |
| `project_deferrals` | `project_deferrals_company_id_foreign` | CASCADE |
| `closing_snapshots` | `closing_snapshots_company_id_foreign` | CASCADE |
| `closing_source_rows` | `closing_source_rows_company_id_foreign` | CASCADE |
| `late_corrections` | `late_corrections_company_id_foreign` | CASCADE |
| `historical_error_annotations` | `historical_error_annotations_company_id_foreign` | CASCADE |

`tenant_companies_company_id_foreign` is created with CASCADE by migration `000100`; it is verified but not re-altered by `000200`.

## Indirect ownership and same-Tenant cross-links

| Child table | Constraint | New `ON DELETE` |
|---|---|---|
| `supplier_contacts` | `supplier_contacts_supplier_id_foreign` | CASCADE |
| `expenses` | `expenses_exercise_company_foreign` | CASCADE |
| `expenses` | `expenses_supplier_company_foreign` | CASCADE |
| `expenses` | `expenses_cost_center_company_foreign` | CASCADE |
| `expenses` | `expenses_project_company_foreign` | CASCADE |
| `expenses` | `expenses_contract_company_foreign` | CASCADE |
| `expense_lines` | `expense_lines_expense_id_foreign` | CASCADE |
| `project_transitions` | `project_transitions_project_company_foreign` | CASCADE |
| `project_exercise_classifications` | `project_classifications_project_company_foreign` | CASCADE |
| `project_exercise_classifications` | `project_classifications_exercise_company_foreign` | CASCADE |
| `project_exercise_classifications` | `project_classifications_cost_center_company_foreign` | CASCADE |
| `contracts` | `contracts_supplier_company_foreign` | CASCADE |
| `contract_renewal_configurations` | `contract_renewal_configurations_contract_company_foreign` | CASCADE |
| `contract_lifecycle_facts` | `contract_lifecycle_facts_contract_company_foreign` | CASCADE |
| `contract_lifecycle_facts` | `contract_lifecycle_facts_configuration_company_foreign` | CASCADE |
| `contract_conditions` | `contract_conditions_contract_company_foreign` | CASCADE |
| `contract_exercise_classifications` | `contract_classifications_contract_company_foreign` | CASCADE |
| `contract_exercise_classifications` | `contract_classifications_exercise_company_foreign` | CASCADE |
| `contract_exercise_classifications` | `contract_classifications_cost_center_company_foreign` | CASCADE |
| `project_contract_links` | `project_contract_links_project_company_foreign` | CASCADE |
| `project_contract_links` | `project_contract_links_contract_company_foreign` | CASCADE |
| `attachments` | `attachments_expense_line_id_foreign` | CASCADE |
| `attachments` | `attachments_contract_company_foreign` | CASCADE |
| `attachments` | `attachments_expense_company_foreign` | CASCADE |
| `attachments` | `attachments_proposal_company_foreign` | CASCADE |
| `attachments` | `attachments_historical_annotation_company_foreign` | CASCADE |
| `proposals` | `proposals_exercise_company_foreign` | CASCADE |
| `proposals` | `proposals_reference_budget_id_foreign` | **SET NULL** |
| `proposal_items` | `proposal_items_proposal_company_foreign` | CASCADE |
| `proposal_items` | `proposal_items_expense_company_foreign` | CASCADE |
| `proposal_items` | `proposal_items_project_company_foreign` | CASCADE |
| `proposal_items` | `proposal_items_contract_company_foreign` | CASCADE |
| `proposal_actions` | `proposal_actions_proposal_company_foreign` | CASCADE |
| `proposal_actions` | `proposal_actions_item_company_foreign` | CASCADE |
| `budget_snapshots` | `budget_snapshots_exercise_company_foreign` | CASCADE |
| `budget_snapshots` | `budget_snapshots_proposal_company_foreign` | CASCADE |
| `budget_snapshots` | `budget_snapshots_previous_budget_id_foreign` | **SET NULL** |
| `budget_source_rows` | `budget_rows_snapshot_company_foreign` | CASCADE |
| `budget_evidence` | `budget_evidence_snapshot_company_foreign` | CASCADE |
| `budget_evidence` | `budget_evidence_attachment_company_foreign` | CASCADE |
| `project_deferrals` | `project_deferrals_project_company_foreign` | CASCADE |
| `project_deferrals` | `project_deferrals_source_company_foreign` | CASCADE |
| `project_deferrals` | `project_deferrals_destination_company_foreign` | CASCADE |
| `closing_snapshots` | `closing_snapshots_exercise_company_foreign` | CASCADE |
| `closing_snapshots` | `closing_snapshots_initial_budget_company_foreign` | CASCADE |
| `closing_snapshots` | `closing_snapshots_current_budget_company_foreign` | CASCADE |
| `closing_snapshots` | `closing_snapshots_next_exercise_company_foreign` | CASCADE |
| `closing_snapshots` | `closing_initial_budget_exercise_company_fk` | CASCADE |
| `closing_snapshots` | `closing_current_budget_exercise_company_fk` | CASCADE |
| `closing_source_rows` | `closing_rows_snapshot_company_foreign` | CASCADE |
| `late_corrections` | `late_corrections_exercise_company_foreign` | CASCADE |
| `late_corrections` | `late_corrections_snapshot_company_foreign` | CASCADE |
| `late_corrections` | `late_corrections_expense_company_foreign` | CASCADE |
| `late_corrections` | `late_corrections_expense_line_id_foreign` | CASCADE |
| `late_corrections` | `late_corrections_original_expense_line_id_foreign` | CASCADE |
| `historical_error_annotations` | `historical_annotations_exercise_company_foreign` | CASCADE |
| `historical_error_annotations` | `historical_annotations_snapshot_company_foreign` | CASCADE |

## Global User constraints left RESTRICT

Do not alter any FK whose parent is `users`, including:

- `company_capabilities.user_id`;
- `audit_events.actor_id`, `audit_events.beneficiary_id`;
- creator/annuller fields on Project transitions and Contract configurations/facts/conditions;
- uploader/detacher fields on Attachments;
- creator/approver/discarder/withdrawer/alignment fields on Proposals, Proposal items/actions and Budgets;
- `closing_snapshots.closed_by_id`;
- `late_corrections.recorded_by_id`;
- `historical_error_annotations.recorded_by_id`.

Deleting a Company cascades the child row that contains a User reference. It never deletes the User parent.

## Verification requirements

1. Before migration, compare this matrix with `information_schema.REFERENTIAL_CONSTRAINTS` and current migrations.
2. After migration, assert every listed update/delete rule, and assert every User constraint remains RESTRICT.
3. Run one complete Company-root deletion with all relation families populated.
4. Run ordinary per-record delete rejection tests to prove the feature did not expose those database cascades as application operations.
5. If MySQL rejects any cascade topology, stop and report the exact constraint path; do not disable `foreign_key_checks` or replace the matrix with an unreviewed manual delete list.
