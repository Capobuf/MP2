# Data Model: Closing

## New entity: `ClosingSnapshot`

Exactly one per Closed Exercise.

Suggested table: `closing_snapshots`

Fields:

- `id`
- `company_id`
- `company_name` — materialized Company denomination
- `exercise_id` — unique
- `exercise_year` — materialized calendar year
- `closed_at` — technical timestamp
- `closed_by_id`
- `initial_budget_id` nullable
- `current_budget_id` nullable
- `total_final_allocation` decimal(19,2)
- `total_closing_actual` decimal(19,2)
- `total_operational_variance` decimal(19,2)
- `total_consolidated_carryover` decimal(19,2)
- `accepted_warnings` JSON — immutable list of accepted warning code/message/source
  references
- `applied_settings` JSON containing at least:
  - Company timezone;
  - `overspend_note_required`;
  - `unclassified_closing_policy`
- `next_exercise_disposition`
  - `created`
  - `already_existed`
  - `not_created_management_terminated`
- `next_exercise_id` nullable
- `operation_id` UUID unique
- timestamps

Constraints:

- same-company composite FKs where repository convention supports them;
- one row per Exercise;
- no update;
- no delete.

`next_exercise_disposition` is materialization vocabulary for canonical §23.8's
"creazione o mancata creazione di N+1"; it does not introduce a new business state.

## New entity: `ClosingSourceRow`

Suggested table: `closing_source_rows`

Common fields:

- `id`
- `company_id`
- `closing_snapshot_id`
- `source_type` = `expense|project|contract`
- `origin_id`
- `origin_key`
- `copied_from_origin_key` nullable
- `label`
- `summary` nullable
- `supplier_id` nullable
- `supplier_label` nullable
- `cost_center_id` nullable
- `cost_center_label`
- `end_state` nullable
- `has_actuals` boolean
- `final_estimates` decimal(19,2)
- `received_carryover` decimal(19,2)
- `final_allocation` decimal(19,2)
- `closing_actual` decimal(19,2)
- `operational_variance` decimal(19,2)
- `detail_version`
- `detail` JSON
- timestamps

Unique:

- `closing_snapshot_id + origin_key`

No update/delete.

### Expense detail

Materialize:

- Expense ID/description;
- Exercise;
- origin Manual/System;
- owner type/origin;
- Supplier;
- direct/inherited Cost Center information as appropriate;
- active/reversed state;
- final Estimate total;
- Closing Actual total;
- **all** persisted Estimate/Actual lines existing at Closing:
  - ID;
  - type;
  - amount;
  - quantity/unit amount/unit;
  - note;
  - active/annulled state.

Annulled lines do not contribute to totals but remain in detail.

### Project detail

Materialize:

- Project ID/title/description;
- state at 31 December;
- transitions effective in the Exercise;
- annual classification;
- received Carryover;
- final Estimates;
- final Allocation;
- Closing Actual;
- operational variance;
- `residual` for Planned/Open;
- `saving` for Closed;
- `unused_allocation` for Cancelled;
- final deferral mode;
- Reprogrammed amount;
- consolidated outgoing Carryover;
- Closing state decision;
- decision reason(s);
- relevant child Expense materialization;
- effective informative relations supported by the current repository;
- explanatory audit event references.

The three balance fields are mutually semantic outputs; do not invent a generic
"remaining budget" measure.

### Contract detail

Materialize:

- Contract ID/title;
- Supplier;
- state at 31 December;
- annual classification;
- contractual start;
- next expiry known at Closing;
- automatic renewal/duration/notice facts;
- all conditions whose interval overlaps the Exercise or that produce an
  attribution date in the Exercise, including their annulled state; only valid
  conditions contribute to economic calculations;
- annual Estimate composition with cycle start and attribution date;
- lifecycle/renewal facts whose declared contractual, state-change or renewed-expiry
  date falls in the Exercise;
- final Estimate total;
- Closing Actual;
- operational variance;
- effective informative relations supported by the current repository;
- explanatory event references.

## Closing evidence materialization

S9 does not add a separate attachment-owner or Closing-evidence workflow.

The immutable Closing Snapshot itself materializes the evidence required by S9:

- submitted Project Closing decisions and their reasons;
- accepted warning codes/messages;
- applied Company settings;
- actor and technical Closing timestamp;
- explanatory audit event references;
- `N+1` disposition.

If a future canonical requirement explicitly associates file attachments with Closing,
that storage extension must preserve immutable/versioned evidence according to §23.12.
S9 must not create a staging upload workflow without that requirement.

## Existing entity changes

### `ProjectDeferral`

Forward migration only:

- preserve existing enum value `consolidated`;
- replace the S8 CHECK so mode `carryover` accepts either `provisional` or
  `consolidated`;
- preserve all S8 mutual-exclusion constraints.

No new deferral entity.

### `Exercise`

Add relationship:

- `closingSnapshot(): HasOne`

Enforce:

- a Closed Exercise cannot return to Open.

Do not add a second Closing status.

### `Company`

Add relationship:

- `closingSnapshots(): HasMany`

No new Company setting.

## No `ClosingDraft`

Closing review/input is transient UI/application state until successful atomic
confirmation.

## Snapshot identity and autonomy

Closing rows must remain readable if live:

- Project/Contract/Expense is later archived;
- labels change;
- Supplier/Cost Center is archived/renamed;
- S10 appends late Actual corrections.

Store materialized labels and values.

## `HaEffettivi`

For Closing inclusion/warnings:

```text
HaEffettivi =
exists active Actual line with amount != 0
```

Two active lines `+100` and `-100` mean:

```text
closing_actual = 0
has_actuals = true
```

## Closing totals

Header totals are sums of first-level sources only.

Child Project/Contract Expenses are detail, not additional first-level totals.

```text
total_operational_variance =
total_closing_actual - total_final_allocation
```

`total_consolidated_carryover` is the sum of outgoing consolidated Project Carryovers
from the closing Exercise.
