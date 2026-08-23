# Research: Carryover and Reprogramming

This document records technical decisions required to implement S8 without adding
product behavior beyond the canonical domain.

## Decision 1 — Persist one current Project deferral state per consecutive Exercise pair

**Decision:** Add one `ProjectDeferral` current-state record keyed by
`Project + source Exercise + destination Exercise`. The record stores the current
mode, current Carryover amount/state, current Reprogrammed amount, and—only while an
active Reprogramming exists—the exact effects needed to reverse it.

**Rationale:** The canonical model says mode and received Carryover are annual live
facts. They cannot be reconstructed safely from Timeline because §22.10 forbids
event-sourcing the ordinary current state. A single row is sufficient; a generic
ledger would duplicate AuditEvent.

**Alternatives rejected:**
- infer the mode from Expenses: violates explicit mutual exclusivity and makes
  `Nessuna` ambiguous;
- store only events: would make current behavior event-sourced;
- create separate Carryover and Reprogramming tables: permits conflicting states and
  duplicates the one-mode concept.

## Decision 2 — Keep Carryover separate from Estimate lines

**Decision:** Received Carryover is a field of the Project deferral state and is added
to Project and Exercise allocation formulas. No synthetic Expense or Estimate line is
created for Carryover.

**Rationale:** §8.4 explicitly defines Project allocation as
`RiportoRicevuto + Stime`. A synthetic Estimate would erase that distinction and make
Budget `approved_carryover` meaningless.

**Alternative rejected:** create a pseudo Expense called Carryover. This would
double-count or misclassify the value and violate the canonical model.

## Decision 3 — Reprogramming changes real Estimate lines; Carryover does not

**Decision:** A Reprogramming action records explicit source Estimate-line
reductions. A partial reduction updates the existing source line amount; a full
reduction annuls the line. Destination plan is generated from those reductions and
creates new Project Expense/Estimate identities.

**Rationale:** This is the smallest deterministic implementation of §16.10:
source allocation really falls, destination Estimates really increase, and no
matching engine is needed.

**Alternative rejected:** store a negative "reprogramming adjustment" beside
unchanged source Estimates. That would not satisfy the canonical requirement that
source Estimates are reduced/annulled (or the no-Actual Expense is reversed).

## Decision 4 — Treat canonical availability as an upper bound, not synthetic source plan

**Decision:** `DisponibilitàPreOperazione` is validated exactly as canonical, including
received Carryover in source allocation. Separately, `ImportoRiprogrammato` is derived
from explicit active source Estimate reductions and must equal their sum. S8 never
reduces the source Exercise's received Carryover to make a larger Reprogramming fit.

Example: source allocation `6,000 = 5,000 received Carryover + 1,000 Estimates`, Actual
`0`. Canonical availability is `6,000`, but the executable Reprogramming through the
defined source Estimate mechanism is at most `1,000`.

**Rationale:** §16.10 simultaneously requires the availability upper bound, exact
`RiduzioneAllocatoOrigine = ImportoRiprogrammato`, and reduction/annulment of source
Estimate lines. The intersection of these mandatory conditions is deterministic; no
new economic cap formula and no consumption ordering for Carryover is introduced.
This also respects §17.8, which forbids reconstructing or consuming Carryover as a
specific underlying source.

**Alternative rejected:** reduce received Carryover as if it were an Estimate. The
canonical operation does not define that mutation, and it would alter another annual
passage instead of reducing the explicitly identified source Estimate plan.

## Decision 5 — Standardize S8 source-side application on Estimate-line reduction/annulment

**Decision:** S8 uses the canonical permitted line-level path. It does not
automatically reverse the parent source Expense when all its Estimates move.

**Rationale:** The domain permits either reducing/annulling source Estimate lines or
reversing an eligible no-Actual Expense. Using one line-level rule eliminates a
second branch, preserves source Expense identity, and makes reversal exact. This does
not remove any required S8 functionality.

**Alternative rejected:** dynamically choose between line annulment and Expense
reversal. It adds state combinations and reversal cases without a domain requirement.

## Decision 6 — Generate destination Reprogramming plan one-to-one from selected reductions

**Decision:** For each source Project Expense with selected reductions, create one new
destination Project Expense. It copies description and notes, retains Project
ownership and `CopiedFromOriginKey`, and copies the optional supplier as a default only when that supplier is still selectable under the existing
destination-Expense rules. If the source supplier is Archived, do not silently carry,
replace, or null it as a hidden rule: the Proposal uses the existing optional
Project-Expense supplier choice and the user explicitly confirms `Nessun Fornitore`
or an active supplier. Each selected source Estimate reduction creates one new
destination Estimate line with the reduction amount and copied Note.

**Rationale:** The user chooses what amount moves by selecting exact source lines and
reduction amounts. One-to-one generation makes the required equality
`source reduction = destination increase = ImportoRiprogrammato` structural rather
than heuristic, while preserving canonical new identity and lineage.

**Alternatives rejected:**
- free-form destination redistribution: adds a second editor and more invalid
  combinations without a stated requirement;
- copy full source Expenses and then trim them: creates transient invalid plan and
  unnecessary actions;
- matching destination rows by amount/description: explicitly prohibited.

Independent plan in the destination remains available through `Nuova allocazione`.

## Decision 7 — Reprogramming-created destination Expenses are normal Expenses, not a new economic type

**Decision:** Persist destination records in the existing Expense/ExpenseLine tables
with normal Project ownership and `CopiedFromOriginKey`. Their origin remains
compatible with the existing Proposal-created Expense path. The active
`ProjectDeferral` and its audit identify the economic reason they exist.

**Rationale:** The canonical model defines only Spesa and Riga, not
"ReprogrammedExpense". A new table/subtype would complicate all aggregation and
future reporting.

## Decision 8 — Keep exact reversal metadata only for the active Reprogramming

**Decision:** `ProjectDeferral.reprogramming_effects` stores:
- source Expense/Estimate-line IDs;
- source Expense IDs with expected ownership/year/reversed state plus source
  Estimate-line IDs, their line `revision` immediately after application, and
  complete expected mutable line state after the operation (amount, quantity/unit
  metadata, Note, annulment), with amount/annulment state before the operation for
  restoration;
- destination Expense IDs, ownership/year/lineage state, and the exact destination
  Estimate-line IDs with complete expected mutable line state;
- source `OriginKey` / destination `CopiedFromOriginKey` lineage.

It also stores the active Reprogramming economic operation UUID: the applied
`PlanProjectDeferral` action operation ID on the Proposal path, or the direct
`ChangeProjectDeferral` operation ID for `Riporto -> Riprogrammazione`. It does not
reuse the generic Proposal approval UUID as the Reprogramming identity.

**Rationale:** §16.10 requires mode reversal by preserved IDs and blocks overwrite
after independent line modification. Current expected post-operation state is
therefore necessary. Historical copies remain in AuditEvent and approved Budgets;
the live row does not become an append-only ledger.

**Alternatives rejected:**
- store only parent Expense revisions: too coarse because unrelated child changes
  would cause false blocking;
- store only current values or `updated_at`: insufficient because a line could be
  modified and later returned to the same values, and timestamp precision is not a
  strong concurrency/version guarantee;
- retain every historical effect array in the current row: duplicates Timeline.

**Supporting technical decision:** Add a simple integer `revision` to `ExpenseLine`.
S8 requires it to increment on every successful mutation path that can change a
Project Estimate line eligible for Reprogramming: ordinary line update, line
annul/restore, Proposal-applied Project Estimate changes, and S8's own source or
destination Estimate changes. New rows start at revision `0`; a no-op does not
increment. The column may exist on all Expense lines because they share one table, but
S8 does not require unrelated Contract/system or Actual-only workflows to be refactored
merely to consume it. This is local optimistic-version metadata, not a new business
concept or event-sourcing mechanism.

## Decision 9 — Reversal annuls only Reprogramming-created Estimate lines

**Decision:** Leaving `Riprogrammazione` restores the exact source lines and annuls
the exact destination Estimate lines created by that active Reprogramming. It does
not delete or automatically reverse the destination Expense shell.

**Rationale:** The canonical rule targets the created destination Estimates. Leaving
the Expense identity preserves lineage and permits unrelated later Actual/Estimate
facts to remain untouched.

**Alternative rejected:** reverse the whole destination Expense. That could erase or
block unrelated later facts and is broader than required.

## Decision 10 — Use one typed Project deferral Proposal action

**Decision:** Add one `PlanProjectDeferral` action type whose payload contains mode,
source/destination Exercises, amount, and, for Reprogramming, explicit source
reductions plus deterministic destination plans. Any explicit `Riporto` or
`Riprogrammazione` choice stores the mandatory rinvio Note in the existing
`ProposalAction.reason`; replacing/removing an already-applied mode also requires the
canonical mode-change reason. Replaying that action updates the Project Proposal
result's deferral plan.

**Rationale:** The domain has one mutually exclusive choice. Three unrelated actions
would allow conflicting active rows and require reconciliation logic.

## Decision 11 — Add a distinct `CreateProjectAllocation` Proposal action

**Decision:** New plan attached to an existing live Project in the destination
Exercise uses a typed `CreateProjectAllocation` action with mandatory Note. The Note
is stored in the existing `ProposalAction.reason` field; it is not silently copied
into the destination Expense `notes`. The action reuses the existing new-Expense
Proposal item/application path; it is not a new persistent entity.

**Rationale:** §16.10 explicitly requires destination plan independent of
Reprogramming to be a separate `Nuova allocazione` action with Note. A distinct
action type gives that declaration without creating a new economic model.

**Alternative rejected:** keep using unqualified `CreateExpense` for the existing
Project. That would make it impossible to prove that new destination Estimates were
declared as independent allocation rather than Reprogramming.

New child Expenses of a Project that itself is newly created in the same Proposal
retain the existing path; there is no prior Project passage to classify.

## Decision 12 — Extend existing Project totals and Exercise totals, not a second calculator tree

**Decision:** Add a small deterministic Project deferral calculator/value helper for
the canonical residual/maximum formula, then integrate received Carryover into
`Project::annualTotals()` and `Exercise::allocation()`.

**Rationale:** These are the existing authoritative current-value paths. A separate
S8 reporting aggregate would create two realities.

## Decision 13 — Extend S7 whole-source snapshot/readiness

**Decision:** The destination-year Project snapshot includes incoming deferral facts.
Every Proposal deferral action additionally captures the canonical Project snapshot
fingerprint/revision for source Exercise `N` plus the source aggregate facts and exact
selected Estimate-line state on which the decision depends. `ProjectPlan`,
`ProposalReadiness`, and `ProposalImpactPlan` revalidate both source and destination
contexts and validate/replay/preview the new typed action. The already existing S7
inconsistency reasons are reused unchanged.

**Rationale:** A Proposal for `N+1` must not accept a stale Carryover/Reprogramming
decision merely because the `N+1` Project snapshot is unchanged while source-year
Estimates or Actuals changed.

## Decision 14 — Apply every Proposal deferral transition inside the existing approval transaction

**Decision:** Extend `ApproveProposal` locking/revalidation with the deferral row and
explicit source Estimate lines. Apply the Project's ordinary plan through existing
paths, then apply the requested S8 state transition in the same transaction before
snapshot materialization.

If the current live mode is an active Reprogramming and the approved Proposal changes
it to `Nessuna` or `Riporto`, the same apply path first revalidates and exactly
reverses the persisted active effect map, then applies the requested state. Any stale
involved row blocks and rolls back the entire approval.

The deferral apply returns/persists the resolved destination IDs required by the live
row and Budget detail.

**Rationale:** Approval already owns atomic multi-Exercise application and retry.
Another transaction would violate zero-partial-effect requirements.

## Decision 15 — Direct pre-Closing mode changes reuse the same deterministic deferral rules

**Decision:** Add one operational Action with `preview` and `confirm` paths for
replacing/removing an already-applied live `Riporto` or `Riprogrammazione`. Supported
direct transitions are `Riporto -> Nessuna`, `Riporto -> Riprogrammazione`,
`Riprogrammazione -> Riporto`, and `Riprogrammazione -> Nessuna`. Creating a new
transfer from `Nessuna` remains a Proposal/Revision responsibility (or S9 at Closing).
The Action locks Company, Project, both Exercises, current deferral, and exact
involved Estimate rows; reauthorizes; revalidates; reverses the active
Reprogramming when required; applies the new mode; increments affected revisions;
marks affected Draft Project items `Da riallineare`; and appends one idempotent
Project-deferral event.

**Rationale:** This is the existing MP2 pattern for explicit multi-Exercise live
operations. The Action is narrow and does not require a command bus or generic
workflow abstraction.

## Decision 16 — Use `AuditEvent` as the idempotency receipt for direct mode change

**Decision:** The direct operation UUID is checked against the append-only audit
event. A successful retry with the same Project/operation returns the current result;
reuse for another operation/subject is rejected.

**Rationale:** S7 already uses AuditEvent receipts for reasoned operations. A separate
receipt table would duplicate existing infrastructure.

## Decision 17 — Add one Project deferral audit event type

**Decision:** Add `ProjectDeferralChanged` with the Italian label
`Rinvio progetto modificato`. The payload describes whether the applied decision was
a Carryover choice, a Proposal/Revision provisional-amount change, Reprogramming, or
a direct mode reversal/change.

**Rationale:** One event type matches the single domain concept and avoids an event
enum for every edge of the state machine while still satisfying §22's structured
event facts.

## Decision 18 — Budget materialization consumes live resolved state

**Decision:** Remove the current S7 zero placeholders for Project deferral in
`BudgetSnapshotPayload`. For the Proposal Exercise:
- `approved_estimates` = active Project Estimate total;
- `approved_carryover` = received Carryover;
- `approved_allocation` = estimates + Carryover;
- `carryover_state` = `provisional` in S8 when Carryover is non-zero;
- Project detail records `deferral_mode`, approved Carryover, approved Reprogrammed
  amount, and resolved lineage/effects when applicable.

**Rationale:** Reprogrammed value is already represented by destination Estimates and
must not be added again.

## Decision 19 — Existing approved Budgets remain read-only comparison evidence

**Decision:** Neither Proposal approval nor direct live mode change edits a prior
Budget. S8 only materializes the new destination Budget when a Proposal is approved.

**Rationale:** Required by §§10, 12, 16.10 and 23.

## Decision 20 — Over-limit live provisional Carryover is a warning plus future approval block

**Decision:** A later source change does not mutate the live Carryover. Project/annual
Current Situation displays the canonical warning. In a Draft, existing S7 baseline
invalidation is not bypassed: the changed Project is first `Da riallineare`; after
explicit realignment/replay against current reality, a retained over-limit Carryover
is `Incoerente` until its planned value is reduced or the mode changes. Any approval
attempt remains blocked throughout.

**Rationale:** This exactly follows §17.3 and avoids hidden automatic correction.

## Decision 21 — Enforce terminal compatibility at Project-transition boundaries

**Decision:** Existing live Project-transition operations must validate the resulting
Project state at 31 December for every affected open source Exercise that has an
outgoing `ProjectDeferral`. If the resulting state is `Chiuso` or `Cancellato` while
the live mode is `carryover` or `reprogramming`, reject the transition. Do not
automatically change the mode or reverse Reprogramming as a side effect of a Project
state transition. A Proposal may plan the terminal transition and mode `none`
atomically.

**Rationale:** Invariant 28.16 requires terminal Project -> `Nessuna`. Automatic
mode changes would be hidden economic operations and, for Reprogramming, would require
a reasoned multi-Exercise reversal. Blocking preserves the invariant with the smallest
explicit behavior.

**Alternative rejected:** automatically set `none` during any closing/cancellation
transition. This would hide a separate economic decision and can erase/reverse plan
without the canonical mode-change confirmation.

## Decision 22 — Do not implement Closing behavior early

**Decision:** The schema contains `carryover_state` because the canonical live/snapshot
model distinguishes provisional and consolidated, but S8 writes only `provisional`.
S9 alone will consolidate, set the consolidated state/value, perform Closing checks,
and create Closing Snapshot.

**Rationale:** This preserves a compatible state shape without crossing the roadmap
boundary.
