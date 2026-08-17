# Research: Exercises, Expenses and Lines

## Decision 1 — Exact decimal arithmetic

**Decision:** Declare the already installed `ext-bcmath` runtime requirement and use
focused string-based BCMath helpers for economic sums, variance, comparison, and the
quantity-by-unit suggestion with half-up rounding to two decimals. Fixed-precision
database decimals remain strings at the persistence boundary.

**Rationale:** PHP floating point cannot guarantee canonical decimal behavior.
Integer cents do not proportionally cover two optional six-decimal factors. BCMath is
bundled in the current PHP 8.3 Sail runtime, maintained with PHP, and covered by the
PHP license; declaring it makes the runtime need reproducible without a package.

**Alternatives considered:** Floats were rejected as non-authoritative; handwritten
arbitrary-precision multiplication was needless risk; database-only arithmetic
cannot support validation and UI suggestions. Exit: replace the focused calculator
with another exact-decimal implementation without changing stored values.

## Decision 2 — Forward schema and company integrity

**Decision:** Add `exercises`, `expenses`, and `expense_lines`. A new forward
migration adds redundant composite reference keys to Supplier and Cost Center so
Expense can use composite same-company foreign keys. All deletes are restrictive.

**Rationale:** Action and policy checks remain mandatory, while relational constraints
also reject crafted or accidental cross-company references. Applied S2 migration
files remain unchanged.

**Alternatives considered:** Application-only checks were weaker; global scopes can
hide rather than prevent invalid data; rewriting S2 migrations is prohibited.

## Decision 3 — Autonomous-only schema boundary

**Decision:** Do not create placeholder Project/Contract columns, polymorphic owners,
system-origin fields, or copy-lineage fields. S3 has no container input and every S3
Expense is autonomous/manual by construction. Future slices add real foreign keys
and the complete XOR check when their tables and workflows exist.

**Rationale:** Placeholder identities permit dangling references and anticipate S4/S5.
S3 can authoritatively test autonomous first-level behavior and reject unknown
container input, but full cross-container moves become demonstrable only as the
container slices extend this aggregate.

**Alternatives considered:** Nullable future IDs without foreign keys weaken
integrity; a polymorphic owner prevents normal foreign keys; early container tables
cross the slice boundary.

## Decision 4 — Derived totals and monotone revisions

**Decision:** Derive allocation, actual, variance and Actual presence from active
Lines. Do not cache totals. Add monotone revisions to Exercise and Expense; increment
them conservatively on affected economic mutations and revalidate the preview's
revision before confirmation.

**Rationale:** Derived values cannot drift. Revisions make current S3 impact previews
safe under concurrency and also satisfy the canonical source-revision mechanism
without implementing Proposals.

**Alternatives considered:** Stored counters need rebuild machinery; timestamps can
miss or ambiguously order fast changes; lazy per-row queries create N+1 behavior.

## Decision 5 — One command, one audit event

**Decision:** Keep unique `audit_events.operation_id`. Each Action writes one complete
typed event. Expense creation materializes its initial Lines and total impact in one
`expense_created` event; later Line commands have their own events and IDs.

**Rationale:** Retry semantics retain one authoritative outcome without a command
table. This prevents a transient persisted Expense without Lines.

**Alternatives considered:** Multiple events per operation conflict with the existing
unique index; a receipt table duplicates current infrastructure; independent initial
Line commands permit partial creation.

## Decision 6 — Deterministic locks and atomicity

**Decision:** Lock Company first, then affected Exercises in ID order, Expense, Lines
in ID order, and referenced master data. Re-authorize and revalidate under the locks.
Move and event persistence share one transaction.

**Rationale:** Company locking synchronizes with capability mutations. Stable order
protects the two-year move and limits deadlock risk.

**Alternatives considered:** UI-only checks are stale under concurrency; broad table
locks are disproportionate.

## Decision 7 — Native Resources and explicit impact actions

**Decision:** Exercise gets list/create/view only. Expense gets list/create/view and
descriptive edit; initial Lines use a required create repeater, later Lines a relation
manager. Exercise/Supplier/Cost Center changes use a separate preview-confirm action.
Storno and restore are explicit confirmed actions.

**Rationale:** This follows existing tenant Resource patterns and keeps consequential
operations separate from descriptive editing.

**Alternatives considered:** Generic CRUD can bypass impact display; nested custom
routing is unnecessary; a custom frontend or service layer adds complexity.

## Decision 8 — Explicit amount-warning acknowledgement

**Decision:** If both descriptive factors are present and their rounded product
differs from authoritative amount, show both values and require acknowledgement.
Changing input invalidates the acknowledgement.

**Rationale:** The amount remains authoritative while the pre-save warning is
observable and testable.

**Alternatives considered:** Auto-replacement violates authority; rejecting every
difference turns a warning into an invented invariant.

## Decision 9 — Attachments deferred

**Decision:** S3 exposes no upload/removal controls. Notes and Timeline cover S3
explanations; attachment storage begins with the first bounded slice that requires
uploaded evidence.

**Rationale:** Empty attachment collections are valid. A partial UI would invent
removal/versioning behavior and a media plugin has no demonstrated S3 need.

## Decision 10 — Traceability is incremental, not a domain gap

**Decision:** S3 establishes the autonomous side of FR-005/FR-051/FR-052 and invariant
28.4. S4 and S5 extend the same primary anchor with Project/Contract cases. These rows
must not be declared fully verified before those container cases exist.

**Rationale:** The canonical domain is complete, but S3 is intentionally forbidden
from introducing the future containers. This is a roadmap verification boundary, not
a category-E structural gap.

**Alternatives considered:** Placeholder or premature container implementation is
prohibited; reopening the canonical domain is unjustified.
