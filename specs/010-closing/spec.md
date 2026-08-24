# Feature Specification: Closing

**Feature Branch**: `spec/s9-closing`
**Roadmap ID**: S9
**Status**: Implemented — pending review
**Canonical authority**: `docs/domain/Specifica_Canonica_Semplificata_v4.md`
**Canonical primary requirements**: FR-034–FR-041
**Canonical primary invariants**: 28.25–28.28, 28.49, 28.58

## Scope

S9 implements the first complete Exercise Closing.

Closing is not a status toggle. It is one atomic operation that:

- verifies the Exercise can be closed;
- obtains the explicit final Project decisions required by the canonical domain;
- processes Contract renewals/expiry effects due through 31 December;
- recalculates the final live values;
- creates `N+1` only when required;
- applies any not-yet-executed final Reprogramming;
- consolidates Carryover;
- materializes one immutable Closing Snapshot;
- marks the Exercise Closed;
- records explanatory audit/Timeline events.

S9 does not reopen an Exercise and does not implement post-Closing corrections.

---

## User Story 1 — Understand whether an Exercise is closable

As a user with the Closing capability, I can inspect a deterministic Closing review
before committing anything, so I know the blocks, warnings, totals, Project decisions
and `N+1` consequence.

### Acceptance scenarios

1. An Exercise cannot be closed before the end of its calendar year in the Company
   timezone.
2. A prior Open Exercise blocks Closing.
3. A Draft Proposal for the same Exercise blocks Closing.
4. A Draft Proposal for `N+1` does not block Closing; if Closing changes a Project
   passage used by that Draft, the affected Project source becomes `Da riallineare`.
5. Absence of an approved Budget does not block Closing and is shown explicitly.
6. Warnings are visible but do not themselves block Closing.
7. The review does not mutate live economic data.

---

## User Story 2 — Confirm final Project decisions

As a Closing user, I explicitly confirm the 31-December state and the annual deferral
decision for each Project that is Planned or Open at 31 December.

### Acceptance scenarios

1. Every Project Planned or Open at 31 December requires an explicit state decision.
2. A Planned Project can remain Planned or become Cancelled.
3. An Open Project can remain Open, become Closed, or become Cancelled.
4. State and deferral mode are separate decisions.
5. A Project that remains Planned/Open and continues into `N+1` must explicitly choose
   `Nessuna`, `Riporto`, or `Riprogrammazione`.
6. If no amount can or should transfer, the explicit mode is `Nessuna`.
7. A Project Closed/Cancelled at 31 December has mode `Nessuna` and zero consolidated
   Carryover.
8. Closing/Cancel Project transitions use economic effective date 31 December and must
   remain compatible with future approved transitions.
9. Notes remain mandatory exactly where the canonical domain already requires them:
   Project Closing/Cancel, deferral, or a change of an existing deferral mode.

---

## User Story 3 — Consolidate Carryover correctly

As a Closing user, I choose the final Carryover within the final transferable maximum
instead of having the system infer or maximize it automatically.

### Acceptance scenarios

1. `RiportoConsolidato` is strictly positive and not above the final transferable
   maximum when mode is `Riporto`.
2. A zero transfer is represented by mode `Nessuna`.
3. The system never auto-sets final Carryover to the maximum.
4. An existing provisional Carryover may be confirmed at the same amount or replaced
   with another valid final amount.
5. The live `N+1` Project allocation is updated from provisional to consolidated.
6. An existing `N+1` Budget is not rewritten.
7. If an `N+1` Draft Proposal exists, the affected Project source becomes
   `Da riallineare`.
8. After Closing N, the consolidated Carryover cannot be changed by ordinary S8
   operations.

---

## User Story 4 — Finish Reprogramming exactly once

As a Closing user, I can finalize a Reprogramming decision that has not yet been
executed, while an already executed Reprogramming is only revalidated and never
duplicated.

### Acceptance scenarios

1. A not-yet-executed Reprogramming uses explicit source Estimate reductions and exact
   balanced destination Estimates according to S8.
2. Source allocation reduction equals destination Estimate increase equals the
   Reprogrammed amount.
3. No Actual is copied, moved, matched or consumed.
4. An already executed Reprogramming is not applied again.
5. Its persisted source/destination IDs and expected post-operation states are
   revalidated.
6. If an involved line/Expense was independently modified and no canonical
   realignment has restored integrity, Closing blocks.
7. S9 does not invent a fuzzy, amount-based or description-based repair.
8. A final Closing choice that changes an existing live mode uses the exact S8
   reversal rules while both Exercises are still Open inside the Closing transaction.

---

## User Story 5 — Finalize Contracts at 31 December

As a Closing user, I get Contract states and Estimates that correspond to 31 December,
even if I technically execute Closing months later.

### Acceptance scenarios

1. Due renewals, non-renewal expiries and related lifecycle facts are materialized
   idempotently only through 31 December of the Exercise.
2. A technical Closing date in a later year must not cause later renewals to be
   materialized as part of the earlier Closing.
3. Final Contract Estimates for the closing Exercise are recalculated after due
   lifecycle materialization.
4. Invalid Contract conditions block Closing.
5. The Snapshot stores the Contract state at 31 December, conditions/composition
   relevant to the Exercise, lifecycle events effective in the Exercise and next
   expiry known at Closing.
6. `Rinnovo senza condizione economica` remains a warning, not an inferred missing
   invoice/payment.

---

## User Story 6 — Create or not create N+1

As a Closing user, when `N+1` does not yet exist I explicitly state whether management
continues.

### Acceptance scenarios

1. If `N+1` already exists, Closing never deletes or recreates it.
2. If `N+1` is absent and management continues, Closing creates it Open in the same
   transaction.
3. The created Exercise follows canonical §11.8:
   - no Budget;
   - no autonomous Expense copy;
   - no Actual copy;
   - annual Project/Contract classifications initialized from the latest known value;
   - Contract Estimates calculated from current canonical conditions/state/renewals;
   - no automatic Project Estimates;
   - Carryover received only through the final Closing decision.
4. If `N+1` is absent and management is terminated, it is not created.
5. Termination requires zero Carryover and no `N+1` Proposal/Budget.
6. A transfer mode that requires `N+1` cannot coexist with `Gestione terminata`.
7. The Snapshot and Timeline state whether `N+1` was created, already existed, or was
   intentionally not created.

---

## User Story 7 — Freeze one autonomous Closing Snapshot

As a reviewer, I can inspect exactly what was known and decided at Closing even after
live data changes in later slices.

### Acceptance scenarios

1. Exactly one Closing Snapshot exists after an Exercise is Closed.
2. It can exist without a Budget.
3. It is immutable and autonomous from live objects.
4. Header values include final allocation, Actual at Closing, operational variance,
   consolidated Carryover, accepted warnings, Budget references when present,
   applied Company settings and `N+1` disposition.
5. Included sources follow canonical §7.6.5.
6. Every included first-level row stores final plan/Actual values and state at
   31 December.
7. Expense detail stores all Estimate/Actual lines existing at Closing, including
   annulled state; only active lines contribute to totals.
8. Project detail stores final state, transitions, deferral mode, Reprogrammed amount,
   consolidated Carryover, Closing decision/reason and the defined closing balance.
9. Contract detail stores final state, next expiry, renewal configuration facts,
   conditions, cycles/attribution composition and lifecycle events.
10. A net Actual of zero does not erase `HaEffettivi=true` when non-zero active Actual
    lines offset one another.

---

## User Story 8 — Enforce historical immutability after Closing

As a user, once an Exercise is Closed I cannot use ordinary operations to rewrite the
materialized historical plan/state.

### Acceptance scenarios

1. `Chiuso -> Aperto` is impossible.
2. Ordinary changes cannot mutate historical:
   - Estimates;
   - container/year;
   - annual classification;
   - Contract conditions/scadenze/lifecycle affecting the Closed year;
   - Project state at that 31 December;
   - consolidated Carryover;
   - approved Budgets;
   - Closing Snapshot.
3. New Budget/Revision approval for the Closed Exercise is impossible.
4. S10 later adds the only canonical post-Closing monetary correction path.
5. S9 must not create late-correction or historical-annotation behavior early.

---

# Functional Requirements

- **S9-FR-001**: Closing MUST be rejected until the Company-local date is later than
  31 December of the target Exercise.
- **S9-FR-002**: Closing MUST be rejected while any earlier Exercise of the same
  Company is Open.
- **S9-FR-003**: A Draft Proposal whose main Exercise is the target Exercise MUST
  block Closing.
- **S9-FR-004**: A Draft Proposal outside the target Exercise, including `N+1`, MUST
  NOT block Closing merely because it is Draft. For every Project or Contract source
  whose live facts are changed by Closing in any affected Open Exercise, existing S7
  whole-source realignment MUST mark the corresponding Draft source `Da riallineare`.
- **S9-FR-005**: Approved Budget existence MUST NOT be a Closing prerequisite.
  Missing Budget references MUST be explicitly materialized as absent.
- **S9-FR-006**: Closing MUST require the Company-scoped `chiude_esercizio`
  capability through the Exercise authorization boundary and MUST preserve tenant
  isolation. Ordinary `modifica_operativita` MUST NOT imply Closing permission, and
  a user who has `chiude_esercizio` MUST NOT be forced to also possess
  `modifica_operativita` merely because Closing internally applies canonical Project,
  Contract or `N+1` effects.
- **S9-FR-007**: The pre-Closing review MUST be side-effect-free and MUST expose
  current/finalizable totals, canonical blocks, canonical warnings, required Project
  decisions, the `N+1` disposition, and the per-Exercise impact for every Open
  Exercise whose values or states would change according to §10. The displayed
  finalizable values MUST reflect the submitted Closing decisions and deterministic
  31-December Contract/deferral projection, not merely the pre-Closing live totals.
- **S9-FR-008**: Every Project Planned or Open at 31 December MUST receive an explicit
  Closing state decision according to §14.5.
- **S9-FR-009**: A Planned Project decision is limited to `Pianificato` or
  `Cancellato`; an Open Project decision is limited to `Aperto`, `Chiuso` or
  `Cancellato`.
- **S9-FR-010**: A state-changing Project decision MUST create the corresponding
  canonical Project transition effective on 31 December, with required reason, and
  MUST be rejected if it makes the full timeline incompatible with already approved
  future transitions.
- **S9-FR-011**: State and deferral mode MUST remain distinct. A Project terminal at
  31 December MUST have mode `Nessuna`, final live Reprogrammed amount zero, and
  consolidated Carryover zero.
- **S9-FR-012**: A continuing Planned/Open Project MUST explicitly choose one of
  `Nessuna`, `Riporto`, `Riprogrammazione`; no transfer MAY be inferred from Residual.
- **S9-FR-013**: Final Carryover MUST satisfy
  `0 < RiportoConsolidato <= DisponibilitàMassimaRiportabileAllaChiusura`.
  `Riporto` MUST NOT encode zero and the system MUST NOT auto-maximize it.
- **S9-FR-014**: Closing MUST replace a live provisional Carryover with the explicit
  consolidated value in `N+1`, set its state to `Consolidato`, and MUST NOT rewrite
  any approved `N+1` Budget.
- **S9-FR-015**: A final Reprogramming not already executed MUST reuse S8's explicit,
  balanced source-reduction/destination-creation semantics atomically.
- **S9-FR-016**: An already executed Reprogramming MUST be revalidated from persisted
  operation/effect identities and MUST NOT be applied a second time. If the final
  Closing mode remains `Riprogrammazione`, the final amount/effects are the exact
  already-executed operation; S9 MUST NOT silently rewrite it as a different
  same-mode Reprogramming.
- **S9-FR-017**: If an already executed Reprogramming's recorded source/destination
  effects no longer match because of independent modification, Closing MUST block.
  S9 MUST NOT invent a matching or repair algorithm.
- **S9-FR-018**: If the final mode changes an existing live S8 mode, Closing MUST use
  the same exact S8 reversal semantics before applying the final decision, while both
  Exercises are still Open inside the transaction. When the final Project state is
  terminal, the final deferral must be resolved to `Nessuna` first and the terminal
  31-December transition applied afterward; final Closing values use the resulting
  restored plan.
- **S9-FR-019**: If `N+1` is absent, Closing MUST require an explicit choice between
  management continuation and termination.
- **S9-FR-020**: With continued management and absent `N+1`, Closing MUST create
  `N+1` Open atomically and initialize it according to canonical §11.8.
- **S9-FR-021**: Canonical Exercise initialization MUST include inherited annual
  Project/Contract classifications and Contract Estimates calculated with applicable
  state, renewal configurations, conditions and attribution dates; it MUST NOT copy
  autonomous Expenses, Actuals or Project Estimates and MUST NOT create a Budget.
- **S9-FR-022**: With terminated management and absent `N+1`, Closing MUST NOT create
  it and MUST reject non-zero Carryover or a transfer decision requiring `N+1`.
- **S9-FR-023**: If `N+1` already exists, Closing MUST use it as-is and MUST NOT
  delete/recreate it or silently interpret Closing as offboarding.
- **S9-FR-024**: Contract renewals, expiry cessations and related lifecycle facts due
  by 31 December MUST be materialized idempotently through that cutoff, not through
  the technical Closing date. Missing deterministic automatic-renewal facts whose
  due dates fall in earlier already-Closed Exercises MAY still be appended as required
  by §27.39, but those Closed Exercises and their Snapshots MUST NOT be recalculated
  or rewritten.
- **S9-FR-025**: Contract final Estimates for the closing Exercise MUST be recalculated
  after due lifecycle materialization and before the final Snapshot. Any resulting
  recalculation of other affected Open Exercises changes only their live Current
  Situation; existing approved Budgets in those Exercises MUST remain unchanged.
- **S9-FR-026**: Invalid Contract conditions MUST block Closing.
- **S9-FR-027**: Missing first-level classification MUST block or warn exactly
  according to the Company `Policy Non classificato alla Chiusura` materialized for
  the operation.
- **S9-FR-028**: Missing required Project overspend Notes MUST block Closing when the
  applicable Company setting requires those Notes.
- **S9-FR-029**: S9 MUST produce the non-blocking warnings currently representable
  from canonical §14.4: first-level source with Allocated > 0 and `HaEffettivi=false`,
  unclassified source under Warning policy, Planned Project never Opened, the
  provisional Carryover in the current approved `N+1` Budget differing from the final consolidable
  maximum, applicable Contract without a valid economic condition, and renewal
  without a valid economic condition after expiry. S10 later supplies the historical-annotation warning source.
- **S9-FR-030**: Warnings MUST NOT infer invoices, instalments or missing causes.
  When warnings exist, final confirmation MUST require explicit acknowledgement and
  the accepted warnings MUST be materialized.
- **S9-FR-031**: Final confirmation MUST show Exercise, totals, Project state
  decisions, consolidated Carryovers, accepted warnings, `N+1` disposition, and the
  declaration that the Exercise cannot be reopened.
- **S9-FR-032**: Closing MUST first enumerate and show every Open Exercise affected by
  the operation, then run in one logical transaction that locks/revalidates those
  Exercises and all sources whose live state can change. It MUST leave no partial
  economic, Snapshot or status effects on failure; only the canonical non-economic
  attempt/failure audit may remain.
- **S9-FR-033**: Successful retry with the same Closing operation identity MUST return
  the same Closing Snapshot without duplicating renewals, Project transitions,
  Reprogramming effects, Carryover consolidation, `N+1` or audit effects.
- **S9-FR-034**: Exactly one immutable Closing Snapshot MUST exist for a Closed
  Exercise, and it MUST be autonomous from subsequent live-object changes.
- **S9-FR-035**: Closing Snapshot header MUST materialize the fields required by
  canonical §23.8, including materialized Company denomination and Exercise year,
  Budget v1/current references when available, final totals, accepted warnings,
  Company settings applied, and `N+1` disposition.
- **S9-FR-036**: Closing Snapshot source inclusion MUST implement canonical §7.6.5 and
  MUST preserve first-level no-double-counting semantics.
- **S9-FR-037**: Each Closing source row/detail MUST materialize the common and
  source-specific facts required by §§23.8–23.11, including `HaEffettivi` semantics
  independent of net Actual total.
- **S9-FR-038**: Project and Contract state in the Snapshot MUST be evaluated at
  31 December of the Exercise, never at the technical Closing timestamp.
- **S9-FR-039**: Closing MUST record explanatory Timeline/audit for start,
  confirmation, completion and failure; it MUST also record the canonical functional
  effects created by Closing, including Carryover consolidation and intentional
  non-creation of `N+1` when management terminates.
- **S9-FR-040**: After Closing, ordinary operations MUST NOT alter the historical
  fields listed in canonical §14.8, no new Budget version MAY be approved for the
  Exercise, and `Chiuso -> Aperto` MUST be impossible.
- **S9-FR-041**: S9 MUST NOT implement late corrections, historical-error annotations,
  full comparison/reporting/export, Forecast, automatic Project state inference,
  automatic Carryover maximization, or a generic Closing workflow engine.

---

# Canonical Closing Blocks

The implementation must represent, without silently weakening, all §14.3 blocks that
are reachable in S9:

- previous Open Exercise;
- Draft Proposal for the same Exercise;
- missing required Project continuation/state decision;
- missing/invalid mode or Carryover;
- Carryover over final maximum;
- not-yet-executed Reprogramming above pre-operation availability;
- unbalanced Reprogramming;
- independently modified effects of an executed Reprogramming;
- non-zero Carryover for terminal Project;
- conflicting Carryover/Reprogramming;
- future transition incompatibility;
- invalid Contract conditions;
- missing classification when Company policy is Blocking;
- required overspend Note missing;
- technical recalculation/consistency failure.

The end-of-year time rule and `N+1` termination preconditions are additional canonical
preconditions from §§11.7 and 14.2, not invented warning categories.

# Out of scope

- S10 closed-year Actual corrections;
- Historical Error Annotation creation;
- S11 reports/exports and complete comparison categories;
- reminders/notifications;
- electronic approval/signature workflow;
- closing draft/version workflow;
- reopening;
- month-level accounting;
- Forecast.
