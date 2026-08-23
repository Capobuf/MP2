# Implementation Plan: Carryover and Reprogramming

**Branch**: `main` | **Date**: 2026-08-23 | **Spec**: `spec.md`

**Input**: S8 specification for canonical FR-059–FR-061 and invariants 28.11–28.16.

## Summary

Extend the existing S4 Project and S7 Proposal/Budget paths with one small persistent
current-state concept, `ProjectDeferral`, representing a Project passage from
Exercise `N` to immediate `N+1`.

Carryover remains a distinct allocation component. Reprogramming is applied as exact
source Estimate-line reduction plus newly created destination Project Estimates.
S7 whole-source readiness, impact planning, approval atomicity, immutable Budget
materialization, Proposal realignment, and AuditEvent infrastructure are extended in
place.

No second economic engine, generic ledger, matching subsystem, queue, cache, or new
frontend is introduced.

## Technical Context

**Language/Version**: PHP 8.3+ as required by current repository  
**Framework**: Laravel 13 / Filament 5 already present  
**Database**: MySQL 8.x family in development/testing  
**Testing**: Pest; Unit plus Feature/Livewire; browser verification only where useful  
**Frontend**: Existing Filament/Blade/Vite stack  
**Primary persistence**: existing Eloquent models plus one forward-only
`project_deferrals` table  
**Quality gate**: current `.github/workflows/quality.yml` and repository Composer/Vite
commands are executable source of truth

## Canonical Compliance Gate

Before implementation, confirm these statements against the current canonical file:

- only Project has Carryover;
- Project allocation includes received Carryover plus Estimates;
- maximum availability is capped by current allocation;
- one mode per consecutive Project passage;
- provisional Carryover is explicit and never auto-max;
- Reprogramming is balanced real source/destination Estimate mutation;
- existing Budgets never change;
- later Actual does not retroactively invalidate executed Reprogramming;
- reversal uses exact preserved IDs and blocks after independent involved-line change;
- terminal source-year Project forces `Nessuna`;
- Closing consolidation remains S9.

If the canonical file has materially changed from the SHA reviewed for this package,
stop and reconcile S8 before coding.

Reviewed canonical SHA at package creation:

`122e8af31e98789940672a5c0e8ddbb84f2441c6`

## Project Structure

### New files

```text
app/Domain/Projects/
├── ProjectDeferralMode.php
└── ProjectDeferralValues.php

app/Models/
└── ProjectDeferral.php

app/Actions/Proposals/
├── PlanProjectDeferral.php
└── ApplyProjectDeferral.php

app/Actions/Operations/
└── ChangeProjectDeferral.php

database/migrations/
├── 2026_08_23_000200_create_project_deferrals_table.php
└── 2026_08_23_000210_add_revision_to_expense_lines_table.php

database/factories/
└── ProjectDeferralFactory.php

tests/Unit/Domain/Projects/
└── ProjectDeferralValuesTest.php

tests/Feature/Projects/
├── ProjectDeferralPersistenceTest.php
├── ProjectCarryoverTotalsTest.php
├── ChangeProjectDeferralTest.php
└── ProjectDeferralUiTest.php

tests/Feature/Proposals/
├── PlanProjectDeferralTest.php
├── ApproveProjectCarryoverTest.php
├── ApproveProjectReprogrammingTest.php
├── ProjectDeferralReadinessTest.php
├── ProjectDeferralBudgetTest.php
└── S8InvariantTest.php
```

Exact test file grouping may be consolidated if doing so keeps each test file focused;
do not create additional production abstractions merely to mirror this list.

### Existing files expected to change

```text
app/Models/Project.php
app/Models/Exercise.php
app/Models/ExpenseLine.php
app/Models/Proposal.php
app/Models/ProposalItem.php

app/Domain/Company/AuditEventType.php
app/Domain/Proposals/ProposalActionType.php
app/Domain/Proposals/ProposalActionPayload.php
app/Domain/Proposals/ProposalSourceSnapshot.php
app/Domain/Proposals/ProjectPlan.php
app/Domain/Proposals/ProposalReadiness.php
app/Domain/Proposals/ProposalImpactPlan.php
app/Domain/Proposals/BudgetSnapshotPayload.php
app/Domain/Proposals/ProposalActionReplay.php

app/Actions/Proposals/ApproveProposal.php
app/Actions/Proposals/MaterializeBudgetSnapshot.php
app/Actions/Proposals/PlanExpense.php
app/Actions/Proposals/ApplyExpensePlan.php

app/Actions/Operations/UpdateExpenseLine.php
app/Actions/Operations/SetExpenseLineActive.php
app/Actions/Operations/CreateProjectTransition.php
app/Actions/Operations/ReplaceProjectTransition.php
app/Actions/Operations/AnnulProjectTransition.php

app/Filament/Resources/Projects/Pages/ViewProject.php
app/Filament/Resources/Projects/Schemas/ProjectInfolist.php
app/Filament/Resources/Proposals/Pages/ViewProposal.php
app/Filament/Resources/Proposals/Schemas/ProposalInfolist.php
app/Filament/Resources/Budgets/Schemas/BudgetInfolist.php
```

Change only files actually needed after inspecting current code. Do not touch every
listed file mechanically.

## Architecture

### 1. Current values

`ProjectDeferralValues` is a small pure/deterministic helper for:

```text
residual(allocation, actual)
maximumTransferable(allocation, actual)
```

Use existing `Decimal` operations. Do not add a money library.

`Project::annualTotals()` adds received Carryover from the destination-side
`ProjectDeferral`.

`Exercise::allocation()` adds received Project Carryover once.

### 2. Current deferral state

`ProjectDeferral` is the only live state row for a Project passage.

Use model relationships for source/destination Exercise and Project. Do not expose a
generic repository/service layer.

Add one integer `revision` to `ExpenseLine` and increment it exactly once on each real
line mutation in existing operational/Proposal paths. S8 stores and checks the
line-specific revision for involved Reprogramming lines. Do not use parent Expense
revision as the reversal freshness marker because unrelated sibling-line changes must
not block reversal.

### 3. Proposal planning

`PlanProjectDeferral` follows current S7 planning Action conventions:

- validate UUID;
- lock Company/Proposal/Project item and relevant current source rows;
- authorize Proposal update;
- check expected Proposal revision;
- validate immediate consecutive Exercises;
- validate source Project state at source-year 31 December, including applicable
  planned Project transitions in the Proposal result, validate transferable values,
  and validate that the resulting destination Project plan may receive the generated
  new Estimates under existing Project-state rules;
- capture the source-year Project fingerprint/revision plus exact aggregate and
  selected Estimate-line facts required by the decision;
- require the existing `ProposalAction.reason` for every explicit `Riporto` or
  `Riprogrammazione` choice and for any planned replacement/removal of an
  already-applied live mode;
- build one validated typed payload;
- update Project item result;
- append one Proposal Action and planning AuditEvent;
- increment Proposal revision;
- remain idempotent by operation UUID.

For Reprogramming, destination plan is generated from explicit source reductions.
No live Expense is created during planning.

`CreateProjectAllocation` is a new Proposal action type but reuses `PlanExpense`
creation/application mechanics.

### 4. Readiness and impact

Extend `ProjectPlan::validateForApproval()` and `ProposalReadiness` with the S8
predicates. Reuse existing reason enum values.

`ProposalImpactPlan` includes:

- source `N` delta for Reprogramming/reversal;
- destination `N+1` delta for Carryover, Reprogramming, reversal, and new allocation;
- unchanged Budget evidence;
- stale Drafts.

No new impact DTO hierarchy is required unless current arrays become unsafe to
maintain.

### 5. Approval

`ApproveProposal` extends its deterministic lock set with:

- relevant `ProjectDeferral` rows;
- source Estimate lines referenced by active Reprogramming actions.

After readiness revalidation, apply ordinary Proposal plans using existing paths, then
apply S8 deferral effects in the same transaction.

`ApplyProjectDeferral` is the shared S8 state-transition Action used by Proposal
approval and reused by the permitted direct live replacement/removal path:

- creates/updates the live `ProjectDeferral`;
- when leaving an active Reprogramming for `Nessuna` or `Riporto`, revalidates and
  exactly reverses the persisted active effect map before applying the requested
  state;
- for a newly applied Reprogramming, reduces/annuls exact source Estimate lines and
  creates destination Project Expenses/Estimate lines;
- records resolved effect IDs in the live row;
- increments affected Project/Exercise/Expense revisions proportionally;
- returns enough resolved state for snapshot/audit materialization.

Do not create nested independent transactions that can commit before Proposal
approval.

### 6. Direct live mode change

`ChangeProjectDeferral` has a preview and confirmation path using the same validation
and destination-plan generation rules for replacing/removing an already-applied
`Riporto` or `Riprogrammazione`. It supports only `Riporto -> Nessuna`,
`Riporto -> Riprogrammazione`, `Riprogrammazione -> Riporto`, and
`Riprogrammazione -> Nessuna`. It does not create a new transfer from `Nessuna` and
does not provide a same-mode live Carryover amount editor; those changes are applied
through Proposal/Revision.

Confirmation:

- locks and revalidates everything;
- for direct `Riporto -> Riprogrammazione`, requires the destination Project to
  already accept new planning under current state rules; the direct Action does not
  silently add a Project state transition, so a required reopen/open transition must
  be planned atomically through Proposal/Revision instead;
- reverses current active Reprogramming exactly when leaving that mode;
- when the target is `Riporto`, recomputes the canonical source maximum on the
  post-reversal hypothetical allocation with current Actuals before accepting the
  provisional Carryover;
- applies requested new mode;
- appends one `ProjectDeferralChanged` event;
- marks affected Draft Project items `to_realign`;
- does not touch Budgets;
- is idempotent by operation UUID.

Keep preview logic side-effect free.

### 7. Terminal-state guard

Extend the existing live Project-transition create/replace/annul paths with one shared,
small validation of the resulting 31-December state against any outgoing live
`ProjectDeferral` for the affected open source year.

If the resulting state is `Chiuso` or `Cancellato` and the live outgoing mode is not
`Nessuna`, reject the transition. Do not auto-change deferral state and do not trigger
Reprogramming reversal from a generic Project transition.

Proposal readiness performs the same semantic check against the resulting planned
timeline and can accept a terminal transition only when the same Proposal resolves the
deferral to `Nessuna`.

Use the smallest shared validation shape that avoids duplicating this predicate across
`CreateProjectTransition`, `ReplaceProjectTransition`, and `AnnulProjectTransition`;
do not introduce a lifecycle framework.

### 8. Snapshot

`BudgetSnapshotPayload` removes S7's explicit S8 placeholders:

```text
deferral_mode = none
approved_carryover = 0
approved_reprogrammed_amount = 0
```

and reads the actual resolved Project deferral for the Proposal Exercise.

Project top-level allocation becomes:

```text
approved_estimates + approved_carryover
```

The Reprogrammed amount is detail only and not re-added.

### 9. UI

Use the existing Proposal Project action group and Project detail page.

No full-screen workflow is needed. Use bounded Filament modals with exact previews
and validation.

The Project current-value surface exposes Carryover and maximum transferability.
Proposal and live modes share labels/calculation semantics.

## Transaction and Locking Rules

For approval-driven S8 effects, preserve S7's top-level transaction and stable lock
ordering.

For direct live mode change, use:

```text
Company
-> Project
-> source/destination Exercises ordered by ID
-> current ProjectDeferral row
-> affected source Expenses ordered by ID
-> affected source/destination Estimate lines ordered by ID
-> affected Draft Proposal Items/Proposals
-> apply
-> AuditEvent
```

When entering Reprogramming, destination rows do not exist before apply; create them
only after every existing dependency has been locked/revalidated.

When leaving Reprogramming, lock every ID from the active effect map and compare the
exact expected current line state before the first write.

## Revision Invalidation

Every successful applied deferral mutation increments:

- Project revision once for the logical operation;
- source Exercise revision once because the annual passage mode/decision belongs to
  that source year even when `Riporto` leaves source Estimates unchanged;
- destination Exercise revision once because received Carryover and/or destination
  Estimates/current passage state are affected;
- each changed source/destination Expense revision as needed for actual line writes.

Do not increment the same aggregate repeatedly merely because several child rows were
changed in one operation if current project conventions permit one logical increment.

Affected Draft Project sources are explicitly marked `to_realign` using the existing
S7 semantics.

## Security and Authorization

No new capability.

- Proposal plan: existing Proposal update policy -> `gestisce_proposte`.
- Approval: existing Proposal approval policy -> `approva_budget`.
- Direct Project deferral change: Project update/operational policy ->
  `modifica_operativita`.

Every lookup is company-scoped and revalidated inside the write transaction.

## Test Strategy

Focused tests first, full gate at the end.

### Unit

Authoritative formulas:

- normal residual;
- over-Actual residual zero;
- negative Actual capped by allocation;
- zero allocation + negative Actual -> zero maximum.

### Feature

Cover:

- schema/unique/company/consecutive rules;
- Carryover proposal plan and approval;
- Reprogramming plan/application/balance/identity/lineage;
- direct mode transitions;
- exact reversal and independent-line-change block;
- Proposal stale/review behavior;
- Budget materialization;
- idempotency/rollback;
- tenant/authorization;
- invariant map.

### UI

Use existing Livewire/Filament test style for visibility, labels, validation and
modal behavior. Run authenticated browser verification for the integrated journey
after automated tests.

## Migration Strategy

Forward-only migration. Do not edit any existing used migration.

Existing Projects have no deferral rows; absence resolves to effective `none/0`.
No data backfill is required because S8 functionality did not previously exist and
Budget payload explicitly stored zero placeholders.

## Complexity Budget

Allowed new production concepts:

1. `ProjectDeferral` current-state model/table.
2. `ProjectDeferralMode` enum.
3. one small deterministic value helper.
4. one Proposal planning Action.
5. one Proposal apply Action.
6. one direct operational Action.
7. two Proposal action enum values (`plan_project_deferral`,
   `create_project_allocation`).
8. one AuditEvent type.

Any additional service/table/abstraction requires a concrete implementation problem
that cannot be solved cleanly with the existing paths.

## Constitution Check

- **Canonical Domain Authority — PASS**: S8 is limited to canonical
  FR-059–FR-061, invariants 28.11–28.16, and directly required cross-cutting rules;
  no unresolved product behavior is filled by assumption.
- **Simplicity and Proportionality — PASS**: one current-state table and narrow Actions
  extend existing Project/S7 paths; no generic architecture is introduced.
- **Vertical Slice Traceability — PASS**: S8 is independently demonstrable and does
  not implement S9 Closing.
- **Dependency Integrity — PASS**: no dependency source modification or new package.
- **Explicit Domain Operations — PASS**: atomic/idempotent behavior remains in
  deterministic Actions, not anonymous UI callbacks.
- **Proportional Test Discipline — PASS**: formulas, workflows, MUST NOTs, rollback,
  idempotency and regressions are directly task-mapped.
- **Reproducible Development — PASS**: the existing S0 environment and current CI
  gate are reused.
- **Historical/Transactional Integrity — PASS**: Budget history remains immutable and
  every S8 economic mutation is atomic.
- **Agent Operational Discipline — PASS**: phases remain bounded; unrelated refactors
  and future-slice implementation are prohibited.

Concrete simplicity constraints:

- No new package.
- No new frontend.
- No queue/cache.
- No event sourcing.
- No generic command bus.
- No matching algorithm.
- No synthetic Carryover Expense.
- No duplicate Proposal/approval engine.
- No premature Closing code.
- Existing Budget immutability preserved.
- Existing S7 readiness/impact/locking reused.

## Complexity Tracking

No constitution exception is required by this plan.

| Potential complexity | Why it is still required | Why the chosen form is proportional |
|---|---|---|
| `ProjectDeferral` current-state row | Canonical mode/Carryover/Reprogramming are live annual facts and cannot depend on Timeline replay | One row per Project passage replaces separate mode/Carryover/Reprogramming stores |
| `ExpenseLine.revision` | Canonical reversal must detect a later independent modification of the exact involved line even if its values are restored | One integer on the existing row; avoids a new history table and avoids false blocking from parent Expense revisions |
| Active Reprogramming effect map | Canonical reversal must restore/annul exact preserved IDs and block overwriting later independent line edits | Stored only for the currently active Reprogramming; history remains in existing AuditEvent/Budget evidence |
| Multi-Exercise locking and idempotency | Canonical §§10, 16.10 and existing S7 approval semantics require atomicity and retry safety | Extends existing S7 transactions/receipts instead of introducing a second workflow engine |

