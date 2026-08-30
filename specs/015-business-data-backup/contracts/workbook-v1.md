# Workbook Contract V1

## Invariants

- The artifact is one Office Open XML workbook with `format_version` equal to `1`.
- The first row of every machine sheet is the exact ordered header below. Missing, additional, renamed, or reordered columns invalidate the package.
- Every machine cell is written as an explicit string. Empty nullable values are represented by an empty cell; booleans are `0` or `1`; dates are `YYYY-MM-DD`; timestamps are ISO 8601 with offset; JSON is canonical UTF-8 JSON with recursively sorted object keys.
- Monetary amounts use a signed decimal string with exactly two fractional digits. Quantities and unit amounts use their database scale without exponent notation.
- Package references are unique, deterministic within the package, and have no meaning outside it. Source database IDs, ordinary timestamps, revision tokens, proposal identifiers, audit identifiers, and operation UUIDs are forbidden.
- A value longer than 30,000 UTF-8 bytes is replaced with `@payload:<payload_ref>` and stored in `_MP2_long_payloads` as ordered chunks. A literal beginning with `@payload:` is encoded through the same mechanism.
- Machine sheets contain no formulas. Visible sheets are derived views and are not restore inputs.

## Portable reference prefixes

References are assigned after ordering the source records by their local primary key. The number is zero-padded to ten digits.

| Prefix | Entity |
|---|---|
| `COM` | Company |
| `SUP` | Supplier |
| `CON` | Supplier contact |
| `CDC` | Cost center |
| `EXE` | Exercise |
| `PRJ` | Project |
| `PTR` | Project transition |
| `PCL` | Project/exercise classification |
| `CTR` | Contract |
| `RCF` | Contract renewal configuration |
| `LCF` | Contract lifecycle fact |
| `CCN` | Contract condition |
| `CCL` | Contract/exercise classification |
| `PCLN` | Project-contract link |
| `EXP` | Expense |
| `LIN` | Expense line |
| `DEF` | Project deferral |
| `BUD` | Budget snapshot |
| `BUR` | Budget row |
| `BEV` | Budget evidence |
| `CLS` | Closing snapshot |
| `CLR` | Closing row |
| `LCR` | Late correction |
| `ANN` | Historical error annotation |
| `ATT` | Attachment inventory entry |
| `PAY` | Long payload |

Example: `SUP-0000000001`.

## Visible sheets

Visible sheets are `Informazioni`, `Riepilogo Esercizi`, `Budget`, `Spese`, `Progetti`, `Contratti`, `Fornitori`, `Centri di Costo`, `Chiusure`, `Correzioni`, and `Allegati`.

`Informazioni` contains the warning that the workbook is a consultable backup, analysis must be performed on a copy, and any modification invalidates guaranteed restore. The remaining views use Italian labels, materialized values, EUR net of VAT, and do not contain formulas. Their contents are informative; validation and restore use only the machine sheets.

## Machine sheets

All sheets in this section are hidden.

### `_MP2_manifest`

`key`, `value`

Required keys, in order: `format_version`, `package_id`, `exported_at`, `application_revision`, `company_ref`, `company_name`, `company_timezone`, `currency`, `vat_basis`, `machine_sheet_count`, followed by one `row_count:<sheet>` and one `sha256:<sheet>` for every machine data sheet other than manifest, then one `view_sha256:<sheet>` for every visible sheet. `currency` is `EUR`; `vat_basis` is `net`. Visible checksums make accidental edits diagnosable; machine checksums remain the authoritative restore integrity contract.

### `_MP2_company`

`company_ref`, `name`, `timezone`, `overspend_note_required`, `unclassified_closing_policy`

Exactly one row is required and `company_ref` is `COM-0000000001`.

### `_MP2_suppliers`

`supplier_ref`, `legal_name`, `vat_number`, `notes`, `archived_at`

### `_MP2_supplier_contacts`

`contact_ref`, `supplier_ref`, `first_name`, `last_name`, `phone`, `email`, `notes`, `role_tags_json`

`role_tags_json` is a JSON array of strings preserving its business order.

### `_MP2_cost_centers`

`cost_center_ref`, `name`, `archived_at`

### `_MP2_exercises`

`exercise_ref`, `year`, `status`

### `_MP2_projects`

`project_ref`, `title`, `description`, `notes`, `initial_state`, `initial_effective_date`, `archived_at`

### `_MP2_project_transitions`

`transition_ref`, `project_ref`, `from_state`, `to_state`, `effective_date`, `reason`, `annulled_at`, `annulment_reason`

The absent historical author is intentional and is restored as null.

### `_MP2_project_classes`

`classification_ref`, `project_ref`, `exercise_ref`, `cost_center_ref`

### `_MP2_contracts`

`contract_ref`, `supplier_ref`, `title`, `notes`, `contractual_start_date`, `next_expiry_date`, `renewal_anchor_date`, `automatic_renewal`, `renewal_duration_months`, `notice_days`, `archived_at`

### `_MP2_contract_renewals`

`renewal_ref`, `contract_ref`, `effective_from`, `automatic_renewal`, `expiry_anchor_date`, `renewal_duration_months`, `notice_days`

### `_MP2_contract_lifecycle`

`lifecycle_ref`, `contract_ref`, `type`, `declared_contractual_date`, `state_change_date`, `renewed_expiry_date`, `renewal_ref`, `reason`, `annulled_at`, `annulment_reason`

### `_MP2_contract_conditions`

`condition_ref`, `contract_ref`, `cycle`, `attribution_mode`, `amount`, `valid_from`, `valid_to`, `reason`, `annulled_at`

### `_MP2_contract_classes`

`classification_ref`, `contract_ref`, `exercise_ref`, `cost_center_ref`

### `_MP2_project_contract_links`

`link_ref`, `project_ref`, `contract_ref`, `note`, `archived_at`

### `_MP2_expenses`

`expense_ref`, `exercise_ref`, `project_ref`, `contract_ref`, `supplier_ref`, `direct_cost_center_ref`, `origin`, `copied_from_expense_ref`, `description`, `notes`, `reversed_at`

Exactly one of `project_ref` and `contract_ref` is present when the current domain requires an owner; direct expenses may leave both empty. `copied_from_expense_ref` replaces the source `copied_from_origin_key`.

### `_MP2_expense_lines`

`line_ref`, `expense_ref`, `type`, `amount`, `quantity`, `unit_amount`, `unit_of_measure`, `note`, `annulled_at`

Line presence is preserved independently from its net contribution, including actual lines that sum to zero.

### `_MP2_project_deferrals`

`deferral_ref`, `project_ref`, `source_exercise_ref`, `destination_exercise_ref`, `mode`, `carryover_amount`, `carryover_state`, `reprogrammed_amount`, `reprogramming_effects_json`

`reprogramming_effects_json` is either empty or an array ordered by effect creation order. Each object has exactly: `source_line_ref`, `destination_expense_ref`, `destination_line_ref`, `source_line_before`, `source_line_after`, `destination_line`. Each line-state object has exactly `type`, `amount`, `quantity`, `unit_amount`, `unit_of_measure`, `note`, `annulled_at`. New local revision values and a new local operation UUID are generated consistently during restore; neither is transported.

### `_MP2_budgets`

`budget_ref`, `exercise_ref`, `version`, `purpose`, `approved_at`, `previous_budget_ref`, `total`, `affected_exercises_json`

`affected_exercises_json` is the ordered materialized business impact array with every Exercise and source identity converted to portable references; Proposal/action/audit identities and operation UUIDs are absent. Historical author and source Proposal are intentionally absent.

### `_MP2_budget_rows`

`budget_row_ref`, `budget_ref`, `source_type`, `source_ref`, `copied_from_source_ref`, `label`, `summary`, `supplier_ref`, `supplier_label`, `cost_center_ref`, `cost_center_label`, `approved_estimates`, `approved_carryover`, `carryover_state`, `approved_allocation`, `start_state`, `end_state`, `detail_version`, `detail_json`

`source_ref` and `copied_from_source_ref` refer to a Project, Contract, or Expense according to `source_type`; they replace `origin_id`, `origin_key`, and Proposal item identifiers. `detail_json` uses the explicit portable detail schemas below.

### `_MP2_budget_evidence`

`evidence_ref`, `budget_ref`, `external_subject`, `external_venue`, `reason`, `attachment_ref`, `original_name`, `media_type`, `size`, `sha256`

The attachment reference is optional. Storage coordinates are forbidden and no attachment is recreated.

### `_MP2_closings`

`closing_ref`, `exercise_ref`, `company_name`, `exercise_year`, `closed_at`, `initial_budget_ref`, `current_budget_ref`, `total_final_allocation`, `total_closing_actual`, `total_operational_variance`, `total_consolidated_carryover`, `warnings_json`, `settings_json`, `next_exercise_disposition`, `next_exercise_ref`

The historical author is intentionally absent. The snapshot values are restored as supplied and are not recalculated from live data.

### `_MP2_closing_rows`

`closing_row_ref`, `closing_ref`, `source_type`, `source_ref`, `copied_from_source_ref`, `label`, `summary`, `supplier_ref`, `supplier_label`, `cost_center_ref`, `cost_center_label`, `end_state`, `has_actuals`, `final_estimates`, `received_carryover`, `final_allocation`, `closing_actual`, `operational_variance`, `detail_version`, `detail_json`

The portable source and detail rules are the same as for Budget rows.

### `_MP2_late_corrections`

`correction_ref`, `exercise_ref`, `closing_ref`, `expense_ref`, `line_ref`, `original_line_ref`, `recorded_at`, `reason`, `belongs_to_closed_exercise`, `source_type`, `source_ref`, `label`, `owner_context_json`, `supplier_context_json`

`recorded_at` is the domain-significant original creation timestamp. Historical author and operation UUID are intentionally absent.

### `_MP2_error_annotations`

`annotation_ref`, `exercise_ref`, `closing_ref`, `recorded_at`, `kind`, `reason`, `versions_json`, `facts_json`, `affected_source_refs_json`

The JSON fields use only documented business scalars and portable source references. Historical author and operation UUID are intentionally absent.

### `_MP2_attachments`

`attachment_ref`, `owner_type`, `owner_ref`, `original_name`, `media_type`, `size`, `sha256`, `state`

Allowed `owner_type` values are `contract`, `expense`, `expense_line`, and `historical_error_annotation`. Proposal-owned attachments are excluded. `state` is `active` or `detached`. This is an inventory only and is never persisted as an Attachment during restore.

### `_MP2_long_payloads`

`payload_ref`, `target_sheet`, `target_row_ref`, `target_column`, `chunk_index`, `chunk_count`, `chunk_sha256`, `chunk_text`

Chunks are numbered from `1`, each is at most 30,000 UTF-8 bytes and ends only at a code-point boundary. The concatenated value must match the checksum of the canonical target sheet after expansion.

## Portable materialized detail

`detail_json` is not an Eloquent serialization. V1 allows only these recursively sorted objects:

- `project`: descriptive state, exercise classification by `cost_center_ref`, transition facts without actors, and approved/materialized calculation components expressed as decimal strings.
- `contract`: contractual dates, renewal configuration and lifecycle/condition facts without actors, exercise classification by `cost_center_ref`, and approved/materialized calculation components expressed as decimal strings.
- `expense`: `expense_ref`, owner references, supplier/cost-center references, origin, reversal state, and line objects using `line_ref` and the line business fields.
- `correction_context`: labels and business scalars needed by the historical view, with any source represented by `source_type` plus `source_ref`.

Keys ending in `_id`, local origin keys, proposal/proposal-item/action/audit identifiers, revision values, operation UUIDs, storage paths, and ordinary persistence timestamps invalidate the package wherever they occur.

## Canonical checksum

For each machine data sheet, expand long payload references, then encode the exact ordered object below using UTF-8 JSON without insignificant whitespace:

```json
{"columns":["first_column"],"rows":[["first_value"]]}
```

Object keys are fixed as shown; columns and rows retain contract order; every cell value is a string. The lowercase hexadecimal SHA-256 of those bytes is recorded in the manifest. `_MP2_manifest` is not self-checksummed. `_MP2_long_payloads` is checksummed using its literal chunk rows to make both chunk structure and reconstructed values tamper-evident.
