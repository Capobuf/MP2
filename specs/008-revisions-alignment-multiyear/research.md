# Research: Revisions, Realignment, and Multi-Year Impact

## Decision 1 — Extend the S6 Proposal/Budget path in place

**Decision:** Generalize the existing initialization, approval and materialization
path for `initial_budget` and `revision`. A Revision stores the latest Budget as its
comparison reference but snapshots current live sources exactly as S6 already does.

**Rationale:** The canonical model has one Proposal concept and one versioned Budget
lineage. A separate Revision subsystem would duplicate isolation, readiness, locking,
materialization and policy behavior.

**Alternatives considered:** A second Resource/table family, cloning the last Budget,
or reapplying old Budget rows would create a second reality and violate §12.4.

## Decision 2 — Add only the lineage and terminal metadata S7 needs

**Decision:** A forward-only migration extends the closed database enums for Proposal
and Budget purpose, adds `reference_budget_id` to Proposal, and adds discard actor,
timestamp, reason and operation identity. Existing Budget `previous_budget_id` and
unique `(exercise_id, version)` remain authoritative.

**Rationale:** The existing schema already contains stable Proposal identities,
terminal status, immutable Budget rows and version lineage. No new Revision tables
are needed.

**Alternatives considered:** Deriving the reference Budget dynamically loses the
creation-time comparison anchor; a Revision header table duplicates Proposal.

## Decision 3 — Withdraw actions one way; never delete or rewrite history

**Decision:** Add `status = active|withdrawn`, withdrawal operation, actor, timestamp
and reason to Proposal Actions. Existing `actions()` returns active actions used for
result, readiness and approval; `actionHistory()` exposes every row. Withdrawal is
the only permitted update and is irreversible. Replacement means withdraw the old
action and append a new validated typed action.

**Rationale:** `Ricarica realtà` and manual review must remove actions from the
current Proposal result while preserving the audit trail and respecting the ban on
physical deletion.

**Alternatives considered:** Physical deletion loses history; mutating JSON payloads
silently rewrites decisions; a separate resolution table adds joins without adding
domain value.

## Decision 4 — Replay only the existing closed typed-action vocabulary

**Decision:** Add a small deterministic `ProposalActionReplay` helper that starts
from a freshly captured whole-source `plan_baseline`, validates each active action
again, and calls the existing `ExpensePlan`, `ProjectPlan`, `ContractPlan`, and
relation validators in sequence. Unknown or S8+ action types remain rejected.

**Rationale:** `Mantieni proposta` is reapplication, not merge. Reusing current pure
rules keeps the result identical to ordinary planning and avoids a generic patch or
second rules engine.

**Alternatives considered:** Field-level diff/merge is prohibited; replaying raw
database updates bypasses typed validation; a generic command bus is unnecessary.

## Decision 5 — Encode the three realignment choices in one explicit Action

**Decision:** `RealignProposalItem` locks Company, Proposal, main/impacted Exercises,
source, Item, active actions and relevant child facts; reauthorizes and checks the
expected Proposal revision. `reload` withdraws every touching action and uses the
fresh baseline result. `keep` replays every touching active action and requires a
reason. `manual` replays only the explicitly selected active actions, withdraws the
others, and treats any later modification as a new typed action.

**Rationale:** One transaction gives each choice the same stale-data and rollback
guarantees. Selecting retained actions is the smallest UI that supports manual
removal and replacement without editing action payloads.

**Alternatives considered:** Three near-duplicate Actions, UI-only status changes,
or partial successful replay would complicate correctness.

## Decision 6 — Use append-only AuditEvent rows as operation receipts

**Decision:** Realignment, acknowledgement and discard use their operation UUID plus
closed event type as the idempotency receipt. Audit payloads contain the previous and
new full baseline fingerprints/payloads, selected choice, active/withdrawn action
IDs, impacts, actor and reason. No extra receipt or realignment table is added.

**Rationale:** The established audit uniqueness and event sequence already provide
retry behavior and complete evidence.

**Alternatives considered:** New receipt/history tables duplicate audit facts;
timestamps cannot identify retries.

## Decision 7 — Acknowledge new sources without inventing an economic action

**Decision:** `AcknowledgeProposalSource` operates only on `to_review`. It captures
the current whole-source baseline, preserves and validates any explicitly prepared
typed plan actions, then marks the item aligned. Keeping unchanged reality has no
Proposal Action; excluding permitted plan requires the existing typed Estimate
action before acknowledgement.

**Rationale:** Canonical acknowledgement is not itself an economic change. Actuals
remain read-only and cannot be removed by an acknowledgement flag.

**Alternatives considered:** A generic exclude boolean could suppress unsupported
reality; silently aligning on readiness review removes the required user decision.

## Decision 8 — Replace generic readiness failures with the canonical closed list

**Decision:** `ProposalReadinessReason` enumerates every §12.14 predicate as a stable
code and Italian message. Pure validation maps known validation fields/types to
those codes and retains multiple reasons when multiple predicates fail. Internal
technical failures remain exceptions and do not become a domain inconsistency.

**Rationale:** S6 safely blocked invalid actions but intentionally used a broad
placeholder. S7 owns the complete deterministic resolution workflow and therefore
must expose exact reasons.

**Alternatives considered:** Free-text exceptions and `invalid_action` cannot prove
closed-list coverage; adding new catch-all reasons would violate FR-026.

## Decision 9 — Treat Closed-year differences as read-only divergences

**Decision:** `ProposalImpactPlan` separates writable Open impacts from Closed
historical divergences. Approval locks all rows needed for revalidation, applies only
the Open impacts supported by the action, leaves Closed objects/snapshots untouched,
and appends one divergence event per affected Closed Exercise. The S10 formal
historical-error annotation workflow is not introduced.

**Rationale:** This implements §10.3 and S7's FR-029 ownership while preserving the
roadmap boundary for FR-045.

**Alternatives considered:** Blocking every future operation because history would
differ contradicts §10.3; recalculating history violates immutability; creating S10
annotations early crosses the slice boundary.

## Decision 10 — Assign the next Budget version under the approval lock

**Decision:** Approval locks the Exercise and its Budget headers, verifies the
Proposal's `reference_budget_id` is still the latest version, calculates
`next = latest.version + 1`, requires a non-blank Revision reason, and materializes
one snapshot with `purpose=revision` and `previous_budget_id=latest.id`.

**Rationale:** The existing uniqueness constraint prevents duplicate version numbers;
the lock/recheck provides a clear domain error instead of relying on a database race.

**Alternatives considered:** Reserving version numbers at Draft creation creates
gaps; retrying after a unique-key exception obscures stale Proposal semantics.

## Decision 11 — Copy from Closed sources by reading, never mutating

**Decision:** Canonical copy accepts an active autonomous source Expense from another
Exercise even when that source Exercise is Closed. It revalidates the source revision
and fingerprint, copies only active Estimate facts, creates a new Proposal identity
and lineage, and applies only to the Open destination during approval.

**Rationale:** §7.6.3 and §12.4 do not require the source Exercise to be Open because
copy does not modify it. Invariant 28.55 requires new identity, lineage and no Actuals.

**Alternatives considered:** Requiring an Open source is an unsupported restriction;
sharing identity or mutable rows violates the canonical copy.

## Decision 12 — Keep the UI native and bounded

**Decision:** Exercise view offers `Crea revisione` when a Budget exists. Proposal
view adds three realignment choices, source acknowledgement and discard; approval
labels/version summary adapt to purpose. Action history distinguishes active and
withdrawn decisions. Existing form schemas and capability policies are reused.

**Rationale:** The workflow stays inspectable without a new frontend or generic
editor.

**Alternatives considered:** A visual diff/merge builder and multi-year wizard are
disproportionate and risk implying unsupported field-level behavior.
