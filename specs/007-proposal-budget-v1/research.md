# Research: Initial Proposal and Budget v1

## Decision 1 — Extend the existing vertical boundaries directly

**Decision:** Add Proposal/Budget models, deterministic Proposal code, explicit
Actions, tenant policies and native Filament Resources. Reuse existing Exercise and
source revisions, state-at-date functions, exact decimals, audit events, attachment
storage and company capability checks.

**Rationale:** S3–S5 already provide the authoritative live reality and the technical
primitives needed by S6. A parallel architecture would risk duplicate economics and
bypass existing source rules.

**Alternatives considered:** Generic repositories/services, CQRS, event sourcing,
observers, a separate API/frontend and new packages add no current value.

## Decision 2 — Store closed source-specific action envelopes

**Decision:** Persist ordered Proposal Actions with an enum `action_type` and a JSON
payload validated by a dedicated source/action DTO before save and again before
approval. Allowed types are explicitly enumerated for Expense, Project, Contract,
and Project–Contract link decisions. Unknown fields and action types are rejected.

**Rationale:** JSON keeps the bounded action payload proportional while the closed
enum plus strict validators prevents a generic database patch. It also lets Budget
v1 materialize the exact approved decision without inventing a broad service layer.

**Alternatives considered:** One nullable column per possible action would create a
wide sparse schema; a generic patch violates FR-011; serializing PHP objects is not a
stable historical contract.

## Decision 3 — Capture immutable baselines and deterministic fingerprints

**Decision:** Each existing-source Item stores the source revision, an immutable
canonical baseline payload and its SHA-256 fingerprint. Readiness rebuilds the whole
source payload and compares both revision and fingerprint. Proposal membership is
re-enumerated on review and again under approval locks.

**Rationale:** The canon requires whole-source invalidation and an equivalent
monotone mechanism. Fingerprints cover relevant relations and facts even if a prior
slice conservatively missed a parent revision increment; membership comparison
detects newly qualifying sources.

**Alternatives considered:** Per-field merge is prohibited; timestamps are weak
tokens; trusting only the displayed preview allows stale approval.

## Decision 4 — Materialize read-only Actual context separately

**Decision:** Baselines and readiness payloads contain a clearly separated
`actual_context` used only for display/fingerprinting. Action result and Budget
payload validators reject Actual Lines, variance, residual, savings, overspend,
late-correction, Forecast and Closing keys recursively.

**Rationale:** Actual changes must invalidate the Item while Actual values must never
become editable plan actions or Budget baseline data.

**Alternatives considered:** Omitting Actuals would make review unsafe; copying them
into result payloads violates Proposal isolation and Budget schema.

## Decision 5 — Use one source Item for each first-level source

**Decision:** `proposal_items` has explicit nullable Expense, Project and Contract
foreign keys with a database shape check. A new Item has no live source ID and a
stable UUID ProposalItemID. Copy lineage is an explicit immutable OriginKey string;
approved copied Expenses also receive an additive `copied_from_origin_key` column.

**Rationale:** Explicit foreign keys preserve tenant integrity and match the three
canonical source types. UUID Proposal identities allow new Items to reference each
other before live IDs exist without reserving live identities.

**Alternatives considered:** Polymorphic IDs weaken relational constraints; early
live rows violate invariant 28.21; fuzzy identity is prohibited.

## Decision 6 — Make readiness an explicit deterministic result

**Decision:** A readiness evaluator returns Item status, exact closed-list block
codes/messages, warnings, affected Exercise revisions and exact before/after
allocation/state impacts. New qualifying sources are inserted once as
`Da prendere in visione`; changed baselines become `Da riallineare`; invalid typed
results become `Incoerente`. S6 exposes but does not resolve these states.

**Rationale:** Approval needs authoritative preconditions even though S7 owns the
resolution workflows. Closed block codes keep user messages testable and prevent a
generic inconsistency bucket.

**Alternatives considered:** UI-only validation is stale; silently dropping
unsupported actions would create partial behavior.

## Decision 7 — Apply approval in one ordered transaction

**Decision:** Approval locks Company, Proposal, main and affected Exercises by ID,
source rows by type and ID, source child facts/Lines, Proposal Items/Actions, and
related master data in a deterministic order. It reauthorizes, re-enumerates,
rebuilds readiness, applies all typed plan actions, resolves ProposalItem references,
materializes Budget and evidence, appends audit events and marks the Proposal
approved inside one database transaction.

**Rationale:** This directly satisfies canonical all-or-nothing approval and prevents
preview/confirmation races. Existing source Actions are not called as independent
commands because their authorization and transaction boundaries differ from Budget
approval; their domain value objects and validation rules are reused.

**Alternatives considered:** Per-Item transactions permit partial economics;
distributed locks or queues are unnecessary for one MySQL application.

## Decision 8 — Use approval operation identity as the retry receipt

**Decision:** Store a unique approval operation UUID on the Proposal and Budget.
Audit rows use deterministic event sequences for that operation. A retry for the
same Proposal and UUID returns the existing Budget; a reused UUID for another
operation is rejected.

**Rationale:** This extends the established `(operation_id, event_sequence)` pattern
and guarantees one Budget v1, one live object set and one event set.

**Alternatives considered:** Request timestamps or random IDs on retry cannot prove
identity; a new command bus/receipt table is unnecessary.

## Decision 9 — Use autonomous materialized Budget rows with strict JSON details

**Decision:** Persist a Budget header and one immutable first-level row per effective
Item. Scalar identity, label, classification, supplier, allocation and state fields
are first-class columns; source-specific Estimate Lines, transitions, conditions,
cycles, events, actions and relations use a strictly validated versioned JSON detail
contract copied at approval. The header total uses the canonical Exercise formula
(autonomous Expenses + Projects + Contracts), so a materialized child Expense row
does not double-count its parent aggregate.

**Rationale:** The snapshot stays independently readable and queryable without
duplicating many sparse source-type tables. Header totals are computed from the rows
inside the transaction with the canonical ownership categories and rechecked by a
snapshot consistency rule.

**Alternatives considered:** Resolving live models on read violates autonomy;
serializing arbitrary models is unstable; three parallel row tables duplicate the
common immutable envelope.

## Decision 10 — Retain approval evidence independently

**Decision:** Extend the existing private Attachment ownership contract with an
optional Proposal owner, preserving the exactly-one-owner check. A manager may upload
new evidence to the Draft or select an eligible same-company attachment. Budget
Evidence materializes optional external approval subject/venue and zero or more
attachment facts: original Attachment ID, immutable disk/path, name, media type,
size and checksum. Existing blobs are never deleted on detach; Budget download
authorization uses the Budget company rather than live ownership.

**Rationale:** Later detachment or live-object changes cannot remove or rewrite the
approved evidence. No signature or maker-checker mechanism is introduced.

**Alternatives considered:** A live join alone is not autonomous; copying blobs is
unnecessary because existing paths are immutable and never deleted; storing a file
only inside the approval transaction cannot provide rollback semantics across DB and
filesystem, whereas a versioned Draft attachment is already a valid retained fact.

## Decision 11 — Native Filament Resources and explicit planning actions

**Decision:** Add `Proposta` and read-only `Budget` Resources. Exercise view exposes
`Inizializza proposta`; Proposal view exposes source-specific actions, readiness and
approval confirmation. Item and Budget detail remain drillable. All mutations call
explicit Actions and rotate the UI operation UUID only after success.

**Rationale:** This follows verified project conventions and keeps the vertical
journey inspectable without Dusk-only coverage or a frontend framework.

**Alternatives considered:** Generic CRUD exposes unsafe fields; a custom SPA or
plugin is disproportionate.

## Decision 12 — Keep S7+ behavior visibly unavailable

**Decision:** S6 detects stale/new/inconsistent sources but offers no reload/keep/
manual realignment; creates only v1; exposes no carryover, reprogramming, Closing,
late correction, full comparison/export, Forecast, structured source replacement, alternate Draft,
or arbitrary as-of controls.

**Rationale:** The S7+ exclusions are explicit roadmap boundaries, not missing S6
implementations; structured source replacement is instead permanently absent under
canonical §32. Rejected requests receive a domain message rather than a partial record.

**Alternatives considered:** Placeholder actions or inactive fields would falsely
suggest supported behavior and invite early implementation.
