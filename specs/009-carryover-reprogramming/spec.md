# Feature Specification: Carryover and Reprogramming

**Feature Branch**: `main`

**Created**: 2026-08-23

**Status**: Verified

**Roadmap ID**: S8

**Canonical primary requirements**: FR-059, FR-060, FR-061

**Canonical primary invariants**: 28.11, 28.12, 28.13, 28.14, 28.15, 28.16

**Canonical anchors**: §§7.5–7.6, 8.4, 8.6, 10, 12.4–12.14, 15.7, 16.10–16.11,
17, 22.2, 22.4, 22.7, 22.9, 23.4, 27.19–27.22, 28.11–28.16.

**Input**: Implement the first complete Project year-passage workflow after S7:
calculate the current transferable limit, choose exactly one mode between `Nessuna`,
`Riporto`, and `Riprogrammazione`, apply provisional carryover or reprogramming
without double counting, preserve immutable Budgets, allow a reasoned mode change
while both Exercises remain Open, and keep Proposal readiness, Current Situation,
Timeline, and Budget snapshots coherent.

## User Scenarios & Testing *(mandatory)*

### User Story 1 - Understand the transferable Project amount (Priority: P1)

As a user working on a Project across two consecutive Exercises, I can see its current
allocation, Actual, residual, and maximum transferable availability for the source
Exercise, so I know the upper bound before choosing how to defer plan into the next
Exercise.

**Why this priority**: Every Carryover and Reprogramming decision depends on the same
canonical formulas. If this value is wrong, every later operation is wrong.

**Independent Test**: Create Projects with positive Actual, zero Actual, and negative
Actual, and verify the exact canonical formulas at Project and Exercise level without
creating any deferral.

**Acceptance Scenarios**:

1. **Given** a Project with `Allocato Corrente = 10,000` and `Effettivo = 6,000`,
   **When** values are calculated, **Then** `Residuo = 4,000` and
   `Disponibilità Massima Riportabile = 4,000`.
2. **Given** a Project with `Allocato Corrente = 10,000` and `Effettivo = -1,000`,
   **When** values are calculated, **Then** `Residuo = 11,000` but
   `Disponibilità Massima Riportabile = 10,000`.
3. **Given** a Project with `Allocato Corrente = 0` and `Effettivo = -1,000`,
   **When** values are calculated, **Then** `Residuo = 1,000` but
   `Disponibilità Massima Riportabile = 0`.
4. **Given** a Project receiving a valid Carryover in an Exercise, **When** its current
   allocation and the Exercise total are calculated, **Then** the Carryover is added
   exactly once and child Expenses are not double-counted.

---

### User Story 2 - Choose the Project deferral mode in a Proposal (Priority: P1)

As a proposal manager preparing Exercise `N+1`, I can choose one explicit deferral
mode for a continuing Project from the immediately preceding Exercise `N`, so the
Proposal cannot mix Carryover and Reprogramming or silently infer a transfer.

**Why this priority**: FR-059 and invariant 28.15 require one mutually exclusive mode
before any economic effect can be applied.

**Independent Test**: Initialize a Proposal for `N+1`, select each mode in turn for a
Project from `N`, and verify validation, impact preview, readiness, terminal-state
rules, authorization, tenant isolation, and stale-source behavior.

**Acceptance Scenarios**:

1. **Given** a continuing Project from Open `N` into Open `N+1` with no existing live
   deferral for that passage, **When** no deferral has been selected, **Then** its
   effective mode is `Nessuna`, Carryover and Reprogrammed amount are both zero, and
   no transfer is inferred.
2. **Given** an existing applied live deferral for that same passage, **When** a new
   Proposal/Revision is initialized, **Then** the current mode and value are preserved
   as baseline rather than reset or auto-recalculated, and they remain subject to
   current validation before approval.
3. **Given** that Project, **When** `Riporto` is selected, **Then** a strictly positive
   provisional amount not above the current maximum is required and
   Reprogramming is zero.
4. **Given** that Project, **When** `Riprogrammazione` is selected, **Then** a strictly
   positive amount not above the current pre-operation availability and an explicit
   set of source Estimate reductions are required, and Carryover is zero.
5. **Given** a Project whose state on 31 December of `N` is `Chiuso` or `Cancellato`,
   **When** a non-`Nessuna` mode is requested, **Then** the action is rejected.
6. **Given** non-consecutive Exercises, a Closed Exercise, another company, or a user
   without `gestisce_proposte`, **When** a Proposal deferral action is attempted,
   **Then** it is rejected without partial Proposal changes.
7. **Given** a Project whose live source facts changed after the Proposal baseline,
   **When** the mode action or approval is attempted, **Then** S7 whole-source
   realignment rules apply and no stale decision is silently merged.

---

### User Story 3 - Apply provisional Carryover without moving source Estimates (Priority: P1)

As a proposal manager, I can approve a provisional Carryover into `N+1`, so the
destination Project receives additional current allocation while the source plan,
source Budget, and source Estimate identities remain unchanged.

**Why this priority**: Carryover is a distinct allocation component, not a copy of
source Expenses and not a Forecast.

**Independent Test**: Approve a Proposal for `N+1` with a partial Carryover and verify
source Estimates unchanged, destination allocation increased exactly once, Budget
materialization, later source-change warning, and no automatic correction.

**Acceptance Scenarios**:

1. **Given** `Disponibilità Massima Riportabile = 6,000`, **When** a Proposal approves
   `Riporto provvisorio = 4,000`, **Then** source Estimates remain unchanged,
   destination `Allocato Corrente Progetto` increases by exactly `4,000`, and the
   destination Budget records `4,000` as `Provvisorio`.
2. **Given** the same maximum, **When** the user requests `6,001`, zero, or a negative
   Carryover, **Then** approval is blocked and nothing is applied.
3. **Given** a live provisional Carryover of `4,000`, **When** later source facts
   reduce the current maximum to `3,000`, **Then** the live `4,000` and every approved
   Budget remain unchanged, Current Situation shows
   `Riporto provvisorio superiore al massimo corrente`, and a later Revision cannot
   confirm the invalid value.
4. **Given** mode `Riporto`, **When** the user adds new destination Project plan,
   **Then** it must use the separate `Nuova allocazione` action with a Note; it is not
   treated as copied/reprogrammed plan.
5. **Given** mode `Riporto`, **When** a canonical copy/reprogramming of the same
   Project Estimates to `N+1` is attempted, **Then** it is rejected as a conflicting
   deferral mode.

---

### User Story 4 - Reprogram Project Estimates atomically (Priority: P1)

As a proposal manager, I can reprogram an explicit amount of Project Estimates from
Open `N` to Open `N+1`, so source allocation decreases and new destination Estimates
increase by exactly the same amount with new identities and traceable lineage.

**Why this priority**: Reprogramming is an actual cross-Exercise plan mutation and
must be balanced, atomic, idempotent, and reversible without matching heuristics.

**Independent Test**: Select source Estimate-line reductions, approve the Proposal,
and verify exact source reduction, exact destination creation, new identity and
lineage, zero Carryover, unchanged Budgets, failure rollback, retry, and later Actual
behavior.

**Acceptance Scenarios**:

1. **Given** current pre-operation availability of `6,000`, **When** the user
   reprograms `4,000` through explicit source Estimate-line reductions totaling
   `4,000`, **Then** source current allocation falls by exactly `4,000`, destination
   new Estimates total exactly `4,000`, and Carryover is zero.
2. **Given** source allocation `6,000` composed of `5,000` received Carryover and
   only `1,000` active source Estimates, with Actual `0`, **When** Reprogramming is
   prepared, **Then** canonical availability remains `6,000` but at most `1,000` can
   be executed from explicit source Estimate reductions; the received Carryover in
   the source Exercise is not reduced or rewritten to manufacture the missing source
   reduction.
3. **Given** a selected source Estimate line, **When** only part of its amount moves,
   **Then** the source line keeps its identity with the reduced amount; **When** its
   full amount moves, **Then** that source Estimate line is annulled rather than
   physically deleted.
4. **Given** selected reductions from one or more source Project Expenses, **When**
   Reprogramming is applied, **Then** destination Expenses and Estimate lines are new
   identities, each destination Expense preserves the source Expense
   `CopiedFromOriginKey`, and no Actual is copied.
5. **Given** any mismatch among declared Reprogrammed amount, source reduction sum,
   or destination Estimate sum, **When** readiness or approval is evaluated, **Then**
   the Proposal is `Incoerente` with the canonical unbalanced reason and nothing is
   applied.
6. **Given** a successful Reprogramming, **When** later Actuals are recorded in either
   Exercise, **Then** they may create overspend but do not retroactively alter or
   invalidate the already executed Reprogramming.
7. **Given** a persistence failure or stale source during approval, **When** the
   operation fails, **Then** source reductions, destination creations, deferral
   state, Budget, and audit application events all roll back.
8. **Given** the same successful approval operation UUID is retried, **When** approval
   is submitted again, **Then** no source reduction or destination Estimate is
   duplicated.

---

### User Story 5 - Change a live deferral mode safely before Closing (Priority: P1)

As an operational editor, I can change a Project's live mode while both consecutive
Exercises remain Open, so a previous Reprogramming can be reversed precisely or a
Carryover can be replaced without rewriting unrelated work.

**Why this priority**: §16.10 explicitly permits a reasoned pre-Closing mode change
and requires exact reversibility.

**Independent Test**: Execute every supported mode transition, independently modify
one involved source or destination Estimate line, and verify exact reversal,
blocking, Draft realignment, unchanged Budgets, idempotency, and audit.

**Acceptance Scenarios**:

1. **Given** live `Riporto`, **When** the user changes to `Riprogrammazione`, **Then**
   provisional Carryover becomes zero and the newly confirmed Reprogramming is
   executed atomically.
2. **Given** live `Riprogrammazione`, **When** the user changes to `Riporto`, **Then**
   only the source reductions made by that Reprogramming are restored, only its
   destination Estimate lines are annulled, the source maximum is recalculated from
   the restored allocation and current Actuals, and the newly confirmed provisional
   Carryover must fit that recalculated maximum.
3. **Given** live `Riprogrammazione`, **When** the user changes to `Nessuna`, **Then**
   only that operation's source reductions are restored and its destination Estimate
   lines are annulled; independent destination allocations remain untouched.
4. **Given** an involved source or destination Estimate line was independently
   modified after Reprogramming, **When** reversal is attempted, **Then** the change
   is blocked and no overwrite is performed.
5. **Given** one of the Exercises is Closed, **When** any live mode change is
   attempted, **Then** it is rejected.
6. **Given** another Draft Proposal contains the affected Project, **When** the live
   mode change succeeds, **Then** the whole Project source in that Draft becomes
   `Da riallineare`.
7. **Given** an existing approved Budget in either Exercise, **When** a live mode
   change succeeds, **Then** every Budget remains immutable.
8. **Given** a successful live mode-change UUID is retried, **When** the operation is
   submitted again, **Then** the same resulting deferral state is returned without
   duplicate economic effects.

---

### User Story 6 - Read the decision correctly in Current Situation, Budget and Timeline (Priority: P2)

As a user reviewing a Project or Budget, I can distinguish Estimates, received
Carryover, Reprogramming, independent new allocation, and current warnings, so the
year-passage decision is explainable without reconstructing history.

**Why this priority**: Carryover must not disappear into Estimates, and
Reprogramming must not look like an ordinary copy.

**Independent Test**: Materialize Budgets for all three modes, create one independent
new allocation, trigger an over-limit provisional warning, and inspect Project,
Proposal, Budget, and Timeline representations.

**Acceptance Scenarios**:

1. **Given** mode `Riporto`, **When** the destination Budget is approved, **Then** the
   Project row stores Estimates separately from approved Carryover, marks it
   `Provvisorio`, and `Allocato Approvato = Stime + Riporto`.
2. **Given** mode `Riprogrammazione`, **When** the destination Budget is approved,
   **Then** approved Carryover is zero, the approved reprogrammed amount and exact
   lineage are materialized, and new destination Estimates remain ordinary Project
   Estimate values with new identities.
3. **Given** mode `Nessuna`, **When** a Budget is approved, **Then** Carryover and
   Reprogrammed amount are zero.
4. **Given** an independent `Nuova allocazione`, **When** Budget and Timeline are
   inspected, **Then** it remains distinguishable from Reprogramming and is never
   removed by a later Reprogramming reversal.
5. **Given** any successful deferral choice/change, **When** Timeline is read, **Then**
   actor, timestamp, company, Project, affected Exercises, previous/new mode and
   amounts, allocation impacts, reason where required, operation identity, and
   Proposal/Budget reference where applicable are available.

## Edge Cases

- A negative Actual may increase mathematical residual but never raises transferable
  availability above current allocation.
- A Project with zero current allocation has zero transferable availability even with
  a negative Actual.
- `Riporto` cannot encode zero; zero transfer is `Nessuna`.
- `Riprogrammazione` cannot encode zero.
- Carryover and Reprogramming cannot coexist for the same
  `Project + source Exercise + immediate destination Exercise`.
- Only Projects may produce or receive Carryover. Contracts, standalone Expenses,
  Cost Centers, and Suppliers always have zero Carryover.
- Outgoing Carryover does not reduce source Estimates or source current allocation.
- Reprogramming reduces only selected active source Estimate lines. Actual lines are
  never edited, copied, matched, or consumed.
- A received Carryover in the source Exercise contributes to canonical allocation and
  therefore to the availability cap, but it is not itself a reducible source Estimate.
  If reducible Estimates total less than that cap, only the reducible Estimate total
  can be Reprogrammed.
- Reprogramming does not reconstruct which historical Estimate "caused" an Actual.
- A later Actual does not retroactively invalidate an executed Reprogramming.
- An over-limit already-live provisional Carryover is warned, not auto-corrected.
- An approved Budget is never rewritten after later source facts or mode changes.
- An independently added destination allocation is never identified as
  Reprogramming by similarity of amount, description, supplier, or date.
- Reversal uses preserved IDs and expected post-operation line state; there is no
  fuzzy matching.
- If an involved reprogramming line was independently changed, or an involved parent
  Expense was independently moved/reversed so the expected year/ownership/economic
  state no longer matches, reversal blocks rather than overwriting it.
- A destination Expense created by Reprogramming may later acquire unrelated lines or
  Actuals; reversal annuls only the Estimate lines created by the Reprogramming and
  does not remove unrelated facts.
- If a source Project Expense references an Archived supplier, that supplier is not
  silently selected on the new destination Expense. The destination remains a normal
  Project Expense: supplier is optional, and the user explicitly confirms
  `Nessun Fornitore` or selects an active supplier. The system does not silently
  substitute or clear the supplier as a hidden economic rule; source lineage remains
  explicit.
- Project state `Chiuso` or `Cancellato` at source-year 31 December forces mode
  `Nessuna` for that passage.
- A live Project transition must not create a terminal-at-31-December state while an
  outgoing live mode remains non-`Nessuna`; it blocks instead of auto-changing the
  economic decision.
- Existing consolidated Carryover, once S9 exists, is read-only to S8 when the source
  Exercise is Closed; S8 does not implement consolidation or reopening.
- A Proposal may change provisional Carryover amount while still in `Riporto`, but an
  already executed Reprogramming amount is not silently edited in place. Changing an
  executed Reprogramming first requires leaving `Riprogrammazione`, which invokes the
  canonical exact reversal.
- Missing immediate previous Exercise means no editable incoming deferral for the
  Proposal; ordinary new Project allocation remains possible where otherwise valid.

## Requirements *(mandatory)*

### Functional Requirements

- **S8-FR-001**: The system MUST calculate Project current allocation as received
  Carryover plus active Estimate lines of the Project's Expenses in the Exercise.
- **S8-FR-002**: The system MUST calculate Project Actual, Operational Variance, Residual,
  and Maximum Transferable Availability exactly according to canonical §8.4.
- **S8-FR-003**: The Maximum Transferable Availability MUST never be negative and MUST
  never exceed current Project allocation, including when Actual is negative.
- **S8-FR-004**: Exercise current allocation MUST include received Project Carryover
  exactly once in addition to current Estimate lines and MUST NOT double-count child
  Expenses.
- **S8-FR-005**: Carryover MUST exist only for Projects; no Contract, standalone Expense,
  Cost Center, or Supplier may receive or produce it.
- **S8-FR-006**: For each relevant
  `Project + source Exercise N + destination Exercise N+1`, the effective mode MUST be
  exactly one of `Nessuna`, `Riporto`, or `Riprogrammazione`.
- **S8-FR-007**: Source and destination MUST belong to the same Company and destination
  year MUST equal source year plus one for every editable deferral operation.
- **S8-FR-008**: Mode `Nessuna` MUST imply Carryover `0` and Reprogrammed amount `0` and
  MUST create no transfer.
- **S8-FR-009**: Mode `Riporto` MUST require
  `0 < RiportoProvvisorio <= DisponibilitàMassimaRiportabileCorrente`, a non-blank
  reason/Nota preserved with the action and audit evidence, MUST set Reprogrammed
  amount to `0`, and MUST leave source Estimates unchanged.
- **S8-FR-010**: A Proposal MUST NOT initialize provisional Carryover automatically to
  the maximum. Existing live Carryover is preserved as baseline; otherwise the
  initial value is zero until an explicit choice.
- **S8-FR-011**: If later source facts reduce the maximum below an already-live
  provisional Carryover, the system MUST keep the live value unchanged, show the
  canonical warning, and block a later Proposal/Revision from confirming the
  over-limit value.
- **S8-FR-012**: Mode `Riprogrammazione` MUST require, at execution,
  `0 < ImportoRiprogrammato <= DisponibilitàPreOperazione` and a non-blank reason
  preserved with the action/audit evidence. `DisponibilitàPreOperazione` is a
  necessary upper bound, not a promise that the whole amount is mechanically
  transferable: `ImportoRiprogrammato` MUST also equal the sum of explicit reducible
  source Estimate-line reductions. S8 MUST NOT reduce or rewrite Carryover received
  by the source Exercise to manufacture source reduction capacity.
- **S8-FR-013**: Reprogramming MUST reduce source current allocation by exactly the
  Reprogrammed amount and create destination Estimate lines totaling exactly the
  same amount. Therefore an executable Reprogramming amount cannot exceed the total
  active source Project Estimate amount actually selected/reducible, even when the
  canonical pre-operation availability is higher because source allocation includes
  received Carryover.
- **S8-FR-014**: Reprogramming MUST set Carryover to zero and MUST be rejected when
  source reduction, destination Estimate creation, and declared amount are not equal.
- **S8-FR-015**: Reprogramming MUST operate only on explicit active Estimate-line
  reductions belonging to Project Expenses in the source Exercise and MUST NOT edit
  Actual lines.
- **S8-FR-016**: A partial source-line Reprogramming MUST preserve the source line
  identity with a reduced amount; a full source-line Reprogramming MUST annul that
  Estimate line instead of deleting it.
- **S8-FR-017**: Destination Reprogramming MUST create new Expense and Estimate-line
  identities, preserve source Expense lineage through `CopiedFromOriginKey`, and copy
  no Actual.
- **S8-FR-018**: Destination Expenses created by Reprogramming MUST retain the same
  Project ownership; their Estimate amounts MUST be generated from the explicitly
  selected source reductions without amount matching heuristics, and the resulting
  destination Project plan MUST still satisfy the existing Project-state rules for
  receiving new Estimates in that Exercise (including an applicable planned reopen/
  opening transition when required by the canonical domain).
- **S8-FR-019**: Additional destination Project Estimates that are not produced by
  Reprogramming MUST be represented by a separate `Nuova allocazione` Proposal
  action with a non-blank Note. The existing generic planned-Expense creation path
  MUST NOT bypass this declaration for a new destination Expense attached to an
  already-live Project: backend validation and UI routing MUST classify that case as
  `Nuova allocazione`. Generic `CreateExpense` remains valid for standalone Expenses
  and for child plan of a Project that is itself new in the same Proposal.
- **S8-FR-020**: While mode is `Riporto`, canonical copy/Reprogramming of that Project's
  source Estimates into the same destination passage MUST be rejected.
- **S8-FR-021**: Project state `Chiuso` or `Cancellato` at 31 December of the source
  Exercise MUST imply mode `Nessuna` and zero Carryover for that passage. In a
  Proposal this check MUST use the Project state resulting after all applicable
  planned Project transitions, not only the pre-Proposal live display state. An
  ordinary live Project-transition operation that would make the Project terminal at
  source-year 31 December while an outgoing live mode is `Riporto` or
  `Riprogrammazione` MUST be rejected rather than silently changing or reversing the
  deferral. The user can first change the live mode to `Nessuna`, or plan the terminal
  transition and `Nessuna` atomically in a Proposal.
- **S8-FR-022**: Proposal deferral planning MUST operate against the immediate previous
  Exercise of the Proposal's main Exercise; both Exercises MUST be Open for a new or
  changed provisional Carryover/Reprogramming effect.
- **S8-FR-023**: The existing S7 whole-source Project baseline/fingerprint MUST include
  received deferral state so any live deferral change invalidates affected Drafts.
  Every Proposal deferral action that depends on source Exercise `N` MUST also capture
  and later revalidate the source-year Project context used by the decision,
  including a canonical source-year fingerprint/revision, current source allocation,
  current Actual, maximum transferable availability, and every explicitly referenced
  source Expense/Estimate-line state. A change in those source facts MUST require S7
  whole-source realignment or produce the exact canonical inconsistency; it MUST NOT
  be accepted merely because the destination-year Project snapshot is unchanged.
- **S8-FR-024**: Proposal readiness MUST activate the existing canonical reasons
  `carryover_above_limit`, `reprogramming_above_available`,
  `reprogramming_unbalanced`, and `deferral_modes_conflict`; it MUST NOT add a generic
  S8 inconsistency. Existing S7 baseline invalidation keeps precedence: a changed
  source is first `Da riallineare`; after the baseline is explicitly realigned, an
  invalid retained S8 action becomes `Incoerente` with its exact S8 reason.
- **S8-FR-025**: Approval MUST re-enumerate and revalidate source/destination Exercises,
  current Project facts, Estimate lines, mode, amounts, and destination plan under
  lock before applying any S8 effect.
- **S8-FR-026**: Proposal approval MUST apply S8 effects atomically together with the
  existing S7 multi-Exercise operation; any validation or persistence failure MUST
  leave zero partial source reductions, destination creations, deferral state,
  Budget, or applied audit events.
- **S8-FR-027**: Successful Reprogramming MUST be idempotent for the same operation
  identity and MUST NOT reduce source Estimates or create destination Estimates more
  than once.
- **S8-FR-028**: Later Actuals MUST NOT retroactively alter or invalidate an already
  executed Reprogramming.
- **S8-FR-029**: Before Closing, an already-applied live `Riporto` or
  `Riprogrammazione` mode MAY be replaced or removed only while both consecutive
  Exercises are Open, and the change MUST be atomic and reasoned. The direct live
  operation is limited to `Riporto -> Nessuna`, `Riporto -> Riprogrammazione`,
  `Riprogrammazione -> Riporto`, and `Riprogrammazione -> Nessuna`. A new transfer
  from `Nessuna`, and a provisional Carryover amount change while remaining in
  `Riporto`, are performed through a Proposal/Revision; Closing-time decisions remain
  S9.
- **S8-FR-030**: `Riporto -> Riprogrammazione` MUST zero provisional Carryover and apply
  the newly confirmed Reprogramming in the same operation.
- **S8-FR-031**: `Riprogrammazione -> Riporto` or `Riprogrammazione -> Nessuna` MUST
  restore only the exact source reductions made by the active Reprogramming and
  annul only the exact destination Estimate lines created by that Reprogramming.
  The same exact reversal rule applies whether the replacement/removal is approved
  through a Proposal/Revision or executed by the permitted direct live operation. For
  `Riprogrammazione -> Riporto`, the new provisional Carryover limit MUST be computed
  from the hypothetical post-reversal source allocation plus current source Actuals;
  the new Carryover is validated only after that restored-state availability is
  calculated.
- **S8-FR-032**: Reprogramming reversal MUST use persisted source/destination identities
  and complete expected post-operation mutable row state plus a line-specific
  monotonic `revision`; if an involved source or destination row has been independently
  modified—even if later returned to the same visible values—or its ownership/year
  lineage no longer matches, the mode change MUST be blocked without overwrite.
- **S8-FR-033**: Reprogramming reversal MUST NOT remove or alter independent destination
  allocations, unrelated Estimate lines, or Actual lines added later.
- **S8-FR-034**: Every successful live mode change MUST mark affected Draft Proposal
  Project sources `Da riallineare` without blocking the live operation.
- **S8-FR-035**: No S8 live or Proposal operation may rewrite an existing Budget or
  historical snapshot.
- **S8-FR-036**: The destination Budget Project row MUST materialize approved Estimates,
  approved Carryover and its state, approved allocation, deferral mode, approved
  Reprogrammed amount, exact approved action/reason, and resolved Reprogramming
  lineage when applicable.
- **S8-FR-037**: Budget approved allocation for a Project MUST equal approved Estimate
  total plus approved Carryover; Reprogramming amounts are already represented by
  the new Estimate lines and MUST NOT be added a second time.
- **S8-FR-038**: Timeline/audit MUST record every Project deferral choice/change with
  actor, company, Project, source/destination Exercises, previous/new mode and
  amounts, allocation impact by Exercise, reason where mandatory, operation identity,
  exact Reprogramming effects when applicable, and Proposal/Budget reference when
  applicable.
- **S8-FR-039**: Proposal deferral actions use `gestisce_proposte`; Proposal approval
  continues to use `approva_budget`; direct live pre-Closing changes use the existing
  `modifica_operativita` Project authorization. No new capability is introduced.
- **S8-FR-040**: S8 MUST NOT implement Closing consolidation, Closing Snapshot,
  late-correction behavior, Forecast, reporting/export semantics, or automatic
  Exercise creation belonging to S9–S11.

### Canonical Requirement Mapping

| Canonical requirement | S8 implementation obligation |
|---|---|
| FR-059 | S8-FR-006, S8-FR-008, S8-FR-009, S8-FR-012, S8-FR-014, S8-FR-020, S8-FR-021, S8-FR-029–S8-FR-033 |
| FR-060 | S8-FR-001–S8-FR-004, S8-FR-009–S8-FR-011, S8-FR-036–S8-FR-037 |
| FR-061 | S8-FR-002–S8-FR-003 and authoritative negative-Actual tests |

### Canonical Invariant Mapping

| Invariant | S8 proof |
|---|---|
| 28.11 Residuo e disponibilità riportabile | Formula unit/feature tests for positive, zero, overspent, and negative Actual |
| 28.12 Riporto entro il limite | Proposal/live validation plus Budget snapshot assertions |
| 28.13 Effettivo negativo | Explicit `0/-1000` and `10000/-1000` cases |
| 28.14 Riporto esclusivo del Progetto | Model/action/UI rejection for non-Project sources |
| 28.15 Modalità di rinvio | Mutual exclusion, balance, idempotency, later-Actual tests |
| 28.16 Progetto terminale | 31-December `Chiuso`/`Cancellato` rejection tests |

### Regression Obligations From Earlier Slices

S8 does not take ownership of these already-mapped canonical requirements/invariants,
but its implementation MUST preserve and regression-test them where S8 changes their
code paths:

| Existing rule | S8 regression obligation |
|---|---|
| FR-007 / 28.52 — no double counting | Carryover is added once at Project/Exercise level; Project child Expenses are not added twice |
| FR-008, FR-019 / 28.55 — stable identity and canonical copy lineage | Reprogrammed destination Expenses/lines have new identities, retain explicit lineage where copied, and copy no Actual |
| FR-011 / 28.17 — immutable approved Budget | Source and prior destination Budget versions are never rewritten |
| FR-017 / 28.20 — Proposal only changes plan | S8 Draft actions never edit/copy Actuals |
| FR-024 / 28.22 — whole-source realignment | Any relevant live Project/deferral change makes the affected Draft Project source `Da riallineare` |
| FR-026 — closed inconsistency vocabulary | S8 activates the existing four exact reasons and adds no generic inconsistency |
| FR-028, FR-094 / 28.23 — atomic inter-Exercise approval | Source reduction, destination creation, deferral state, audit, and Budget commit together or not at all |
| FR-029 — no rewriting Closed Exercises | S8 applies new/changeable effects only while the relevant Exercises are Open |
| FR-055, FR-057 / 28.24 — Project state at date | terminal validation uses Project state at source-year 31 December; Proposal validation includes applicable planned transitions rather than the pre-Proposal display state |
| FR-084 — explanatory append-only Timeline | every applied deferral decision/change has structured before/after evidence |
| FR-085, FR-086 / 28.47–28.48 — autonomous snapshot schema | Budget stores resolved plan facts without depending on current live rows later |
| FR-092 / 28.57 — company-scoped permissions | existing capabilities are rechecked for the exact Company on write |

### Key Entities

- **ProjectDeferral**: current live annual passage state for one Project from Exercise
  `N` to immediate `N+1`; it is not an event ledger and does not replace Timeline.
- **Proposal Project deferral action**: one typed planning decision that selects
  `Nessuna`, `Riporto`, or `Riprogrammazione` and contains the deterministic facts
  needed to validate the proposed effect before approval.
- **Nuova allocazione**: separate typed Proposal action for new destination Project
  Estimates that are independent of Reprogramming.
- **Reprogramming effects**: the exact source Estimate-line before/after facts and
  exact destination Expense/Estimate-line identities created by the currently active
  Reprogramming, retained only to make canonical reversal precise.
- **Budget Project detail**: immutable approved representation of mode, Carryover,
  Estimates, Reprogrammed amount, and lineage for the destination Exercise.

## Success Criteria *(mandatory)*

- **SC-001**: Every canonical §8.4 formula example, including both negative-Actual
  examples, produces the exact expected two-decimal result.
- **SC-002**: No tested path can persist both non-zero Carryover and non-zero
  Reprogramming for the same Project passage.
- **SC-003**: A valid provisional Carryover changes destination allocation by exactly
  the chosen amount, source allocation by zero, and no approved Budget retroactively.
- **SC-004**: A valid Reprogramming changes source allocation by `-X` and destination
  Estimate allocation by `+X`, with new destination identities and zero Carryover.
- **SC-005**: Forced failure at every S8 apply stage leaves no partial economic effect.
- **SC-006**: Retrying a successful S8 approval/live operation with the same operation
  identity creates no duplicate reduction, Expense, Estimate line, or audit receipt.
- **SC-007**: Every supported Reprogramming reversal restores/annuls exactly the IDs
  created or changed by that operation; one independently modified involved line
  blocks the reversal with zero overwrite.
- **SC-008**: Independent `Nuova allocazione` rows survive every Reprogramming
  reversal unchanged.
- **SC-009**: An over-limit already-live provisional Carryover is visibly warned and
  never silently reduced or written into a later approved Budget unchanged.
- **SC-010**: Budget rows and Project Current Situation show Estimates, Carryover,
  mode, and allocation without double counting.
- **SC-011**: All six primary S8 invariants have authoritative automated tests and all
  relevant MUST NOT rules have rejection tests.
- **SC-012**: The full repository quality gate passes and an authenticated browser
  demonstration covers Carryover, Reprogramming, exact reversal, warning, and
  Budget/Timeline inspectability before S8 is marked verified.

## Explicitly Out of Scope

- Carryover consolidation during Closing and replacement of provisional value by the
  valid consolidated value: S9.
- Closing blockers/warnings beyond the S8 values they later consume: S9.
- Closing Snapshot and automatic creation of `N+1`: S9.
- Historical corrections and late Actual semantics: S10.
- Full report comparison, export, Forecast, and trend semantics: S11.
- Directed `Sostituisce` relation gap already recorded in the roadmap.
- Automatic maximum Carryover.
- Split Carryover + Reprogramming for one Project passage.
- Automatic source-line selection or FIFO/LIFO matching.
- Fuzzy reversal by amount/description/date.
- Rewriting an approved Budget.
