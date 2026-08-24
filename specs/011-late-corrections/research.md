# Research: Correzioni post-Chiusura

## Decision 1 — Represent each late correction explicitly

**Decision:** Add an immutable `LateCorrection` record that belongs to the Closed
Exercise and Closing Snapshot and points one-to-one to the newly appended Actual
ExpenseLine. It stores the operation identity, actor, reason, declaration, optional
original-line reference and materialized historical context needed to explain the
write.

**Rationale:** An ordinary ExpenseLine alone cannot distinguish a post-Closing
correction from an ordinary live Actual or carry the canonical declaration and
historical evidence required by §24.7. The explicit record also gives S11 a stable
source without changing the Closing Snapshot.

**Alternatives considered:** Adding nullable correction fields directly to every
ExpenseLine would mix ordinary and exceptional writes; deriving corrections only
from AuditEvent would make current state depend on event replay, prohibited by §22.10.

## Decision 2 — Reuse Expense and ExpenseLine as the economic state

**Decision:** `RecordLateCorrection` appends exactly one Actual line. It either uses
an explicitly selected compatible historical manual Expense or creates one manual
Expense in the same historical owner context. It never calls the ordinary
`CreateExpenseLine` path because that path correctly rejects Closed Exercises and
uses ordinary-operation authorization.

**Rationale:** Canonical §24.5 defines late corrections as real Expense/ExpenseLine
facts, not a parallel ledger. A dedicated Action preserves the exceptional Closed-year
boundary without weakening ordinary mutation rules.

**Alternatives considered:** A separate correction amount ledger would require every
aggregate to merge two economic stores; allowing the ordinary Action to bypass its
Open check would create an unsafe compatibility path.

## Decision 3 — Make compatibility a small closed deterministic rule

**Decision:** `HistoricalExpenseCompatibility` accepts only a selected Expense that
is manual, belongs to the exact Closed Exercise and same first-level source, is not
reversed, accepts Actuals and retains the historical Supplier context. An incompatible
or absent selection causes creation of a new manual Expense in the explicitly supplied
same historical owner context; it is never matched automatically.

**Rationale:** This maps §24.6 directly and keeps selection user-declared. It avoids
fuzzy matching and avoids reclassifying the original Expense.

**Alternatives considered:** Searching by description, amount or Supplier would be
forbidden matching; silently choosing one among several compatible Expenses would
invent identity.

## Decision 4 — Use a closed annotation kind and versioned materialized facts

**Decision:** Add immutable `HistoricalErrorAnnotation` rows with a closed
`HistoricalErrorKind` vocabulary: `cost_center`, `supplier`, `project`, `contract`,
`container`, `exercise`, `historical_state`, `carryover`, and
`accidental_closing`. Store recorded and believed-correct facts as versioned JSON,
plus a materialized list of affected source references and the required Closing
Snapshot.

**Rationale:** The canonical error classes are closed and have different shapes.
Versioned JSON preserves IDs and labels without a generic polymorphic mutation engine,
while the closed kind prevents undefined annotation categories.

**Alternatives considered:** One table per error kind is disproportionate; free-form
kind/payload values would permit invented behavior; mutating foreign keys would imply
historical reclassification.

## Decision 5 — Treat annotations as non-economic first-class records

**Decision:** Annotation creation writes only the immutable annotation and one typed
AuditEvent. It never writes Expenses, classifications, state, Carryover, Budget or
Closing rows. Any later Open-year plan/state operation remains a separate existing
canonical workflow.

**Rationale:** §§14.9 and 24.10 deliberately separate acknowledging historical error
from changing current or future live state.

**Alternatives considered:** Automatic compensating transfers, reopened history or
future-plan side effects all cross explicit MUST NOT boundaries.

## Decision 6 — Reuse attachments without a second storage system

**Decision:** Correction evidence attaches to the generated ExpenseLine through the
existing Attachment path. Extend owner-aware attachment authorization so
`corregge_esercizio_chiuso` can upload only to a generated LateCorrection line, and
extend the owner shape for `HistoricalErrorAnnotation`. Evidence on either immutable
owner cannot be detached; ordinary live-owner detachment remains unchanged. The
correction/annotation remains valid without an attachment.

**Rationale:** The repository already has immutable file identity, hashes and tenant
ownership. Owner-aware authorization reuses that storage without granting ordinary
Manage Operations powers in a Closed Exercise or permitting retained evidence to be
removed.

**Alternatives considered:** Storing binary or metadata JSON inside correction rows
duplicates Attachment; requiring an upload in the same domain transaction adds a
staging workflow not required by the canonical domain.

## Decision 7 — Use existing operation receipts and ordered locks

**Decision:** Each Action accepts an operation UUID. Under Company and Exercise locks,
it returns the existing same-operation result on retry, then locks the Closing
Snapshot, selected source/Expense/Line and referenced master data before revalidating
and writing one transaction.

**Rationale:** This follows established MP2 mutation patterns, prevents duplicate
append-only facts after a lost response and preserves deterministic tenant checks.

**Alternatives considered:** Timestamp-based duplicate detection is ambiguous;
retrying after a unique-key failure obscures the successful result; broad unrelated
locks are unnecessary.

## Decision 8 — Keep S10 presentation local and defer reporting

**Decision:** Add `Registra correzione tardiva` and `Annota errore storico` to the
Closed Exercise context. Show immutable record details in Exercise/Closing infolists.
Do not add aggregate comparison pages, reporting categories, filters, exports or
cross-Exercise analysis.

**Rationale:** This makes S10 independently demonstrable while preserving roadmap S11
ownership of FR-043, FR-096 and complete reporting/export semantics.

**Alternatives considered:** A correction report Resource would implement S11 early;
hiding records until S11 would make S10 uninspectable.