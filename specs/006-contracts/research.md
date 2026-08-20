# Research: Contracts

## Decision 1 — Extend the S3/S4 vertical boundaries directly

**Decision:** Add Contract models, pure code under `app/Domain/Contracts`, explicit
mutating Actions, tenant policies, and native Filament Resources. Extend the existing
Exercise, Expense, Line, Project, and Timeline paths wherever Contract ownership can
affect their behavior.

**Rationale:** The repository already provides exact decimals, revision tokens,
ordered locks, idempotent Actions, append-only events, company-scoped policies, and
preview-confirm UI. A parallel Contract architecture would allow existing mutation
paths to bypass Contract rules.

**Alternatives considered:** Generic repositories/services add no current value;
observers obscure declarations and transaction order; a separate frontend or API is
outside the bounded slice.

## Decision 2 — Persist authoritative facts and derive state at date

**Decision:** Store the Contract identity and current renewal projection on
`contracts`; store append-only lifecycle facts and complete renewal configurations in
their own tables. `ContractStateTimeline` derives Planned, Active, Cessated, or
Cancelled for an explicit date from start, lifecycle facts, and the configuration
effective at each expiry. Archive remains a separate timestamp.

**Rationale:** This mirrors the proven Project state pattern while preserving the
Contract-specific distinction between declared final day and following state-change
day. The live renewal projection makes deadline filtering direct, while the complete
configuration/fact history remains authoritative and auditable.

Late census uses the real contractual start and approved renewal anchor to derive
the current state, deadlines, and any elapsed renewals. The creation transaction
records the census and required typed events, generates values only for open
Exercises, and never inserts into or recalculates an approved Budget or Closing
Snapshot.

**Alternatives considered:** A mutable state column would make historical answers
depend on today's row; general event sourcing is prohibited; deriving every deadline
query from unindexed JSON history is unnecessary.

## Decision 3 — Keep renewal configuration rows complete and historical

**Decision:** The initial configuration and every renewal change persist a full row
containing an explicit company-local effective date, automatic-renewal flag, approved
expiry anchor, duration, notice, and author. The initial date is declared during
creation and is not inferred from the technical census timestamp. The Contract's
current renewal fields are an atomic projection of the latest effective configuration
and processed renewals. Previously effective configurations and renewal facts are
never rewritten.

**Rationale:** Each elapsed expiry must use the terms historically effective at that
date. Complete rows avoid ambiguous patch replay and make stale-impact validation
deterministic.

**Alternatives considered:** Updating one configuration row destroys history;
field-level change tables add needless reconstruction complexity.

## Decision 4 — Calculate cycles as pure values, not consumable records

**Decision:** Implement anchored month addition, cycle enumeration, eligibility,
attribution date, annual composition, and effective-change boundary as deterministic
value code. Do not create a cycle ledger. The generated Expense/Line stores the
stable annual result; the current composition is reproducible from conditions and
facts, while Timeline events preserve before/after compositions for mutations.

**Rationale:** Canonical cycles allocate Estimates only; they are not invoice items,
payments, or objects consumed by Actuals. Pure calculation prevents a second economic
reality and supports the complete month-end test matrix.

**Alternatives considered:** Persisting every cycle creates lifecycle and deletion
rules absent from the canon; approximate date libraries or floating arithmetic risk
anchor drift and incorrect cents.

## Decision 5 — Materialize one stable system Estimate Expense per year

**Decision:** Extend Expense with `contract_id` and `origin = manual|system`. A
conditional MySQL uniqueness key permits at most one system Expense per
Contract/Exercise. It contains one stable Estimate Line updated idempotently from the
annual composition. A materialized zero remains; a never-materialized zero creates no
Expense.

**Rationale:** This reuses the canonical Expense/Line reality and existing exact
totals without allowing manual Contract Estimates. A generated marker is required so
all existing mutation paths can reject edit, movement, reversal, or Actual insertion.

**Alternatives considered:** A separate annual-total table would become a competing
economic source; deleting/recreating the Expense would break identity; one Expense
per cycle would violate the unique annual Estimate rule.

## Decision 6 — Extend Expense ownership with explicit Contract XOR

**Decision:** Add nullable `contract_id` beside `project_id` and enforce that both
cannot coexist. Associated Expenses have no direct Cost Center. Contract Expenses
materialize the Contract Supplier in the existing `supplier_id`; entering a Contract
replaces a different direct Supplier after warning, and leaving may retain it.
Manual Contract Expenses contain only Actual Lines.

**Rationale:** Explicit nullable foreign keys preserve database-level company
integrity and stable Expense/Line identities. Materializing the derived Supplier
keeps existing list/report queries direct and makes the allowed retain-on-exit rule
natural; any permitted pre-use Contract Supplier change updates its zero-only child
projection atomically.

**Alternatives considered:** A polymorphic owner would weaken composite foreign keys;
copying annual Cost Center to each Expense would create divergence; separate Contract
Expense models would duplicate all Line actions.

## Decision 7 — Use immutable multi-Exercise impact plans

**Decision:** Condition, lifecycle, renewal, classification, Supplier, and ownership
operations that can affect several open Exercises first build an immutable impact
plan. Confirmation reauthorizes, locks Company, all affected Exercises and sources in
stable order, verifies revisions and fingerprint, recomputes the plan, and commits
all changes and events together.

**Rationale:** This extends `ExpenseImpactPlan` and
`ProjectClassificationImpactPlan` to satisfy the first complete FR-094 case. It also
provides the exact requested/minimum/effective-date and annual-impact confirmation
required for economic changes.

**Alternatives considered:** Per-year saves permit partial state; optimistic UI-only
checks are bypassable; one large generic impact engine would over-generalize unlike
operations.

## Decision 8 — Materialize due renewals with a scheduled command, without a queue

**Decision:** Create one idempotent Action for a Contract and an Artisan command
`contracts:process-renewals` scheduled through Laravel. Each Contract is processed in
its own transaction; missed expiries are ordered chronologically and receive one
fact/event each. Reads compute projected state and next expiry deterministically, so
correct display does not depend on a page causing mutation.

**Rationale:** The canon leaves scheduling technical but forbids dependence on the
deadlines page. The existing application has no queue/Redis need. A synchronous
scheduled command is the smallest independent invocation mechanism and is directly
testable and runnable in the quickstart.

**Alternatives considered:** Page-triggered processing violates the independence
rule; permanent workers/queues are unjustified; future renewals must not be persisted
before their expiry.

## Decision 9 — Extend annual classification and Exercise creation symmetrically

**Decision:** Add one nullable Contract classification per Exercise and reuse the
Project classification semantics. `CreateExercise` seeds the latest known Contract
classification under lock without creating Expenses or values. Reclassification
uses a Contract-specific annual impact plan and affects the generated allocation and
all Contract Actuals for that Exercise together.

**Rationale:** The canonical classification is annual and inherited. Reusing the
existing pattern completes the first-level source boundary without copying the Cost
Center into child Expenses.

**Alternatives considered:** Monthly classifications, percentages, or copied child
values are canonically excluded.

## Decision 10 — Implement only a dedicated Project-Contract link

**Decision:** Persist `Collegato a` in a dedicated `project_contract_links` table
with normalized Project and Contract endpoints, one company, active uniqueness,
Archive/restore, note, revision, and audit. Do not add a generic relation union or any
`Sostituisce` input in this plan.

**Rationale:** The Project-Contract link is deterministic and useful now. A dedicated
table retains composite same-company foreign keys and is smaller than a generic
source relation. Directed replacement remains blocked by the explicit category-E
ownership-movement gap.

**Alternatives considered:** Guessing a movement rule violates domain authority;
building a generic relation table now would implement the blocked future case; silently
dropping FR-095 status would misstate coverage.

## Decision 11 — Use native private storage for immutable attachment versions

**Decision:** Add a company-scoped Attachment record with exactly one Contract,
Expense, or Line owner. Store blobs under unique immutable keys on the existing
private `local` disk, with original name, media type, size, checksum, uploader, and
optional detachment metadata. Replacement creates a new record/blob. Download goes
through an authenticated tenant-authorized controller; detachment never deletes the
blob.

**Rationale:** Laravel filesystem and Filament upload components already satisfy the
bounded need without a media plugin. Immutable keys and logical detachment let later
approvals/Snapshots retain an Attachment ID without storage redesign.

**Alternatives considered:** Public URLs disclose tenant evidence; overwriting a
path defeats versioning; a media package has no demonstrated benefit; generic object
storage infrastructure is premature.

## Decision 12 — Allow several ordered audit events for one operation

**Decision:** Add `event_sequence` with default zero to `audit_events` in a new
forward migration and replace unique `operation_id` with unique
`(operation_id, event_sequence)`. S0-S4 Actions continue using sequence zero. S5 uses
deterministic sequences when one confirmed command requires several typed events or
several elapsed renewals.

**Rationale:** The canon requires distinct events for every renewal and the S5 spec
allows multiple required typed events per command. The current global unique
operation ID cannot represent that. A deterministic composite keeps the operation as
the retry receipt and preserves existing behavior.

**Alternatives considered:** Unrelated operation IDs lose common causality;
collapsing required events hides facts; editing the applied migration is prohibited.

## Decision 13 — Native Filament Resource plus a read-only deadline page

**Decision:** Add `ContractResource` with bounded related views for annual situations,
conditions, lifecycle, renewal history, Expenses, links, attachments, and Timeline.
Add a separate `Scadenze contratti` Filament page with canonical fields and filters.
Extend existing Expense/Line/Project pages rather than creating duplicate editors.

**Rationale:** This follows S4's inspectable pattern. Consequential mutations receive
preview-confirm Actions; descriptive edits remain simple. Deadline display stays
informational and never initiates renewal processing or promises reminders.

**Alternatives considered:** Generic CRUD cannot express impact confirmation;
Contract-specific copies of Expense/Line UI would diverge; Dusk is unnecessary for
journeys covered by Action and Livewire tests.
