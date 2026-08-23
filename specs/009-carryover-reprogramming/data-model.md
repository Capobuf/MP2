# Data Model: Carryover and Reprogramming

## New entity: ProjectDeferral

`ProjectDeferral` represents the current live year-passage state of one Project from
Exercise `N` to the immediately consecutive Exercise `N+1`.

It is current state, not an append-only history. `AuditEvent` remains the history.

### Persistence

Recommended forward-only migration:

`database/migrations/2026_08_23_000200_create_project_deferrals_table.php`

| Field | Shape | Rule |
|---|---|---|
| `id` | bigint PK | Stable internal identity |
| `company_id` | FK Company | Same company as Project and both Exercises |
| `project_id` | FK Project | The only source type allowed to carry/reprogram |
| `source_exercise_id` | FK Exercise | Exercise `N` |
| `destination_exercise_id` | FK Exercise | Exercise whose year is exactly `N + 1` |
| `mode` | string/enum | `none`, `carryover`, or `reprogramming` |
| `carryover_amount` | decimal(19,2) | `0.00` unless mode `carryover` |
| `carryover_state` | nullable string/enum | S8 writes `provisional`; later S9 may write `consolidated` |
| `reprogrammed_amount` | decimal(19,2) | `0.00` unless mode `reprogramming` |
| `reprogramming_operation_id` | nullable UUID | Identity of the economic operation that created the active Reprogramming; null otherwise |
| `reprogramming_effects` | nullable JSON | Exact active effect map needed for reversal; null otherwise |
| timestamps | timestamps | Technical current-row timestamps |

Unique constraint:

```text
UNIQUE(project_id, source_exercise_id, destination_exercise_id)
```

Company-consistent foreign keys should follow the repository's existing composite
reference pattern where available. The year-consecutive rule is validated under
application locks because it depends on referenced Exercise rows.

No delete workflow is required. A row that previously carried/reprogrammed may remain
with `mode=none` and zero values after an explicit reversal. A never-configured pair
may have no row; its effective mode is `none` with zero amounts.

### Closed value invariants

```text
mode = none
=> carryover_amount = 0
AND carryover_state = null
AND reprogrammed_amount = 0
AND reprogramming_operation_id = null
AND reprogramming_effects = null

mode = carryover
=> carryover_amount > 0
AND carryover_state = provisional     # S8 writes only provisional
AND reprogrammed_amount = 0
AND reprogramming_operation_id = null
AND reprogramming_effects = null

mode = reprogramming
=> carryover_amount = 0
AND carryover_state = null
AND reprogrammed_amount > 0
AND reprogramming_operation_id IS NOT NULL
AND reprogramming_effects IS NOT NULL
```

Do not add a second boolean such as `has_carryover`; mode is authoritative.

`reprogramming_operation_id` identifies the economic Reprogramming itself:

- Proposal/Revision path: the applied `PlanProjectDeferral` ProposalAction
  `operation_id`;
- direct `Riporto -> Riprogrammazione`: the `ChangeProjectDeferral` operation UUID.

It is not the generic Proposal approval operation UUID.

## Reprogramming effect map

The active effect map exists only to reverse the currently active Reprogramming
precisely. It is not a generic event schema.

Canonical shape:

```json
{
  "source_lines": [
    {
      "expense_id": 10,
      "expense_line_id": 101,
      "expense_reversed_after": false,
      "line_revision_after": 5,
      "amount_before": "6000.00",
      "amount_after": "2000.00",
      "quantity": null,
      "unit_amount": null,
      "unit_of_measure": null,
      "note": "Fase 1",
      "annulled_before": false,
      "annulled_after": false
    },
    {
      "expense_id": 11,
      "expense_line_id": 111,
      "expense_reversed_after": false,
      "line_revision_after": 3,
      "amount_before": "2000.00",
      "amount_after": "2000.00",
      "quantity": null,
      "unit_amount": null,
      "unit_of_measure": null,
      "note": null,
      "annulled_before": false,
      "annulled_after": true
    }
  ],
  "destination_expenses": [
    {
      "expense_id": 30,
      "exercise_id": 2,
      "project_id": 7,
      "reversed": false,
      "copied_from_origin_key": "expense:10",
      "estimate_lines": [
        {
          "expense_line_id": 301,
          "line_revision_after": 0,
          "amount": "4000.00",
          "quantity": null,
          "unit_amount": null,
          "unit_of_measure": null,
          "note": "Fase 1",
          "annulled": false
        }
      ]
    },
    {
      "expense_id": 31,
      "exercise_id": 2,
      "project_id": 7,
      "reversed": false,
      "copied_from_origin_key": "expense:11",
      "estimate_lines": [
        {
          "expense_line_id": 311,
          "line_revision_after": 0,
          "amount": "2000.00",
          "quantity": null,
          "unit_amount": null,
          "unit_of_measure": null,
          "note": null,
          "annulled": false
        }
      ]
    }
  ]
}
```

The implementation may include stable Proposal-local identifiers in the action
payload before approval, but the persisted live effect map must contain resolved
database IDs after successful application.

### Reversal comparison

Before reversing an active Reprogramming:

- every source Estimate line in `source_lines` must still have the complete expected
  post-operation mutable row state: `amount_after`, quantity/unit metadata, Note, and
  `annulled_after`, and its line `revision` must still equal the revision recorded
  after Reprogramming application;
- every destination Estimate line created by the Reprogramming must still exist, its
  line `revision` must still equal the revision recorded after creation, and it must
  have its complete expected post-operation mutable row state: amount, quantity/unit
  metadata, Note, and active/annulled state;
- each source/destination Expense must still belong to the recorded Company, Project
  and expected Exercise; source Expenses must retain their expected reversed/active
  state, and a Reprogramming-created destination Expense must also retain its recorded
  `CopiedFromOriginKey` and expected reversed/active state.

If an involved row differs or an involved Estimate line revision differs, reversal
is blocked. The revision prevents a modify-then-restore-to-the-same-values sequence
from being mistaken for an untouched row. Unrelated lines and Actuals do not
participate in this equality check.

On successful reversal:

- restore each source line to `amount_before` and `annulled_before`;
- annul each exact destination Estimate line created by the Reprogramming;
- do not delete lines or Expenses;
- do not touch unrelated lines or Actuals;
- clear active Reprogramming amount/operation/effects from `ProjectDeferral`;
- if the requested new mode is `carryover`, recompute source allocation after the
  hypothetical restoration, combine it with current source Actuals, derive the
  canonical maximum from that restored state, and validate the requested provisional
  Carryover against that value;
- then persist the requested new `none` or valid `carryover` state atomically with the
  reversal.

## ExpenseLine revision

S8 adds one technical `revision` unsigned integer to `expense_lines`, default `0` for
existing and newly created rows.

It is not a new domain state. It exists only to support the canonical rule that an
involved Reprogramming line modified by a later independent operation must block
reversal even if the visible values are later restored to their previous values.

Every successful path that can mutate a Project Estimate line eligible for S8 MUST
increment that line's revision exactly once in the same transaction. In the current
repository this includes at least `UpdateExpenseLine`, `SetExpenseLineActive`,
Proposal-applied Project Estimate changes in `ApplyExpensePlan`, and S8's own
source/destination Estimate mutations. Newly created rows start at revision `0`; a
no-op does not increment.

The column exists on all `expense_lines` because the table is shared, but S8 does not
require unrelated Contract/system or Actual-only workflows to be refactored merely to
use the counter. If implementation inspection finds another existing path capable of
mutating an eligible Project Estimate line, that path must increment the counter too.

Reprogramming stores each involved line revision immediately after its own successful
write/create and compares that exact integer under lock before reversal. This avoids
using parent Expense revision, which would incorrectly block on unrelated sibling
line changes.

## Project deferral mode enum

Add a small closed enum such as:

```text
ProjectDeferralMode
- None = none
- Carryover = carryover
- Reprogramming = reprogramming
```

A separate Carryover-state enum is optional if it improves consistency with
`BudgetSourceRow.carryover_state`; do not create a hierarchy or strategy classes.

## Project model relationships and annual totals

`Project` gains a relationship to its deferral rows.

For Exercise `E`:

```text
RiportoRicevuto(E) =
  ProjectDeferral.carryover_amount
  where destination_exercise_id = E
    and mode = carryover
```

Then:

```text
StimeProgetto(E) =
  sum active Estimate lines of active Project Expenses in E

EffettivoProgetto(E) =
  sum active Actual lines of active Project Expenses in E

AllocatoCorrenteProgetto(E) =
  RiportoRicevuto(E) + StimeProgetto(E)

ResiduoProgetto(E) =
  max(AllocatoCorrenteProgetto(E) - EffettivoProgetto(E), 0)

DisponibilitaMassimaRiportabile(E) =
  min(ResiduoProgetto(E), AllocatoCorrenteProgetto(E))
```

`Project::annualTotals()` remains the current aggregate entry point and is extended
rather than replaced.

## Exercise totals

`Exercise::allocation()` becomes:

```text
sum active Estimate lines in the Exercise
+ sum received Project Carryover in the Exercise
```

This is safe because current Estimate-line summation already includes standalone,
Project and Contract Estimates exactly once. Carryover is the only additive S8
component.

Actual calculation is unchanged.

## Proposal Project baseline/result extension

The whole-source Project snapshot for Proposal Exercise `E` adds an explicit incoming
deferral section.

A Proposal action that chooses or changes the passage from source Exercise `N` into
`E = N+1` also captures a source-year dependency snapshot. This dependency is
revalidated independently from the destination-year Project snapshot because source
Estimates/Actuals determine both the limit and the Reprogramming effect.

Minimum source dependency shape:

```json
{
  "source_context": {
    "source_exercise_id": 1,
    "project_revision": 12,
    "project_fingerprint": "sha256-of-project-source-year-snapshot",
    "allocation": "10000.00",
    "actual": "4000.00",
    "maximum_transferable": "6000.00",
    "referenced_estimates": [
      {
        "expense_id": 10,
        "expense_revision": 7,
        "expense_line_id": 101,
        "line_revision": 4,
        "amount": "6000.00",
        "annulled": false
      }
    ]
  }
}
```

The fingerprint MUST reuse the canonical Project snapshot logic scoped to source
Exercise `N`; do not create a parallel snapshot engine. For Carryover,
`referenced_estimates` may be empty because the decision depends on aggregate source
facts. For Reprogramming it contains every explicitly selected source Estimate line.
Readiness and approval revalidate both aggregate source context and selected rows.

Example baseline/result shape:

```json
{
  "incoming_deferral": {
    "source_exercise_id": 1,
    "destination_exercise_id": 2,
    "mode": "carryover",
    "carryover_amount": "4000.00",
    "carryover_state": "provisional",
    "reprogrammed_amount": "0.00",
    "reprogramming_operation_id": null
  }
}
```

If no relevant prior consecutive Exercise/persisted deferral exists:

```json
{
  "incoming_deferral": {
    "source_exercise_id": null,
    "destination_exercise_id": 2,
    "mode": "none",
    "carryover_amount": "0.00",
    "carryover_state": null,
    "reprogrammed_amount": "0.00",
    "reprogramming_operation_id": null
  }
}
```

Do not copy `reprogramming_effects` wholesale into every Proposal baseline unless it
is needed to preview/reverse the current mode. When an existing active
Reprogramming must be reversed by a planned mode change, the Proposal action captures
the exact current effect map/fingerprint under validation so approval can revalidate
it.

The Project source fingerprint must change when incoming deferral state changes.

## Proposal action: PlanProjectDeferral

Add one closed action type:

```text
plan_project_deferral
```

Common payload:

```json
{
  "source_exercise_id": 1,
  "destination_exercise_id": 2,
  "mode": "carryover",
  "carryover_amount": "4000.00",
  "reprogrammed_amount": "0.00",
  "source_estimate_reductions": [],
  "destination_plans": []
}
```

The ProposalAction's existing `reason` field is mandatory when mode is
`reprogramming` and whenever the planned action replaces/removes an already-applied
live mode. It is not duplicated inside the JSON payload.

### `none`

```text
carryover_amount = 0
reprogrammed_amount = 0
source_estimate_reductions = []
destination_plans = []
```

If changing away from a live Reprogramming, the payload/result also references the
captured active Reprogramming operation/effect fingerprint required for approval-time
revalidation; the live effect data itself remains authoritative in
`ProjectDeferral`.

### `carryover`

```text
carryover_amount > 0
reprogrammed_amount = 0
source_estimate_reductions = []
destination_plans = []
ProposalAction.reason = non-blank rinvio Note
```

### `reprogramming`

Each selected reduction contains:

```json
{
  "source_expense_id": 10,
  "source_expense_origin_key": "expense:10",
  "source_expense_revision": 7,
  "source_line_id": 101,
  "source_line_revision": 4,
  "source_amount": "6000.00",
  "source_annulled": false,
  "reduction_amount": "4000.00"
}
```

Rules:

```text
0 < reduction_amount <= source_amount
sum(reduction_amount) = reprogrammed_amount
reprogrammed_amount <= DisponibilitàPreOperazione
```

`DisponibilitàPreOperazione` is computed from total source Project allocation, so it
may include Carryover received from `N-1`. That Carryover is not a source Estimate
line and is never reduced by S8 Reprogramming. The selectable/reducible source
Estimate lines therefore form an additional structural feasibility bound without
changing the canonical availability formula.

Destination plans are deterministically built from the selected reductions grouped
by source Expense:

```json
{
  "proposal_destination_id": "uuid",
  "copied_from_origin_key": "expense:10",
  "description": "existing source description",
  "notes": "existing source notes or null",
  "supplier_id": 5,
  "estimate_lines": [
    {
      "proposal_line_id": "uuid",
      "source_line_id": 101,
      "amount": "4000.00",
      "note": "source Estimate note or null"
    }
  ]
}
```

`sum(destination_plans.estimate_lines.amount) = reprogrammed_amount`.

For `supplier_id`, the destination Expense follows the existing Project-Expense rule:
supplier is optional, but an Archived supplier is not selectable for new activity.
The UI MAY prefill the source supplier only while it is selectable. If the source
supplier is Archived, it MUST NOT be silently carried forward, silently replaced, or
silently nulled as an implementation rule; the Proposal must expose the ordinary
optional destination-supplier choice (`Nessun Fornitore` or an active supplier) and
persist the user's explicit result. Source economic lineage remains
`CopiedFromOriginKey`.

The destination amount plan is generated, not matched; ordinary non-economic
destination Expense fields continue to obey their existing validation rules.

## Proposal action: CreateProjectAllocation

Add one action type:

```text
create_project_allocation
```

It reuses the existing new Expense Proposal item structure but requires:

- an existing live Project in the same Company;
- destination Exercise = Proposal main Exercise;
- valid Project state for plan;
- one or more non-negative Estimate lines with positive total;
- non-blank Note identifying it as independent new allocation, stored in the existing
  `ProposalAction.reason` field rather than in the destination Expense `notes`.

It must not carry `CopiedFromOriginKey` from the source year and must not be referenced
by an active Reprogramming effect map.

The generic `CreateExpense` Proposal path MUST reject a new Expense owned by an
already-live Project in the Proposal main Exercise unless the typed action is
`CreateProjectAllocation`. This is a backend rule, not only a Filament label.

New child plan for a Project created inside the same Proposal remains on the existing
new-Project path because there is no prior live Project passage.

## Application ordering inside Proposal approval

Extend the existing S7 lock/apply order minimally:

```text
Company
-> Proposal
-> affected Exercises by ID
-> main Exercise Budget headers
-> ProjectDeferral rows for affected Project/year pairs
-> existing source models
-> explicit source Estimate lines used by Reprogramming
-> Proposal Items and active Actions
-> referenced master data/evidence
-> re-enumerate and rebuild readiness/impact
-> apply ordinary Project/Contract/Expense plan
-> apply S8 deferral effects and resolve destination IDs
-> apply relations
-> mark competing Drafts to_realign
-> materialize Budget and S8 audit evidence
-> mark Proposal approved
```

If existing code ordering can satisfy the same locks without a larger refactor, keep
the existing ordering and insert only the missing deterministic locks.

## Direct live mode-change input

The operational Action uses:

```text
actor
project
source Exercise
destination Exercise
new mode
new provisional Carryover, when new mode is carryover
new Reprogramming source reductions, when entering reprogramming
reason
operation UUID
expected Project revision / preview facts
```

It does not accept free-form destination Expense IDs.

The same deterministic destination plan generation used by Proposal planning is
reused.

## State transitions

Supported direct live transitions before source Closing:

```text
carryover -> none
carryover -> reprogramming
reprogramming -> none
reprogramming -> carryover
```

The direct live Action does not introduce a transfer from `none`. A new Carryover or
Reprogramming from `none` is planned and applied through Proposal/Revision; a
Closing-time decision belongs to S9.

An executed `reprogramming -> reprogramming` in-place amount rewrite is not
introduced. To change the executed amount, the existing Reprogramming must first be
reversed by leaving that mode, then a new explicit Reprogramming can be planned and
applied through Proposal/Revision.

A same-mode provisional Carryover amount change is also performed through a Proposal
or Revision, not through the direct live mode-change Action. This keeps the direct
Action limited to replacing/removing an already-applied canonical mode.

For Proposal planning, an unexecuted Draft action may be replaced/withdrawn using
the existing S7 Proposal action-history semantics.

After source Exercise Closing, S8 exposes the persisted mode/carryover as read-only;
S9 owns consolidation and any terminal transition.

## Terminal Project compatibility

For every open source Exercise `N` with an outgoing `ProjectDeferral` to `N+1`:

```text
StateProjectAt(31 December N) in {closed, cancelled}
=> effective deferral mode = none
```

Proposal readiness evaluates this against the resulting Project timeline after planned
transitions and planned deferral actions.

Ordinary live Project-transition create/replace/annul operations must evaluate the
resulting timeline before persisting the transition. If it would make the Project
terminal at 31 December while the outgoing live mode is `carryover` or
`reprogramming`, reject the transition. Do not mutate `ProjectDeferral` as a hidden
side effect.

The valid paths are:

- explicitly change the live deferral to `none` first while both Exercises are Open;
  or
- include the terminal transition and deferral `none` in the same Proposal so approval
  applies them atomically.

## Budget Project row extension

For a Project in Proposal Exercise `E`:

```text
approved_estimates = active Project Estimate total in E
approved_carryover = received Carryover in E
approved_allocation = approved_estimates + approved_carryover
```

Project `detail` contains at least:

```text
deferral_mode
approved_carryover
carryover_state
approved_reprogrammed_amount
reprogramming_effects/lineage when applicable
approved_estimate_total
expenses
approved actions/reasons
```

`approved_reprogrammed_amount` is descriptive lineage. It is not added to allocation
again because its destination Estimate lines already contribute to
`approved_estimates`.

## Audit

Add one Project event type for current deferral changes. Audit payload contains:

```text
previous mode/carryover/reprogrammed amount
new mode/carryover/reprogrammed amount
source and destination Exercise IDs/years
source allocation before/after
destination allocation before/after
exact source/destination Reprogramming IDs when applicable
reason
operation UUID
Proposal/Budget reference when approval-driven
```

Audit remains append-only. ProjectDeferral remains current state.
