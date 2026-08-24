# Data Model: Correzioni post-Chiusura

## New entity: `LateCorrection`

One immutable record per successful late-correction operation and exactly one newly
appended Actual ExpenseLine.

Suggested table: `late_corrections`

| Field | Shape | Rules |
|---|---|---|
| `id` | stable identity | Never reused |
| `company_id` | Company reference | Exact tenant of every related row |
| `exercise_id` | Exercise reference | Required and Closed |
| `closing_snapshot_id` | ClosingSnapshot reference | Required, same company/Exercise |
| `expense_id` | Expense reference | Required, same company/Exercise |
| `expense_line_id` | ExpenseLine reference | Required, unique, type Actual |
| `original_expense_line_id` | nullable ExpenseLine reference | Same company/Exercise when known; never modified |
| `recorded_by_id` | User reference | Required actor |
| `operation_id` | UUID | Unique retry receipt |
| `reason` | text | Required, non-blank |
| `belongs_to_closed_exercise` | boolean | Required true declaration |
| `source_type` | `expense`, `project`, `contract` | Historical first-level source |
| `source_origin_id` | integer | Historical source identity |
| `source_origin_key` | string | Materialized stable source key |
| `source_label` | string | Materialized label at correction time |
| `owner_context` | versioned JSON | Exact autonomous/project/contract owner IDs and labels |
| `supplier_context` | nullable versioned JSON | Historical Supplier ID/label, including Archived |
| timestamps | created only | No update/delete |

Constraints:

- composite same-company and same-Exercise references where current schema supports
  them;
- `expense_line_id` unique;
- `operation_id` unique;
- the referenced line is newly created by the operation, active and `actual`;
- no correction row can reference an Estimate or system Estimate Expense;
- update/delete rejected at model/policy level; database references use restrict.

The economic amount remains authoritative on `ExpenseLine.amount`. `LateCorrection`
does not duplicate it as mutable state.

## Existing `Expense` and `ExpenseLine`

No ordinary mutation rule is weakened.

`RecordLateCorrection` may:

1. append an Actual line to an explicitly selected compatible manual Expense; or
2. create one manual Expense in the same historical owner context and append its first
   Actual line.

A newly created Expense uses existing required structure:

- exact company and Closed Exercise;
- `origin = manual`;
- explicit description;
- Project/Contract owner matching the selected historical first-level source, or no
  owner for an autonomous late Expense;
- Contract Supplier inherited from the Contract;
- optional direct historical Supplier for autonomous/Project Expense, including an
  Archived Supplier;
- no Estimate line;
- no reversal.

Existing Expense/ExpenseLine update, annul, restore, move, reclassify and delete paths
continue to reject Closed Exercises and late-correction rows.

## Historical Expense compatibility

A selected historical Expense is compatible only when all predicates hold:

```text
origin = manual
exercise_id = corrected closed Exercise
first_level_source = explicitly selected historical source
reversed_at = null
expense accepts Actuals
supplier attribution = preserved historical attribution
```

A Contract system Estimate Expense is never compatible. Failure does not mutate the
selected Expense; the Action creates a new manual Expense in the explicit same owner
context.

## New entity: `HistoricalErrorAnnotation`

Immutable, non-economic evidence of one discovered historical error.

Suggested table: `historical_error_annotations`

| Field | Shape | Rules |
|---|---|---|
| `id` | stable identity | Never reused |
| `company_id` | Company reference | Exact tenant |
| `exercise_id` | Exercise reference | Required and Closed |
| `closing_snapshot_id` | ClosingSnapshot reference | Required, same company/Exercise |
| `recorded_by_id` | User reference | Required actor |
| `operation_id` | UUID | Unique retry receipt |
| `kind` | closed enum | One canonical error kind below |
| `reason` | text | Required, non-blank |
| `recorded_facts_version` | integer | Starts at 1 |
| `recorded_facts` | JSON | Materialized data actually recorded |
| `believed_correct_facts_version` | integer | Starts at 1 |
| `believed_correct_facts` | JSON | Materialized data believed correct |
| `affected_sources_version` | integer | Starts at 1 |
| `affected_sources` | JSON list | Stable type/ID/OriginKey/label references |
| timestamps | created only | No update/delete |

Closed `kind` values:

```text
cost_center
supplier
project
contract
container
exercise
historical_state
carryover
accidental_closing
```

Facts are evidence only. No field is interpreted as an instruction to update an
Expense, Project, Contract, classification, Exercise, Carryover, Budget or Closing
Snapshot.

## Attachment extension

Add nullable `historical_error_annotation_id` to `attachments` and replace the used
exactly-one-owner CHECK in a forward migration so an Attachment belongs to exactly
one of:

```text
proposal
contract
expense
expense_line
historical_error_annotation
```

Correction evidence uses existing `expense_line_id` ownership on the generated line.
Annotation evidence uses `historical_error_annotation_id`. Existing owner shapes and
rows retain their meaning. Physical file/row deletion is prohibited, and evidence on
a generated LateCorrection line or immutable Annotation cannot be detached; existing
ordinary live-owner detachment behavior remains unchanged.

## Relationships

```text
Company
├── LateCorrections
└── HistoricalErrorAnnotations

Exercise (Closed)
├── ClosingSnapshot (immutable)
├── LateCorrections
└── HistoricalErrorAnnotations

LateCorrection
├── Expense
├── ExpenseLine (new Actual, unique)
├── original ExpenseLine (optional)
└── actor

HistoricalErrorAnnotation
├── ClosingSnapshot
├── actor
└── Attachments
```

## Current-knowledge calculation boundary

The S10 domain calculation is:

```text
LateCorrectionNet = sum(active generated Actual line amounts)
CurrentKnowledgeActual = ClosingSnapshot.total_closing_actual + LateCorrectionNet
```

Annotation rows contribute zero. S10 may use this calculation in focused tests and
local context but does not add the complete comparative/report/export surfaces owned
by S11. Because late-correction lines cannot be annulled, `active` is an invariant,
not an editable state.

## Audit events

Add typed events:

```text
late_correction_recorded
historical_error_annotation_recorded
```

The correction event stores closing/current before and after Actual values, the new
line, historical source, original line when known, reason and declaration. The
annotation event stores kind, recorded/correct facts and affected immutable
references with zero economic impact.

AuditEvent remains append-only evidence, not the source used to calculate current
state.

## Lock order

```text
Company
-> Exercise
-> ClosingSnapshot
-> selected Project/Contract/source rows by type then ID
-> selected historical Expense and original ExpenseLine
-> Supplier/Cost Center references
-> operation receipt lookup
-> create new Expense when needed
-> create new Actual ExpenseLine
-> create immutable LateCorrection or HistoricalErrorAnnotation
-> append AuditEvent
```

After locks, recheck tenant, Closed state, Closing Snapshot identity, capability,
source membership/revision and selected Expense compatibility. Any failure rolls back
every database write. A retry with the same successful operation UUID returns the
existing immutable result.