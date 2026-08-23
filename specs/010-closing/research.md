# Research: Closing

## 1. Canonical sources reviewed

Primary normative sections:

- §6.10–6.12 — Closing Snapshot and Actual references
- §7.5–7.6.5 — annual context and Closing inclusion
- §8 — current values
- §9.2, §9.5–9.6 — state reference at 31 December and future compatibility
- §10 — inter-Exercise atomicity
- §11 — Exercise lifecycle and `N+1`
- §14 — Closing
- §16.10–16.11 — Project deferral and annuality
- §17 — Carryover consolidation
- §18.14–18.21 — Contract cycles/renewal/Closing warning
- §20 — annual classification
- §22.2, §22.4–22.9 — Timeline and required reasons
- §23.1–23.14 — immutable Snapshot schema
- §26.1–26.6 — applied settings and Close capability
- §27.17, §27.31, §27.39, §27.40, §27.51 — stress cases
- invariants 28.25–28.28, 28.49, 28.58

Reviewed canonical SHA:

`122e8af31e98789940672a5c0e8ddbb84f2441c6`

## 2. Existing implementation to reuse

S8 already provides:

- `ProjectDeferral` one-row annual passage state;
- canonical residual/maximum formulas;
- exact Reprogramming effects with persisted IDs/revisions;
- exact reversal logic;
- Project/Exercise allocation including received Carryover;
- S7 Draft realignment after live Project change.

S7/S6 already provide:

- immutable Budget snapshots;
- typed audit events with operation sequence;
- transaction/locking patterns;
- Proposal readiness and whole-source realignment.

S5 already provides:

- Contract lifecycle/renewal facts;
- anchored renewal helpers;
- annual Contract allocation;
- Contract Estimate recalculation;
- annual classifications.

S3/S4 already provide:

- Exercise status and annual values;
- Project date-state timeline;
- direct operation guards on many Open-Exercise economic mutations.

## 3. Required technical adaptations, not new domain behavior

### 3.1 `ProjectDeferral.carryover_state`

The S8 table enum already contains `consolidated`, but its DB CHECK currently permits
only `provisional` for mode `carryover`.

S9 needs a forward-only corrective migration that permits:

- `carryover + provisional`;
- `carryover + consolidated`;

while preserving the existing closed shape for `none` and `reprogramming`.

Do not rewrite the S8 migration.

### 3.2 Contract renewals need a Closing cutoff

Current `ProcessContractRenewals` processes through Company-local `today`.

Closing N may be technically executed much later. Canonical state and due events must
stop at `N-12-31`.

Refactor the smallest reusable renewal planning/apply path so:

- ordinary processing still uses `today`;
- Closing uses `N-12-31`;
- preview and confirmation use the same deterministic renewal rules;
- retries do not duplicate lifecycle facts.

Do not fork a second renewal engine.

### 3.3 Canonical Exercise initialization

Current `CreateExercise` already creates the Exercise and inherits Project/Contract
classifications. S9 automatic `N+1` creation must additionally ensure canonical
Contract Estimate initialization.

The direct manual Exercise creation path and Closing-created path should share the
same initialization behavior.

`RecalculateContractEstimates` must calculate Contract state using the applicable
renewal configurations as well as lifecycle facts when projecting an Open Exercise.

### 3.4 Closed-year temporal immutability

Many line/classification paths already require an Open Exercise. Date-based Project
and Contract operations can otherwise modify historical facts whose dates fall in a
Closed year.

S9 must add the smallest shared or local guards needed so ordinary operations cannot
alter materialized historical 31-December state/conditions/renewal facts after
Closing.

This is enforcement of §14.8, not a new correction system.

## 4. No persistent Closing Draft

The canonical domain defines:

- review/blocks/warnings;
- explicit user decisions;
- final confirmation;
- one immutable Closing Snapshot.

It does not define a Closing Proposal, Closing Draft versions or a multi-level
approval workflow.

S9 therefore uses:

- a side-effect-free review;
- explicit form state in the Closing page;
- one final atomic `CloseExercise` operation.

Do not add a `closing_drafts` table.

## 5. Project Closing decisions

For every Project Planned/Open at 31 December:

- state confirmation is explicit;
- mode confirmation is explicit when continuing;
- terminal result forces `Nessuna`;
- state transition is created only if the confirmed state differs;
- transition effective date is 31 December;
- full Project timeline is validated against already approved future transitions.

A Project can remain Planned/Open with mode `Nessuna`; positive Residual does not
force a transfer.

## 6. Carryover at Closing

The final amount is a new explicit decision, not a computed default.

The final live destination allocation uses the consolidated amount. Existing
destination Budgets remain unchanged.

If `N+1` does not exist and management terminates, there is no destination passage to
persist; the Closing Snapshot still materializes the explicit zero/no-transfer
decision.

## 7. Reprogramming integrity

S9 has two cases:

1. **not executed yet**: apply exactly once with S8 semantics;
2. **already executed**: verify the exact persisted amount/effects and do not apply.

If the final mode remains Reprogramming, the existing executed operation remains the
operation being closed; S9 does not create a same-mode replacement mechanism.

If persisted effects no longer match because a line/Expense was independently
changed, §14.3 blocks Closing.

The canonical document mentions realignment but does not define a new live
realignment operation here. This package deliberately does not invent one.

## 8. Snapshot design

Use dedicated immutable Closing tables instead of extending Budget tables because
the schemas have different semantics:

- Budget excludes Actual/Residual;
- Closing must contain final Actual, variance and closing decisions.

Use common scalar columns for the fields needed across all source types and a
versioned JSON `detail` for source-specific materialization.

This mirrors the existing Budget snapshot pattern without merging two distinct
snapshot types.

## 9. Warnings

S9 implements all §14.4 warnings representable with S0–S9 data.

`Annotazione di errore storico` is introduced only by S10. Before S10 there is no
canonical persisted annotation that S9 could query. Do not introduce a placeholder
entity.

The Carryover warning in §14.4 is specifically the difference between an **approved
provisional Carryover in N+1** and the final consolidable maximum. Do not broaden it
to every provisional live value.

## 10. Evidence

For S9, the Closing Snapshot plus audit records are the immutable evidence:

- actor/timestamp;
- Project decisions/reasons;
- accepted warnings;
- settings applied;
- `N+1` disposition;
- explanatory event references.

The canonical domain does not require a new Closing-only file-upload workflow.
Do not add one.


## 11. Overspend Note consistency

The Company setting applies to operations from the time it is effective; it does not
rewrite past decisions (§26.1).

Closing must not retroactively require an overspend Note for an operation that was
validly performed while the setting did not require it. The Closing block concerns a
required Note that should already exist for an operation executed under the
applicable setting.

Prefer validating the existing structured Project-activity/audit evidence already
written by the operation rather than creating a new Closing-only note history.

## 12. Closing authorization versus ordinary-operation authorization

Canonical `chiude_esercizio` is sufficient to execute Closing, decide Project
year-end state/deferral and confirm `N+1` continuation.

Existing ordinary Actions such as Project transition creation, Contract renewal
processing and manual Exercise creation may authorize `modifica_operativita`.

Closing must not accidentally require that additional capability.

Reuse/extract their deterministic validation/application internals where useful, but
authorize the aggregate Closing once through `ExercisePolicy::close` (or equivalent)
with `chiude_esercizio`. Ordinary public Actions keep their existing policies.

## 13. Missed automatic renewals in already-Closed years

Canonical §27.39 explicitly allows deterministic automatic renewals that were not
previously materialized to be appended later in chronological order.

This does **not** reopen or recalculate the earlier Closed Exercises:

- the lifecycle facts may be appended;
- existing Closed-year Budget/Closing Snapshots remain unchanged;
- Estimate recalculation applies only to Open Exercises;
- Closing N still stops materialization at N-12-31.

Therefore post-Closing temporal guards must distinguish this canonical automatic
materialization path from user-driven ordinary historical mutation.

## 14. Existing directed `Sostituisce` gap

The repository roadmap already declares the directed `Sostituisce` behavior as an
unresolved separate domain gap.

Closing must materialize effective informative relations that actually exist, but S9
must not implement or infer `Sostituisce` merely because §23.8 mentions effective
relations.

No fuzzy replacement relation is allowed.
