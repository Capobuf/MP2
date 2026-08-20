# Research: Projects

## Decision 1 — State is calculated from immutable dated facts

**Decision:** Persist one initial state/date on Project and append-only dated
transitions. Derive `Absent at date`, Planned, Open, Closed, or Cancelled by applying
non-annulled transitions in date order. Derive a transition's display status from
`annulled_at` and the company-local current date; do not persist a clock-driven
Planned/Effective flag.

**Rationale:** This is the canonical `StateAtDate` rule and avoids status drift or a
scheduler becoming authoritative. The transition plus its effective date is the
authoritative typed fact; Timeline records scheduling/recording, annulment, and
replacement without replaying unrelated application history.

**Alternatives considered:** A mutable current-state column can contradict history;
daily status jobs create avoidable operational state; full event sourcing is
explicitly excluded.

## Decision 2 — Validate the complete transition sequence

**Decision:** Each create, annul, or replace operation locks the Project and its
transitions, applies the requested change in memory, then validates every active
transition from the initial date forward. A generated nullable active-date column
with a unique key prevents two non-annulled transitions on one date under
concurrency. Closure, cancellation, and reopening require a reason.

**Rationale:** A locally valid transition can invalidate a later planned transition.
Whole-sequence validation plus a database uniqueness guard enforces both temporal
and concurrent correctness.

**Alternatives considered:** Validating only neighboring rows misses downstream
incompatibility; application-only date uniqueness is race-prone; editing an effective
transition destroys history.

## Decision 3 — Project economics remain derived from child Lines

**Decision:** For one Project and Exercise, derive allocation from active Estimate
Lines and Actual from active Actual Lines in non-reversed child Expenses. S4
carryover is unavailable and therefore contributes no stored placeholder. Exercise
totals continue summing each active Line once, while first-level UI grouping treats
Project-owned Expenses only as Project children.

**Rationale:** Authoritative Lines already provide exact decimal behavior and cannot
drift from cached Project totals. Autonomous and Project-owned Expenses are disjoint,
so the set-based Exercise sum remains numerically exact without double counting.

**Alternatives considered:** Cached Project totals require repair machinery;
duplicating inherited amounts on Project creates a second source of truth; adding a
zero carryover field crosses the S8 boundary.

## Decision 4 — Annual classification is one nullable relation

**Decision:** Store at most one nullable Cost Center classification for each
Project/Exercise. Project-owned Expenses never copy a direct Cost Center. Creating a
new Exercise seeds every existing Project from its latest known classification,
including an archived historical identity; `null` is seeded only when no prior
classification exists.

**Rationale:** The canonical initialization rule says latest known classification,
while the archive rule forbids selecting an archived Cost Center for a *new manual
classification*. Deterministic automatic carry-forward is not a user selection and
preserves continuity; the archived reference stays visible and can be replaced by
an active Cost Center or Unclassified.

**Alternatives considered:** Copying classification to every Expense violates
inheritance; silently replacing an archived latest identity with Unclassified loses
the required latest-known value; creating monthly ranges invents unsupported
semantics.

## Decision 5 — Extend Expense ownership with a concrete foreign key

**Decision:** Add nullable `expenses.project_id`. `null` means autonomous; non-null
means Project-owned and requires `direct_cost_center_id` to be null. Use a
same-company composite foreign key and reject all Contract ownership input.

**Rationale:** S4 has one real container and normal relational integrity is simpler
and stronger than a polymorphic or placeholder owner. Supplier remains an Expense
property.

**Alternatives considered:** An owner type/id pair weakens foreign keys; a premature
Contract column crosses S5; copying Expenses or Lines would violate stable identity.

## Decision 6 — Revisions and one deterministic lock order

**Decision:** Keep Exercise and Expense revisions and add Project revision. Every
Project descriptive/state/classification/archive mutation increments Project;
economic child mutations increment the owning Project and affected Exercise;
ownership moves increment old/new Projects, Expense, and affected Exercises. Lock
Company, Exercises by ID, Projects by ID, transition/classification rows by stable
key, Expenses by ID, Lines by ID, then referenced master data by ID.

**Rationale:** Preview fingerprints need all aggregates that can alter the outcome.
The shared lock order extends S3 without deadlock-prone inversions and supports full
revalidation after authorization.

**Alternatives considered:** Timestamps are ambiguous revision tokens; locking only
the edited row misses Project variance and state changes; table locks are excessive.

## Decision 7 — Overspend is a reusable before/after predicate

**Decision:** A pure Project rule compares exact annual variance before and after.
It returns created only for `before <= 0` and `after > 0`, increased only when both
are positive and `after > before`, otherwise none. Every Line, Expense,
classification, and ownership mutation that can affect a Project applies it inside
the transaction and requires a note when the company setting is enabled.

**Rationale:** Overspend can be caused by either side of the variance and is not an
Actual-only rule. Centralizing the predicate makes notification, audit payload, and
rejection consistent.

**Alternatives considered:** Checking only Actual insertion misses Estimate
annulment, restore, reversal, and movement; checking only the final sign creates
duplicate warnings.

## Decision 8 — One complete event is the idempotency receipt

**Decision:** Retain the existing unique `audit_events.operation_id` and write one
complete typed event for each real command. When the command creates or increases
overspend, its Project reference and exact before/after overspend classification are
included in that same event payload and shown as an overspend Timeline occurrence.
A transition replacement is one event containing both annulled and replacement IDs.

**Rationale:** The canonical event envelope permits complete before/after values,
reason, references, and impacts. One causal event can explain both the mutation and
its overspend consequence while preserving the established retry receipt. Separate
synthetic command IDs would weaken causality and require unnecessary schema changes.

**Alternatives considered:** Multiple rows sharing an operation ID would require a
new receipt mechanism and migration across every existing Action; unrelated UUIDs
would make one atomic operation appear as two commands.

## Decision 9 — Native Filament Resource with reused Expense UI

**Decision:** Add one `ProjectResource` with annual situations, transition manager,
and Project Expense manager. Extend `ExpenseResource` to show and change the real
container. Use explicit preview-confirm actions for annual classification and
whole-Expense movement; retain the existing Line manager.

**Rationale:** This makes the new first-level source inspectable without duplicating
economic editing or introducing a frontend framework. Consequential operations stay
separate from descriptive edits.

**Alternatives considered:** A second Project-specific Expense editor would diverge;
generic CRUD cannot express sequence and impact confirmation; custom frontend work
has no S4 need.

## Decision 10 — S4 extends S3 operations instead of bypassing them

**Decision:** Extend Create/Update/annul/restore Line, Expense reverse/restore,
Expense creation/move, and Exercise creation Actions with Project locks, state,
classification, overspend, and revision checks. No alternate Project-only mutation
path is allowed.

**Rationale:** Every path capable of changing a Project aggregate must enforce the
same rules. Reusing the current aggregate Actions preserves S3 validation,
idempotency, and rollback behavior.

**Alternatives considered:** UI-only enforcement is bypassable; observers obscure
transaction ordering and cannot collect the required user declarations; duplicate
Actions invite inconsistent behavior.
