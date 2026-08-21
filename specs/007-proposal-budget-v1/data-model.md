# Data Model: Initial Proposal and Budget v1

## Proposal

| Field | Shape | Rules |
|---|---|---|
| `id` | stable integer identity | Never reused or physically deleted |
| `company_id` | Company reference | Required; restrict deletion |
| `exercise_id` | main Exercise reference | Same Company; Open; no Budget at creation/approval |
| `purpose` | `initial_budget` | Only S6 purpose |
| `status` | `draft`, `approved`, `discarded` | Closed vocabulary; terminal rows immutable |
| `created_by_id` | User reference | Required |
| `approved_by_id` | nullable User reference | Set only atomically with approval |
| `approved_at` | nullable UTC timestamp | Set only atomically with approval |
| `approval_operation_id` | nullable UUID | Unique retry identity after approval |
| `revision` | unsigned monotone integer | Incremented for every Proposal mutation |
| timestamps | UTC | Persistent/resumable Draft metadata |

A generated nullable `active_exercise_id` equals `exercise_id` only for Draft rows;
unique `(company_id, active_exercise_id)` prevents concurrent active alternatives.
The Action also locks Company and Exercise and rejects any existing Budget.

## Proposal Item

| Field | Shape | Rules |
|---|---|---|
| `id` | stable integer identity | Never reused/deleted |
| `proposal_item_id` | UUID | Public Proposal identity, globally unique |
| `company_id`, `proposal_id` | tenant/proposal references | Required and same Company |
| `source_type` | `expense`, `project`, `contract` | Closed vocabulary |
| `expense_id`, `project_id`, `contract_id` | nullable live references | Exactly one for existing source; none for new source |
| `copied_from_origin_key` | nullable string | Required for canonical copy; immutable lineage |
| `baseline_revision` | nullable unsigned integer | Required for existing source |
| `baseline_fingerprint` | nullable SHA-256 | Required for existing source |
| `baseline` | immutable JSON | Canonical plan facts plus separate read-only Actual context |
| `result` | strict JSON | Current plan-only result; no Actual/Forecast/Closing keys |
| `readiness_state` | four canonical states | Default `aligned` for initialized/new valid Items |
| `readiness_reasons` | JSON list | Closed reason codes with Italian messages |
| `read_only_source` | boolean | True for qualifying archived/storned source until a supported restore action |
| `last_aligned_at/by` | nullable | Initialization metadata in S6; S7 extends resolution |
| timestamps | UTC | Ordered through Proposal revision |

Checks enforce source-type/reference congruence and same-Proposal unique live source.
New proposed objects never receive a live ID. `actual_context` is display-only and is
removed before any Budget payload is built.

## Proposal Action

| Field | Shape | Rules |
|---|---|---|
| `id` | stable identity | Never deleted |
| `company_id`, `proposal_id`, `proposal_item_id` | owner references | Same Company and Draft |
| `sequence` | positive integer | Unique per Proposal; approval order |
| `action_type` | closed enum | Source-specific typed operation |
| `payload_version` | positive integer | Starts at `1` |
| `payload` | strict JSON | Validated shape; unknown keys rejected |
| `reason` | nullable text | Required by the referenced canonical action |
| `created_by_id` | User | Required |
| `operation_id` | UUID | Unique Item-action retry receipt |
| timestamps | UTC | Audit metadata |

S6 action families are:

```text
Expense: create, copy, set_estimates, set_owner, set_supplier,
         set_cost_center, reverse, restore
Project: create, plan_child_expenses, set_cost_center, plan_transition
Contract: create, add_condition, change_economics, plan_lifecycle,
          set_renewal, set_cost_center
Relation: link_project_contract
```

Expense `set_estimates` and Project `plan_child_expenses` contain complete planned
Estimate Line facts and explicit annul/restore decisions; they never contain Actual
Lines. A Project has no direct Estimate row: its plan is always represented by child
Expenses. Unsupported S7/S8+ action types are absent from the enum.

## Budget Snapshot

| Field | Shape | Rules |
|---|---|---|
| `id` | stable identity | Never updated/deleted |
| `company_id`, `exercise_id` | owner references | Same Company |
| `proposal_id` | approved Proposal | Unique |
| `version` | positive integer | `1` in S6; unique per Exercise |
| `purpose` | `initial_budget` | Materialized designation |
| `approved_at`, `approved_by_id` | immutable approval facts | Required |
| `previous_budget_id` | nullable | Null for v1 |
| `total_approved_allocation` | decimal `(19,2)` | Exact Exercise formula: autonomous Expenses + Projects + Contracts; child Expenses are not counted twice |
| `affected_exercises` | strict JSON | IDs, years and applied impacts |
| `operation_id` | UUID | Unique approval retry receipt |
| timestamps | UTC | Creation only |

Model hooks reject update/delete. Policies expose read only. Database foreign keys
restrict parent deletion and uniqueness guarantees one v1.

## Budget Source Row

| Field | Shape | Rules |
|---|---|---|
| `id` | stable immutable identity | Never updated/deleted |
| `company_id`, `budget_snapshot_id` | owner references | Required/same Company |
| `source_type`, `origin_id`, `origin_key` | final live identity | Required |
| `proposal_item_id` | source Proposal UUID | Required; unique in Budget |
| `copied_from_origin_key` | nullable lineage | Materialized |
| `label`, `summary` | strings | Materialized, never live-resolved |
| `supplier_id`, `supplier_label` | nullable facts | Materialized when applicable |
| `cost_center_id`, `cost_center_label` | nullable facts | Label uses `Non classificato` when null |
| `approved_estimates` | decimal `(19,2)` | Plan-only amount |
| `approved_carryover` | decimal `(19,2)` | `0.00` in S6 unless produced by an already verified operation |
| `carryover_state` | nullable | No S8 operation is introduced |
| `approved_allocation` | decimal `(19,2)` | Estimates plus applicable verified carryover |
| `start_state`, `end_state` | nullable strings | Materialized source state or absent marker |
| `detail_version` | positive integer | Starts at `1` |
| `detail` | strict source-specific JSON | Estimates, transitions, conditions, cycles, actions, reasons, relations, event refs |

The row/detail contract recursively rejects Actual, variance, residual, saving,
overspend-final, late-correction, Forecast and Closing fields. The header total is
recomputed before commit from autonomous Expense rows plus Project rows plus Contract
rows. Child Expense rows remain materialized for drill-down but are not summed twice.

## Budget Evidence

| Field | Shape | Rules |
|---|---|---|
| `id` | stable immutable identity | Never updated/deleted |
| `company_id`, `budget_snapshot_id` | owner | Required/same Company |
| `external_subject`, `external_venue` | nullable strings | External approval facts |
| `reason` | nullable text | Initial approval note; Revision reason is S7 |
| `attachment_id` | nullable original Attachment | Same Company when present |
| `storage_disk`, `storage_path` | nullable immutable blob reference | Materialized |
| `original_name`, `media_type`, `size_bytes`, `sha256` | nullable attachment facts | Complete when attachment exists |

One evidence row stores external facts; further rows store retained attachments.
The original attachment may later be detached, but its blob and Budget evidence stay.

The existing Attachment owner constraint is extended with nullable `proposal_id` so
new external evidence can be uploaded privately to a Draft before approval. Exactly
one of Contract, Expense, Expense Line, or Proposal remains required. Upload remains
an atomic versioned Attachment command; approval only selects locked same-company
Draft/live attachments and materializes their immutable facts.

## Source baseline and inclusion payloads

For each source type the canonicalizer produces stable-key, deterministically ordered
arrays. It includes all facts listed by §12.11 when present. Collections are ordered
by stable ID/date before hashing. The payload has two top-level keys:

```text
plan_baseline   editable planning facts
actual_context  read-only active Actual facts and HasActuals
```

Automatic inclusion is recalculated from live sources for the main Exercise. The
Proposal stores the included OriginKey set implicitly through its existing-source
Items. Set difference on review creates `Da prendere in visione`; missing/changed
source fingerprints produce `Da riallineare` or a closed inconsistency reason.

## Approval lock and transition order

```text
Company
-> Proposal
-> affected Exercises by ID
-> Expenses, Projects, Contracts by type then ID
-> source child rows/facts in stable order
-> Proposal Items by ID
-> Proposal Actions by sequence
-> referenced Supplier/Cost Center/Attachment rows by ID
-> apply new live rows and existing-source changes
-> calculate and validate the complete Budget payload and total in memory
-> Budget header
-> Audit events by deterministic event_sequence
-> Budget rows/evidence containing the created approval-event references
-> Proposal approved
```

State transitions:

```text
Proposal: draft --atomic approval--> approved
          draft --discard (S7)-----> discarded

Budget: absent --successful initial approval--> immutable v1
```

S6 does not expose discard or realignment-resolution controls even though the closed
state vocabulary remains canonical.
