# Implementation Plan: Closing

**Branch**: `spec/s9-closing`
**Feature**: `specs/010-closing`
**Canonical authority**: `docs/domain/Specifica_Canonica_Semplificata_v4.md`

## Summary

Implement Closing as one bounded application operation around existing MP2 domain
mechanisms.

Add dedicated immutable Closing Snapshot persistence and a deterministic preflight
review. Reuse S8 Project deferral mechanics, S5 Contract lifecycle/allocation logic,
S7 realignment and existing audit conventions.

Do not introduce a generic workflow engine or a second economic engine.

## Technical context

- PHP 8.3+
- Laravel / Eloquent
- Filament
- MySQL
- Pest
- existing CI quality workflow

## Expected new production files

Names may be narrowed after code inspection.

```text
app/Models/
├── ClosingSnapshot.php
└── ClosingSourceRow.php

app/Policies/
└── ClosingSnapshotPolicy.php

app/Domain/Closing/
├── ClosingReview.php                # deterministic review/result vocabulary
├── ClosingSnapshotPayload.php       # immutable materialization
└── ProjectClosingValues.php         # only if small balance helper is useful

app/Actions/Closing/
├── ReviewExerciseClosing.php
└── CloseExercise.php

app/Filament/Resources/Closings/
└── ... read-only Snapshot resource

app/Filament/Resources/Exercises/Pages/
└── CloseExercise.php

database/migrations/
├── ...create_closing_snapshot_tables.php
└── ...allow_consolidated_project_carryover.php
```

Do not create every suggested helper if the current code supports a simpler placement.

## Existing paths expected to be integrated

```text
app/Models/Company.php
app/Models/Exercise.php
app/Models/ProjectDeferral.php
app/Policies/ExercisePolicy.php

app/Domain/Company/AuditEventType.php
app/Domain/Contracts/ContractStateTimeline.php
app/Domain/Contracts/ContractRenewalSchedule.php
app/Domain/Projects/ProjectStateTimeline.php

app/Actions/Operations/CreateExercise.php
app/Actions/Operations/ProcessContractRenewals.php
app/Actions/Operations/RecalculateContractEstimates.php
app/Actions/Proposals/ApplyProjectDeferral.php
app/Actions/Proposals/MarkProposalItemsToRealign.php

app/Filament/Resources/Exercises/Pages/ViewExercise.php
```

Also inspect ordinary Project/Contract temporal mutation Actions affected by §14.8.
Modify only those that can actually rewrite a Closed-year historical fact.

## Constitution Check

- **Canonical Domain Authority**: PASS — every observable rule is anchored to the
  canonical Closing/Exercise/Project/Contract/Snapshot sections. Known under-specified
  repair behavior for independently modified executed Reprogramming is not invented;
  Closing blocks.
- **Simplicity and Proportionality**: PASS — one Closing Action, one deterministic
  review, dedicated immutable Snapshot persistence; no Closing Draft/workflow engine.
- **Vertical Traceability**: PASS — FR-034–FR-041 and invariants
  28.25–28.28/28.49/28.58 are explicitly mapped.
- **Dependency Integrity**: PASS — no vendor/plugin source changes or new dependency
  required.
- **Explicit Domain Operations**: PASS — non-trivial mutation lives in Actions/domain
  code, not Filament callbacks.
- **Test Discipline**: PASS — focused deterministic/workflow/rejection/rollback/retry
  coverage, full gate only at the end.
- **Historical/Transactional Integrity**: PASS — immutable Closing Snapshot,
  no-reopen, exact S8 reuse and one logical transaction are mandatory.
- **Scope**: PASS — no S10/S11 behavior is implemented early.

No Constitution exception is required.

## Architecture

### 1. Review

`ReviewExerciseClosing` is side-effect-free.

Input:

- target Exercise;
- transient Project Closing decisions;
- `N+1` continuation choice when needed.

Review must simulate the submitted final Project/deferral decisions and Contract
cutoff effects without persisting them, so confirmed numbers are the numbers that the
transaction will materialize if no fact changes.

Output contains:

- current review fingerprint;
- header totals;
- per-Exercise impact for every affected Open Exercise;
- blocks;
- warnings;
- Project decision rows;
- Budget references;
- N+1 status/disposition;
- applied Company settings preview.

The fingerprint must include every mutable fact on which confirmation depends.

Do not persist a Closing Draft.

### 2. Contract cutoff planning

Refactor the current renewal path just enough to plan/apply renewals through an
explicit cutoff date. Its internal apply method must be callable by Closing after
Closing authorization without re-checking the ordinary Contract-update capability.

Ordinary `ProcessContractRenewals` keeps current semantics by using Company-local
today.

Closing passes `N-12-31`.

Preview and apply must derive from the same renewal rules.

After due lifecycle effects, recalculate closing-year Contract Estimates.

### 3. N+1 creation

Reuse the canonical Exercise creation mechanics inside the Closing transaction.

Refactor `CreateExercise` only as needed so both direct and Closing-created Exercises
share the same internal initialization. The ordinary public Action keeps
`modifica_operativita`; the Closing-owned internal path runs under the already checked
`chiude_esercizio` capability and must not re-authorize ManageOperations.

Both paths share:

- unique Company/year;
- Open status;
- inherited annual classifications;
- Contract Estimate initialization.

No Budget or copied autonomous/project economic rows.

### 4. Project decisions

Closing owns these mutations under `chiude_esercizio`. Reuse
`ProjectStateTimeline`/transition persistence semantics, but do not call an ordinary
Project Action in a way that adds a `modifica_operativita` requirement.


For each required Project decision:

1. evaluate current state at 31 December;
2. validate requested final state from §14.5;
3. if state changes, validate candidate transition at 31 December against the full
   timeline including future transitions;
4. validate final mode;
5. terminal result => `Nessuna`;
6. validate final transfer amount.

State transitions and deferral effects are only applied in the final transaction.

### 5. Reprogramming

Reuse S8.

For a new Closing-time Reprogramming, invoke the same deterministic S8 apply behavior
after final revalidation.

For an already executed Reprogramming, extract/reuse only the minimum exact-integrity
check from S8; do not invoke the apply branch.

If final mode leaves an active Reprogramming, reuse exact S8 reversal.

No matching/reconstruction.

### 6. Carryover consolidation

When final mode is Carryover:

- validate explicit final amount against final maximum;
- update/create the destination `ProjectDeferral`;
- persist `carryover_state = consolidated`;
- mark affected N+1 Draft Project sources to realign;
- leave every Budget immutable.

When final mode is None/Reprogramming, consolidated Carryover is zero.

### 7. Snapshot materialization

After all final live effects have been applied but before the Exercise is marked
Closed, build one immutable Snapshot from the locked final state.

`ClosingSnapshotPayload` owns only materialization/inclusion logic, not mutation.

Preallocate deterministic audit event sequences for the Closing operation, following
`MaterializeBudgetSnapshot`'s existing pattern. Snapshot detail may retain
`operation_id + event_sequence` references without requiring any later Snapshot
update.

Use first-level source totals; child Expenses are detail.

### 8. Close transaction

`CloseExercise` final flow:

1. persist/ensure a non-economic `Closing started` audit attempt marker;
2. start one DB transaction;
3. lock Company and target Exercise;
4. reject already Closed except successful same-operation retry;
5. enumerate, lock and revalidate prior/target/next and every other Open Exercise
   whose values or states can change, plus target Draft Proposal state, relevant
   Budgets and all mutable source sets;
6. verify Company-local date;
7. process Contract due events through 31 December;
8. recalculate final closing-year Contract Estimates;
9. recompute authoritative Closing review from locked state;
10. validate review fingerprint, decisions and warning acknowledgement;
11. create N+1 if requested and absent;
12. apply final S8 deferral changes/reversals first where required, then apply
    terminal Project transitions; continuing-state transitions may follow the same
    validated final plan;
13. mark every affected Draft Project/Contract source in all impacted Open Exercises
    to realign;
14. allocate deterministic Closing audit-event sequences and materialize the
    immutable Closing Snapshot/rows with those references;
15. write confirmation and functional audit events in the same transaction;
16. set Exercise status Closed;
17. write the Closing-completed event;
18. commit.

On failure, the economic transaction rolls back and a non-sensitive Closing failure
event is recorded outside the failed transaction.

Do not commit nested independent transactions.

### 9. Post-Closing guards

Protect canonical §14.8 using the smallest effective guards.

Existing operations that already require an Open Exercise need no duplicate guard.

Add protection only to date-based Project/Contract paths that could otherwise alter a
materialized Closed-year fact or change the materialized state at its 31 December.
Do not block the canonical append-only materialization of previously missed automatic
renewal facts from §27.39; that path must continue to recalculate only Open Exercises.

No S10 exception path is implemented in S9.

## Locking

Lock deterministically, normally by ascending IDs:

- Company;
- target/prior/next Exercise rows involved;
- target Draft Proposal row if present;
- Project rows + transitions + classifications + relevant deferrals;
- source/destination Expense and ExpenseLine rows touched by Reprogramming;
- Contract rows + renewal configurations + lifecycle facts + conditions +
  classifications + system Estimate Expenses/lines;
- Budget references needed for Snapshot header;

Do not lock unrelated tenants.

## Failure injection

Tests should be able to force failures at meaningful existing/new seams:

- after due Contract renewal materialization;
- after N+1 creation;
- after Project transition/deferral application;
- after Closing Snapshot header/rows;
- before Exercise status update.

Every case must roll back economic/Snapshot/status writes.

## Complexity tracking

Justified complexity:

1. dedicated Closing Snapshot tables — required because Budget and Closing schemas
   have different canonical semantics;
2. explicit renewal cutoff — required because Closing reference date differs from
   technical execution date;
3. post-Closing temporal guards — required by §14.8.

Not justified:

- Closing Draft entity;
- workflow/state machine beyond Exercise Open/Closed;
- generic orchestration framework;
- event sourcing;
- a second deferral engine;
- reconciliation/matching engine.
