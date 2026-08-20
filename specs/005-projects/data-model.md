# Data Model: Projects

## Project

| Field | Shape | Rules |
|---|---|---|
| `id` | stable integer identity | Generated once; never reused |
| `company_id` | Company reference | Required; restrict deletion |
| `title` | string | Required/trimmed; technical max 255; not identity |
| `description` | nullable text | Descriptive |
| `notes` | nullable text | Explanatory; not structured workflow state |
| `initial_state` | `planned`, `open`, `closed`, `cancelled` | Required canonical state |
| `initial_effective_date` | date | Company-local economic date |
| `archived_at` | nullable technical timestamp | Orthogonal visibility property |
| `revision` | unsigned monotone integer | Project preview/source token |
| timestamps | technical UTC | Never substitute for effective dates |

Constraints: unique `(id, company_id)`, indexes for company/archive/title, closed
state vocabulary, restrictive Company FK, and no ordinary physical deletion. A
Project belongs to Company and has transitions, annual classifications, and child
Expenses.

Derived identity:

```text
OriginKey = "project:" + Project.id
```

## Project Transition

| Field | Shape | Rules |
|---|---|---|
| `id` | stable integer identity | Never reused or physically deleted |
| `company_id` | Company reference | Required; equal to Project Company |
| `project_id` | Project reference | Required composite same-company FK |
| `from_state` | canonical Project state | Must match state immediately before date |
| `to_state` | canonical Project state | Pair must be canonically allowed |
| `effective_date` | date | First company-local day in destination state |
| `reason` | nullable text | Required for closure, cancellation, reopening |
| `created_by_id` | User reference | Author of the transition fact |
| `annulled_at` | nullable technical timestamp | Only future transitions may be annulled |
| `annulled_by_id` | nullable User reference | Required with annulment |
| `annulment_reason` | nullable text | Required with annulment |
| timestamps | technical UTC | Creation/update metadata only |

The migration adds generated nullable `active_effective_date`, equal to
`effective_date` only while not annulled, and unique `(project_id,
active_effective_date)`. This is MySQL's partial-unique equivalent.

Allowed pairs:

```text
planned   -> open | cancelled
open      -> closed | cancelled
closed    -> open
cancelled -> planned | open
```

Display status is derived:

```text
annulled_at != null                         -> Annulled
effective_date > company-local current date -> Planned
otherwise                                   -> Effective
```

Replacement atomically annuls one future transition and inserts one new transition;
the Timeline payload links their IDs. There is no edit or physical delete.

## Project Exercise Classification

| Field | Shape | Rules |
|---|---|---|
| `id` | stable integer identity | Generated once; no ordinary deletion |
| `company_id` | Company reference | Required |
| `project_id` | Project reference | Required, same Company |
| `exercise_id` | Exercise reference | Required, same Company |
| `cost_center_id` | nullable Cost Center reference | Null = Unclassified; same Company |
| timestamps | technical UTC | Change history is in Timeline |

Constraints: unique `(project_id, exercise_id)` and composite same-company foreign
keys to Project, Exercise, and Cost Center. The Project and Exercise revisions guard
classification previews; no redundant row revision is added.

A manual reclassification can choose only an active Cost Center or Unclassified.
An already referenced archived Cost Center remains readable. New Exercise
initialization copies the most recent annual reference as a historical automatic
continuation, even when archived, and creates `null` only when no known value exists.

## Expense ownership extension

Add nullable `project_id` to the existing Expense.

```text
project_id is null     -> autonomous Expense; direct Cost Center may be null/value
project_id is not null -> Project Expense; direct_cost_center_id must be null
```

Use composite `(project_id, company_id)` FK to Project, index
`(company_id, project_id, exercise_id, reversed_at)`, and a check constraint that
rejects simultaneous Project and direct Cost Center ownership. No owner enum,
polymorphic reference, Contract column, copied classification, or new Expense type
is added. Supplier remains optional on each Expense.

## Exact derived values

For one Project and Exercise:

```text
Allocation = exact sum(active Estimate Lines in active Project Expenses)
Actual = exact signed sum(active Actual Lines in active Project Expenses)
Variance = Actual - Allocation
HasActuals = exists active non-zero Actual Line in an active Project Expense
```

Received carryover is unavailable in S4 and contributes no row or input. A reversed
Expense and an annulled Line contribute zero while retaining identity and history.

Exercise totals use the disjoint first-level set:

```text
autonomous Expenses + Projects (whose values derive from their child Expenses)
```

The optimized database sum may traverse all eligible Lines once, but UI and grouped
queries must never add Project children again as autonomous sources.

## State at date and annual reference

```text
if reference_date < initial_effective_date:
    Absent at date
else:
    state = initial_state
    apply every non-annulled transition with effective_date <= reference_date
    in ascending effective_date order
```

Any transition mutation validates the entire resulting active sequence. Global view
uses Company-local today. Annual view uses 31 December for a past Exercise,
Company-local today for the current Exercise, and 1 January plus separately listed
future transitions for a future Exercise.

## Overspend decision

For each affected Project/Exercise pair:

```text
before <= 0 and after > 0            -> created
before > 0 and after > before        -> increased
otherwise                            -> none
```

When `overspend_note_required` is true, `created` or `increased` requires a nonblank
note before any mutation. Exact before/after values and classification are retained
in the causal Timeline event.

## Revisions and impact plans

An annual classification preview records Project/Exercise revisions, old/new Cost
Center, affected Expense IDs, and exact allocation/actual movement. A whole-Expense
move preview extends the S3 plan with old/new owner, old/new inherited/direct Cost
Center, old/new Project revisions and annual Project impacts. Input changes
invalidate the fingerprint.

Confirmation re-authorizes, locks, validates all revisions and company ownership,
recomputes state/date/totals/overspend, compares the fingerprint, then persists the
whole mutation and one complete event.

Global lock order:

```text
Company
-> affected Exercises ascending ID
-> affected Projects ascending ID
-> transitions/classifications by stable ID/key
-> affected Expenses ascending ID
-> Lines ascending ID
-> Supplier/Cost Center references ascending ID
```

## Mutations and revision effects

- Project description, transition, classification, archive, or restore increments
  Project revision.
- Child Expense/Line create, edit, annul, restore, reverse, or restore increments
  Expense and owning Project revision as applicable.
- Ownership move increments Expense and each old/new Project revision.
- Every economic or classification change increments each affected Exercise
  revision; descriptive-only Project edit does not.
- No-op and idempotent retry create no revision or Timeline event.

## Timeline envelope reuse

S4 extends `AuditEventType` and reuses the existing unique operation ID envelope.
Each command creates one append-only event with Project reference, ordered affected
Exercises, company-local effective date, before/after state, exact allocation/actual
impacts, reason, source references, and overspend outcome when present. A Project
Timeline filters by the event's immutable subject/reference payload, not by the
Expense's current owner.
